<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IpAddress;
use App\Entity\Subnet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IpAddress> */
class IpAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpAddress::class);
    }

    /**
     * Les adresses documentees d'un subnet, dans l'ordre numerique.
     *
     * @return list<IpAddress>
     */
    public function findBySubnet(Subnet $subnet): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.subnet = :subnet')
            ->setParameter('subnet', $subnet)
            ->orderBy('a.address', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Uniquement les adresses occupees, en forme pointee.
     *
     * Renvoyer les chaines plutot que les entites evite d'hydrater des milliers
     * d'objets quand on ne cherche qu'un trou dans la plage.
     *
     * @return list<string>
     */
    public function findTakenAddresses(Subnet $subnet): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.address')
            ->andWhere('a.subnet = :subnet')
            ->setParameter('subnet', $subnet)
            ->orderBy('a.address', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_column($rows, 'address')));
    }

    public function countBySubnet(Subnet $subnet): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.subnet = :subnet')
            ->setParameter('subnet', $subnet)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recherche globale d'une adresse, tous subnets confondus.
     *
     * @return list<IpAddress>
     */
    public function findByAddress(string $ip): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.address = :ip')
            ->setParameter('ip', $ip, 'ip_address')
            ->getQuery()
            ->getResult();
    }
}
