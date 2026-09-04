<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\IpAddress;
use App\Entity\Organization;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Enum\IpStatus;
use App\Enum\IpVersion;
use App\Enum\SubnetStatus;
use App\Repository\SubnetRepository;
use App\Service\Export\CsvExporter;
use App\Service\Export\DhcpConfigExporter;
use App\Service\Export\DnsZoneExporter;
use App\Service\Import\PlanImporter;
use App\Service\Import\RowReader;
use App\Service\IpTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Import et export, sur une base reelle.
 *
 * L'aller-retour est la propriete qui compte : un plan exporte puis reimporte
 * ne doit rien changer. C'est ce qui permet a un operateur de sortir le
 * referentiel, de le retoucher dans un tableur, et de le recharger sans
 * craindre que l'outil reinterprete ce qu'il n'a pas touche.
 */
final class ImportExportTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Organization $organization;
    private string $workDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->workDir = sys_get_temp_dir().'/mssub-tests';
        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0o777, true);
        }

        $this->organization = (new Organization())->setCode('IMPEX')->setName('Organisation import');
        $this->em->persist($this->organization);

        $site = (new Site())->setOrganization($this->organization)->setCode('PAR')->setName('Paris');
        $this->em->persist($site);

        $this->givenSubnet('10.70.0.0', 16, SubnetStatus::Container, 'Bloc');
        $lan = $this->givenSubnet('10.70.10.0', 24, SubnetStatus::Active, 'LAN');
        $lan->setGateway('10.70.10.254')
            ->setDnsServers(['10.70.10.1'])
            ->setDhcpEnabled(true)
            ->setDhcpRangeStart('10.70.10.100')
            ->setDhcpRangeEnd('10.70.10.200');

        $this->em->persist(
            (new IpAddress())->setSubnet($lan)->setAddress('10.70.10.5')
                ->setStatus(IpStatus::Used)->setHostname('srv-test')->setMacAddress('AA:BB:CC:DD:EE:01'),
        );

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    /** Le test central : exporter puis réimporter ne doit rien modifier. */
    public function testExportedSubnetsReimportUnchanged(): void
    {
        $csv = self::getContainer()->get(CsvExporter::class)->subnets($this->organization);
        $path = $this->workDir.'/reseaux.csv';
        file_put_contents($path, $csv);

        $rows = self::getContainer()->get(RowReader::class)->read($path, 'reseaux.csv');
        $report = self::getContainer()->get(PlanImporter::class)
            ->importSubnets($rows, $this->reloadOrganization(), true);

        self::assertSame(0, $report->getCreated());
        self::assertSame(0, $report->getUpdated());
        self::assertSame(2, $report->getUnchanged());
        self::assertFalse($report->hasErrors());
    }

    /** Les intitulés d'un tableur réel ne sont jamais ceux qu'on attend. */
    public function testColumnAliasesAreUnderstood(): void
    {
        $rows = $this->readCsv("Réseau;Nom;Commentaire\n10.70.20.0/24;Depuis alias;Une note\n");

        $report = self::getContainer()->get(PlanImporter::class)
            ->importSubnets($rows, $this->reloadOrganization(), false);

        self::assertFalse($report->hasErrors());
        self::assertSame(1, $report->getCreated());
        self::assertSame('Depuis alias', $this->find('10.70.20.0', 24)?->getName());
    }

    public function testDryRunWritesNothing(): void
    {
        $rows = $this->readCsv("cidr;nom\n10.70.30.0/24;Simulation\n");

        $report = self::getContainer()->get(PlanImporter::class)
            ->importSubnets($rows, $this->reloadOrganization(), true);

        self::assertSame(1, $report->getCreated());
        self::assertNull($this->find('10.70.30.0', 24), 'La simulation ne doit rien écrire.');
    }

    /**
     * Une seule ligne fautive interdit toute écriture : importer la moitié d'un
     * fichier laisserait un référentiel que personne ne saurait décrire.
     */
    public function testASingleBadLineBlocksTheWholeFile(): void
    {
        $rows = $this->readCsv("cidr;nom\n10.70.40.0/24;Correct\nn-importe-quoi;Fautif\n");

        $report = self::getContainer()->get(PlanImporter::class)
            ->importSubnets($rows, $this->reloadOrganization(), false);

        self::assertTrue($report->hasErrors());
        self::assertNull($this->find('10.70.40.0', 24), 'Aucune ligne ne doit passer si une seule échoue.');
    }

    public function testDuplicateWithinTheFileIsReportedWithItsLine(): void
    {
        $rows = $this->readCsv("cidr;nom\n10.70.50.0/24;Premier\n10.70.50.0/24;Second\n");

        $report = self::getContainer()->get(PlanImporter::class)
            ->importSubnets($rows, $this->reloadOrganization(), true);

        self::assertStringContainsString('ligne 2', $report->getErrors()[0]['message']);
        self::assertSame(3, $report->getErrors()[0]['line']);
    }

    /** Un statut ou un site inconnu ne doit pas condamner le fichier entier. */
    public function testUnknownReferencesAreWarningsNotErrors(): void
    {
        $rows = $this->readCsv("cidr;nom;statut;site\n10.70.60.0/24;Avec scories;n-existe-pas;ZZZ\n");

        $report = self::getContainer()->get(PlanImporter::class)
            ->importSubnets($rows, $this->reloadOrganization(), false);

        self::assertFalse($report->hasErrors());
        self::assertTrue($report->hasWarnings());
        self::assertNotNull($this->find('10.70.60.0', 24));
    }

    /** L'adresse trouve son réseau toute seule ; hors plan, elle est refusée. */
    public function testAddressImportFindsItsSubnetAndRefusesOrphans(): void
    {
        $rows = $this->readCsv(
            "IP;Hostname;Statut\n10.70.10.42;srv-importe;used\n172.31.0.1;hors-plan;used\n10.70.0.9;dans-conteneur;used\n",
        );

        $report = self::getContainer()->get(PlanImporter::class)
            ->importAddresses($rows, $this->reloadOrganization(), true);

        self::assertSame(1, $report->getCreated());
        self::assertCount(2, $report->getErrors());
        self::assertStringContainsString('Aucun réseau', $report->getErrors()[0]['message']);
        self::assertStringContainsString('conteneur', $report->getErrors()[1]['message']);
    }

    public function testDnsExportEmitsFragmentsWithoutSoa(): void
    {
        $zone = self::getContainer()->get(DnsZoneExporter::class)->forward($this->organization, 'mssec.local');

        self::assertStringContainsString('srv-test.mssec.local.', $zone);
        self::assertStringContainsString('IN  A     10.70.10.5', $zone);
        // Inventer un SOA produirait un fichier d'apparence valide qui
        // écraserait la zone réelle à la première inclusion. L'assertion porte
        // sur l'enregistrement, pas sur le mot : l'en-tête du fragment explique
        // justement pourquoi il n'y en a pas.
        self::assertDoesNotMatchRegularExpression('/^[^;]*\bIN\s+SOA\b/m', $zone);
        self::assertDoesNotMatchRegularExpression('/^[^;]*\bIN\s+NS\b/m', $zone);
        self::assertStringNotContainsString('$TTL', $zone);
    }

    public function testReverseZoneNameFollowsThePrefix(): void
    {
        $exporter = self::getContainer()->get(DnsZoneExporter::class);
        $lan = $this->find('10.70.10.0', 24);
        $block = $this->find('10.70.0.0', 16);

        self::assertNotNull($lan);
        self::assertNotNull($block);
        self::assertSame('10.70.10.in-addr.arpa.', $exporter->reverseZone($lan));
        self::assertSame('70.10.in-addr.arpa.', $exporter->reverseZone($block));
        self::assertStringContainsString('5        IN  PTR   srv-test.mssec.local.', $exporter->reverse($lan, 'mssec.local'));
    }

    public function testDhcpExportDeclaresOnlyNetworksWithACompleteRange(): void
    {
        $config = self::getContainer()->get(DhcpConfigExporter::class)->export($this->organization, 'mssec.local');

        self::assertStringContainsString('subnet 10.70.10.0 netmask 255.255.255.0 {', $config);
        self::assertStringContainsString('range 10.70.10.100 10.70.10.200;', $config);
        self::assertStringContainsString('option routers 10.70.10.254;', $config);
        // La MAC documentée devient une réservation : ce que l'IPAM affiche doit
        // être ce que le serveur attribue.
        self::assertStringContainsString('hardware ethernet aa:bb:cc:dd:ee:01;', $config);
        // Le bloc conteneur ne porte pas de plage : il ne doit rien produire.
        self::assertStringNotContainsString('subnet 10.70.0.0', $config);
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $content): array
    {
        $path = $this->workDir.'/'.uniqid('import', true).'.csv';
        file_put_contents($path, $content);

        return self::getContainer()->get(RowReader::class)->read($path, basename($path));
    }

    private function givenSubnet(string $network, int $prefix, SubnetStatus $status, string $name): Subnet
    {
        $tools = self::getContainer()->get(IpTools::class);

        $subnet = (new Subnet())
            ->setOrganization($this->organization)
            ->setVersion(IpVersion::V4)
            ->setNetworkAddress($network)
            ->setLastAddress($tools->lastAddress($network, $prefix))
            ->setPrefixLength($prefix)
            ->setName($name)
            ->setStatus($status);
        $this->em->persist($subnet);

        return $subnet;
    }

    /** L'import vide l'EntityManager : l'organisation doit être relue. */
    private function reloadOrganization(): Organization
    {
        return $this->em->getRepository(Organization::class)->findOneBy(['code' => 'IMPEX']);
    }

    private function find(string $network, int $prefix): ?Subnet
    {
        return self::getContainer()->get(SubnetRepository::class)->findOneBy([
            'organization' => $this->reloadOrganization(),
            'networkAddress' => $network,
            'prefixLength' => $prefix,
        ]);
    }
}
