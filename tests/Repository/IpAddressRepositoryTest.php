<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\IpAddress;
use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\IpStatus;
use App\Enum\IpVersion;
use App\Enum\SubnetStatus;
use App\Repository\IpAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ces tests parlent a une vraie base, et c'est le but.
 *
 * Le stockage en VARBINARY(16) traverse trois couches — le type Doctrine, le
 * mode d'hydratation, le pilote PDO — et chacune peut rendre la valeur sous une
 * forme differente. Un test a double ne verrait rien de tout cela : c'est
 * exactement par la qu'un bug est passe (l'hydratation scalaire court-circuite
 * la conversion du type et rend le binaire brut).
 */
final class IpAddressRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IpAddressRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(IpAddressRepository::class);

        // Chaque test s'execute dans une transaction annulee a la fin : la base
        // ressort dans l'etat ou elle est entree, sans TRUNCATE ni ordre impose.
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testTakenAddressesComeBackAsReadableStrings(): void
    {
        $subnet = $this->givenSubnetWith([
            ['10.20.30.1', IpStatus::Gateway],
            ['10.20.30.2', IpStatus::Used],
        ]);

        $taken = $this->repository->findTakenAddresses($subnet);

        self::assertSame(['10.20.30.1', '10.20.30.2'], $taken);
    }

    /** Une adresse documentee mais libre ne doit pas bloquer une attribution. */
    public function testFreeStatusIsNotConsideredTaken(): void
    {
        $subnet = $this->givenSubnetWith([
            ['10.20.30.1', IpStatus::Used],
            ['10.20.30.2', IpStatus::Free],
        ]);

        self::assertSame(['10.20.30.1'], $this->repository->findTakenAddresses($subnet));
    }

    /**
     * Le tri doit etre numerique, pas alphabetique. En VARCHAR, .9 passerait
     * apres .10 et le calcul de la prochaine adresse libre serait faux.
     */
    public function testOrderingIsNumericAcrossTheWire(): void
    {
        $subnet = $this->givenSubnetWith([
            ['10.20.30.100', IpStatus::Used],
            ['10.20.30.9', IpStatus::Used],
            ['10.20.30.10', IpStatus::Used],
        ]);

        self::assertSame(
            ['10.20.30.9', '10.20.30.10', '10.20.30.100'],
            $this->repository->findTakenAddresses($subnet),
        );
    }

    public function testIpv6RoundTripsThroughTheBinaryColumn(): void
    {
        $subnet = $this->givenSubnetWith(
            [['2001:db8:1:2::5', IpStatus::Used]],
            '2001:db8:1:2::',
            '2001:db8:1:2:ffff:ffff:ffff:ffff',
            64,
            IpVersion::V6,
        );

        self::assertSame(['2001:db8:1:2::5'], $this->repository->findTakenAddresses($subnet));
    }

    public function testCountAndLookup(): void
    {
        $subnet = $this->givenSubnetWith([
            ['10.20.30.1', IpStatus::Used],
            ['10.20.30.2', IpStatus::Free],
        ]);

        self::assertSame(2, $this->repository->countBySubnet($subnet));
        self::assertCount(1, $this->repository->findByAddress('10.20.30.1'));
        self::assertCount(0, $this->repository->findByAddress('10.20.30.250'));
    }

    /**
     * @param list<array{0: string, 1: IpStatus}> $addresses
     */
    private function givenSubnetWith(
        array $addresses,
        string $network = '10.20.30.0',
        string $last = '10.20.30.255',
        int $prefix = 24,
        IpVersion $version = IpVersion::V4,
    ): Subnet {
        $organization = (new Organization())
            ->setCode('TEST'.random_int(1000, 9999))
            ->setName('Organisation de test');
        $this->em->persist($organization);

        $subnet = (new Subnet())
            ->setOrganization($organization)
            ->setVersion($version)
            ->setNetworkAddress($network)
            ->setLastAddress($last)
            ->setPrefixLength($prefix)
            ->setStatus(SubnetStatus::Active);
        $this->em->persist($subnet);

        foreach ($addresses as [$address, $status]) {
            $this->em->persist(
                (new IpAddress())->setSubnet($subnet)->setAddress($address)->setStatus($status),
            );
        }

        $this->em->flush();

        return $subnet;
    }
}
