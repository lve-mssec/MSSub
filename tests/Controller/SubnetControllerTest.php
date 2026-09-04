<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\IpAddress;
use App\Entity\Organization;
use App\Entity\Subnet;
use App\Entity\User;
use App\Enum\AuthSource;
use App\Enum\IpStatus;
use App\Enum\IpVersion;
use App\Enum\SubnetStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'arborescence des reseaux.
 *
 * Le pliage est assure par un script qui s'appuie sur la structure rendue :
 * data-id, data-parent, data-depth, et un bouton porteur d'aria-expanded. Ces
 * attributs ne sont pas decoratifs — les retirer casserait le repli sans qu'un
 * test de rendu classique s'en apercoive. D'ou ces assertions explicites.
 */
final class SubnetControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testTreeRowsCarryTheHierarchyTheScriptNeeds(): void
    {
        [$container, $child] = $this->givenPlan();

        $crawler = $this->client->request('GET', '/reseaux');
        self::assertResponseIsSuccessful();

        $containerRow = $crawler->filter('table[data-tree] tbody tr[data-id="'.$container->getId().'"]');
        $childRow = $crawler->filter('table[data-tree] tbody tr[data-id="'.$child->getId().'"]');

        self::assertCount(1, $containerRow);
        self::assertCount(1, $childRow);

        self::assertSame('0', $containerRow->attr('data-depth'));
        self::assertSame('1', $childRow->attr('data-depth'));
        self::assertSame((string) $container->getId(), $childRow->attr('data-parent'));
        self::assertSame('', (string) $containerRow->attr('data-parent'));
    }

    public function testOnlyBranchesGetAToggle(): void
    {
        [$container, $child] = $this->givenPlan();

        $crawler = $this->client->request('GET', '/reseaux');

        $toggle = $crawler->filter('tr[data-id="'.$container->getId().'"] button.tree-toggle');
        self::assertCount(1, $toggle, 'Un bloc avec des enfants doit porter un bouton de pliage.');
        self::assertSame('true', $toggle->attr('aria-expanded'));

        self::assertCount(
            0,
            $crawler->filter('tr[data-id="'.$child->getId().'"] button.tree-toggle'),
            'Une feuille ne doit pas proposer de pliage.',
        );
        self::assertCount(1, $crawler->filter('tr[data-id="'.$child->getId().'"] .tree-toggle--leaf'));
    }

    /** L'occupation est calculee pour les reseaux terminaux, pas pour les conteneurs. */
    public function testUsageIsShownForLeavesOnly(): void
    {
        [$container, $child] = $this->givenPlan();

        $crawler = $this->client->request('GET', '/reseaux');

        self::assertStringContainsString(
            '2/254',
            $crawler->filter('tr[data-id="'.$child->getId().'"] .usage__text')->text(),
        );
        self::assertCount(0, $crawler->filter('tr[data-id="'.$container->getId().'"] .usage__text'));
    }

    /** @return array{0: Subnet, 1: Subnet} */
    private function givenPlan(): array
    {
        $user = (new User())
            ->setUsername('operateur.test')
            ->setAuthSource(AuthSource::Local)
            ->setRoles([User::ROLE_ADMIN]);
        $user->setPassword('peu-importe');
        $this->em->persist($user);

        $organization = (new Organization())->setCode('TREE')->setName('Organisation arbre');
        $this->em->persist($organization);

        $container = (new Subnet())
            ->setOrganization($organization)
            ->setVersion(IpVersion::V4)
            ->setNetworkAddress('10.90.0.0')
            ->setLastAddress('10.90.255.255')
            ->setPrefixLength(16)
            ->setName('Bloc de test')
            ->setStatus(SubnetStatus::Container);
        $this->em->persist($container);

        $child = (new Subnet())
            ->setOrganization($organization)
            ->setParent($container)
            ->setVersion(IpVersion::V4)
            ->setNetworkAddress('10.90.10.0')
            ->setLastAddress('10.90.10.255')
            ->setPrefixLength(24)
            ->setName('LAN de test')
            ->setStatus(SubnetStatus::Active);
        $this->em->persist($child);

        foreach (['10.90.10.1' => IpStatus::Gateway, '10.90.10.2' => IpStatus::Used, '10.90.10.3' => IpStatus::Free] as $ip => $status) {
            $this->em->persist((new IpAddress())->setSubnet($child)->setAddress($ip)->setStatus($status));
        }

        $this->em->flush();
        $this->client->loginUser($user);

        return [$container, $child];
    }
}
