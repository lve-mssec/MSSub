<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Entity\User;
use App\Enum\AuthSource;
use App\Enum\IpVersion;
use App\Enum\SubnetStatus;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'ecriture du referentiel, vue depuis le formulaire.
 *
 * Le point sensible est que le CIDR saisi n'alimente pas directement l'entite :
 * il passe par le moteur d'allocation, qui en derive les bornes et designe le
 * bloc parent. Ces tests verifient ce chainage de bout en bout, refus compris.
 */
final class SubnetWriteTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user = (new User())
            ->setUsername('ecrivain.test')
            ->setAuthSource(AuthSource::Local)
            ->setRoles([User::ROLE_ADMIN]);
        $user->setPassword('peu-importe');
        $this->em->persist($user);

        $this->organization = (new Organization())->setCode('WRITE')->setName('Organisation écriture');
        $this->em->persist($this->organization);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testCreatingASubnetDerivesItsBoundsFromTheCidr(): void
    {
        $this->submitCreation('10.80.12.0/26', 'DMZ de test');

        $subnet = $this->find('10.80.12.0', 26);

        self::assertNotNull($subnet);
        self::assertSame('DMZ de test', $subnet->getName());
        // Les bornes ne sont pas saisies : elles sont calculees.
        self::assertSame('10.80.12.63', $subnet->getLastAddress());
        self::assertSame(IpVersion::V4, $subnet->getVersion());
    }

    /** Une adresse d'hote saisie a la place du reseau doit etre ramenee au reseau. */
    public function testHostAddressIsNormalisedToItsNetwork(): void
    {
        $this->submitCreation('10.80.12.37/26', 'Saisie approximative');

        self::assertNotNull($this->find('10.80.12.0', 26));
    }

    public function testParentIsAssignedAutomatically(): void
    {
        $container = $this->givenContainer('10.80.0.0', 16);

        $this->submitCreation('10.80.30.0/24', 'Sous-réseau');

        $created = $this->find('10.80.30.0', 24);
        self::assertSame($container->getId(), $created?->getParent()?->getId());
    }

    public function testDuplicateIsRefusedWithAReadableMessage(): void
    {
        $this->givenContainer('10.80.40.0', 24, SubnetStatus::Active);

        $crawler = $this->submitCreation('10.80.40.0/24', 'Doublon');

        self::assertStringContainsString('chevauche un reseau existant', $crawler->filter('.field__errors')->text());
    }

    public function testInvalidNotationIsRefused(): void
    {
        $crawler = $this->submitCreation('nawak/24', 'Bêtise');

        self::assertStringContainsString('Adresse IP invalide', $crawler->filter('.field__errors')->text());
    }

    /**
     * Un bloc intercale doit reprendre les reseaux qu'il englobe, sinon
     * l'arborescence affichee ne correspond plus a la realite du plan.
     */
    public function testAnIntermediateBlockAdoptsTheNetworksItContains(): void
    {
        $root = $this->givenContainer('10.80.0.0', 8);
        // La feuille est accrochee au /8 : c'est la situation que le bloc
        // intercale doit corriger en s'inserant entre les deux.
        $leaf = $this->givenContainer('10.80.50.0', 24, SubnetStatus::Active, $root);

        $rootId = $root->getId();
        $leafId = $leaf->getId();

        $this->submitCreation('10.80.0.0/16', 'Bloc intercalé');

        // La requete HTTP travaille sur son propre etat : les objets d'avant ne
        // sont plus fiables, tout se relit par identifiant.
        $repository = static::getContainer()->get(SubnetRepository::class);
        $inserted = $this->find('10.80.0.0', 16);

        self::assertNotNull($inserted);
        self::assertSame($rootId, $inserted->getParent()?->getId());
        self::assertSame($inserted->getId(), $repository->find($leafId)?->getParent()?->getId());
    }

    /** Supprimer un bloc ne doit pas emporter le plan qu'il contient. */
    public function testDeletingABlockLiftsItsChildrenToTheParent(): void
    {
        $root = $this->givenContainer('10.81.0.0', 16);
        $middle = $this->givenContainer('10.81.0.0', 20, SubnetStatus::Container, $root);
        $leaf = $this->givenContainer('10.81.1.0', 24, SubnetStatus::Active, $middle);

        $rootId = $root->getId();
        $middleId = $middle->getId();
        $leafId = $leaf->getId();

        $this->client->request('GET', '/reseaux/'.$middleId);
        $this->client->submitForm('Supprimer');

        $repository = static::getContainer()->get(SubnetRepository::class);

        self::assertNull($repository->find($middleId));
        self::assertSame($rootId, $repository->find($leafId)?->getParent()?->getId());
    }

    private function submitCreation(string $cidr, string $name): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/reseaux/nouveau?organisation='.$this->organization->getId());

        return $this->client->submit($crawler->selectButton('Créer le réseau')->form([
            'subnet[cidr]' => $cidr,
            'subnet[name]' => $name,
            'subnet[status]' => SubnetStatus::Active->value,
        ]));
    }

    private function givenContainer(
        string $network,
        int $prefix,
        SubnetStatus $status = SubnetStatus::Container,
        ?Subnet $parent = null,
    ): Subnet {
        $tools = static::getContainer()->get(\App\Service\IpTools::class);

        $subnet = (new Subnet())
            ->setOrganization($this->organization)
            ->setParent($parent)
            ->setVersion(IpVersion::V4)
            ->setNetworkAddress($network)
            ->setLastAddress($tools->lastAddress($network, $prefix))
            ->setPrefixLength($prefix)
            ->setStatus($status);

        $this->em->persist($subnet);
        $this->em->flush();

        return $subnet;
    }

    private function find(string $network, int $prefix): ?Subnet
    {
        return static::getContainer()->get(SubnetRepository::class)->findOneBy([
            'organization' => $this->organization,
            'networkAddress' => $network,
            'prefixLength' => $prefix,
        ]);
    }
}
