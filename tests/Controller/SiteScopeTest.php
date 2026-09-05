<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\IpAddress;
use App\Entity\Organization;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Entity\User;
use App\Enum\AuthSource;
use App\Enum\IpStatus;
use App\Enum\IpVersion;
use App\Enum\SubnetStatus;
use App\Service\IpTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le périmètre de travail, vu depuis les écrans.
 *
 * Le point délicat n'est pas le filtre lui-même mais l'héritage : un
 * sous-réseau sans site déclaré appartient au site de son bloc parent, et doit
 * donc apparaître dans son périmètre. Un filtre naïf sur la seule colonne
 * « site » laisserait ces écrans presque vides.
 */
final class SiteScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Organization $organization;
    private Site $paris;
    private Site $lyon;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user = (new User())->setUsername('visiteur.perimetre')->setAuthSource(AuthSource::Local);
        $user->setPassword('peu-importe');
        $user->setRoles([User::ROLE_USER]);
        $this->em->persist($user);

        $this->organization = (new Organization())->setCode('SCOPE')->setName('Organisation périmètre');
        $this->em->persist($this->organization);

        $this->paris = $this->givenSite('PAR', 'Paris');
        $this->lyon = $this->givenSite('LYO', 'Lyon');

        // 10.60.0.0/8 ne porte aucun site ; le /16 parisien en porte un, et son
        // /24 n'en déclare pas : c'est lui qui prouve l'héritage.
        $root = $this->givenSubnet('10.60.0.0', 8, null, null, SubnetStatus::Container);
        $blockParis = $this->givenSubnet('10.60.0.0', 16, $this->paris, $root, SubnetStatus::Container);
        $lan = $this->givenSubnet('10.60.10.0', 24, null, $blockParis);
        $this->givenSubnet('10.61.0.0', 16, $this->lyon, $root, SubnetStatus::Container);

        $this->em->persist((new IpAddress())->setSubnet($lan)->setAddress('10.60.10.5')->setStatus(IpStatus::Used));
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testTreeGroupsBySiteIncludingInheritedOnes(): void
    {
        $crawler = $this->client->request('GET', '/reseaux');

        self::assertResponseIsSuccessful();

        $headings = $crawler->filter('.site-heading')->each(fn ($node): string => trim($node->text()));
        self::assertContains('Paris PAR', $headings);
        self::assertContains('Lyon LYO', $headings);
        self::assertContains('Sans site non rattaché', $headings);
    }

    public function testSelectingASiteHidesTheOthers(): void
    {
        $this->selectContext($this->paris);

        $crawler = $this->client->request('GET', '/reseaux');
        $networks = $crawler->filter('table[data-tree] tbody td.cidr a')->each(fn ($n): string => trim($n->text()));

        self::assertContains('10.60.0.0/16', $networks);
        // Le /24 n'a pas de site déclaré : il ne doit sa présence qu'à l'héritage.
        self::assertContains('10.60.10.0/24', $networks);
        self::assertNotContains('10.61.0.0/16', $networks);
        self::assertNotContains('10.60.0.0/8', $networks);
    }

    public function testDashboardCountsFollowTheScope(): void
    {
        $this->selectContext($this->paris);

        $crawler = $this->client->request('GET', '/');
        $labels = $crawler->filter('.stat__label')->each(fn ($n): string => trim($n->text()));
        $values = $crawler->filter('.stat__value')->each(fn ($n): string => trim($n->text()));
        $counts = array_combine($labels, $values);

        self::assertSame('1', $counts['Organisations']);
        self::assertSame('1', $counts['Sites']);
        self::assertSame('2', $counts['Réseaux'], 'Le /16 déclaré et le /24 hérité.');
        self::assertSame('1', $counts['Adresses']);
    }

    /** Le site d'une autre organisation ne doit pas produire un périmètre incohérent. */
    public function testChoosingAForeignSiteMovesTheWholeScope(): void
    {
        $other = (new Organization())->setCode('AUTRE')->setName('Autre organisation');
        $this->em->persist($other);
        $foreign = (new Site())->setOrganization($other)->setCode('ZZZ')->setName('Ailleurs');
        $this->em->persist($foreign);
        $this->em->flush();

        $this->selectContext($foreign, $this->organization);

        $crawler = $this->client->request('GET', '/reseaux');
        self::assertStringContainsString('Autre organisation', $crawler->filter('.page-head p')->text());
    }

    public function testSiteSheetCountsInheritedNetworks(): void
    {
        $crawler = $this->client->request('GET', '/sites/'.$this->paris->getId());

        self::assertResponseIsSuccessful();
        $networks = $crawler->filter('table td.cidr a')->each(fn ($n): string => trim($n->text()));

        self::assertContains('10.60.0.0/16', $networks);
        self::assertContains('10.60.10.0/24', $networks);
        self::assertNotContains('10.61.0.0/16', $networks);
        // L'origine du rattachement est dite : « déclaré » ou « hérité ».
        self::assertStringContainsString('hérité du bloc parent', $crawler->filter('table')->first()->html());
    }

    private function selectContext(Site $site, ?Organization $organization = null): void
    {
        $crawler = $this->client->request('GET', '/reseaux');

        $this->client->request('POST', '/contexte', [
            'organisation' => (string) ($organization ?? $site->getOrganization())->getId(),
            'site' => (string) $site->getId(),
            '_token' => $crawler->filter('form[data-context] input[name="_token"]')->attr('value'),
        ]);
    }

    private function givenSite(string $code, string $name): Site
    {
        $site = (new Site())->setOrganization($this->organization)->setCode($code)->setName($name);
        $this->em->persist($site);

        return $site;
    }

    private function givenSubnet(
        string $network,
        int $prefix,
        ?Site $site,
        ?Subnet $parent,
        SubnetStatus $status = SubnetStatus::Active,
    ): Subnet {
        $tools = static::getContainer()->get(IpTools::class);

        $subnet = (new Subnet())
            ->setOrganization($this->organization)
            ->setParent($parent)
            ->setSite($site)
            ->setVersion(IpVersion::V4)
            ->setNetworkAddress($network)
            ->setLastAddress($tools->lastAddress($network, $prefix))
            ->setPrefixLength($prefix)
            ->setName($network.'/'.$prefix)
            ->setStatus($status);

        $this->em->persist($subnet);

        return $subnet;
    }
}
