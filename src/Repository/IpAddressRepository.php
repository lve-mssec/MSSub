<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\Type\IpAddressType;
use App\Entity\IpAddress;
use App\Entity\Subnet;
use App\Enum\IpStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Type;
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
     * Uniquement les adresses reellement occupees, en forme pointee.
     *
     * Une ligne au statut « libre » est documentee mais disponible : elle ne
     * doit pas bloquer une attribution. Renvoyer les chaines plutot que les
     * entites evite par ailleurs d'hydrater des milliers d'objets quand on ne
     * cherche qu'un trou dans la plage.
     *
     * @return list<string>
     */
    public function findTakenAddresses(Subnet $subnet): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.address')
            ->andWhere('a.subnet = :subnet')
            ->andWhere('a.status != :free')
            ->setParameter('subnet', $subnet)
            ->setParameter('free', IpStatus::Free->value)
            ->orderBy('a.address', 'ASC')
            ->getQuery()
            ->getScalarResult();

        // L'hydratation scalaire rend la valeur telle que la base la donne, sans
        // passer par le type Doctrine : sur une colonne VARBINARY, c'est du
        // binaire brut. La conversion doit donc etre demandee explicitement.
        $type = Type::getType(IpAddressType::NAME);
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        $addresses = [];
        foreach ($rows as $row) {
            $printable = $type->convertToPHPValue($row['address'], $platform);
            if (null !== $printable) {
                $addresses[] = $printable;
            }
        }

        return $addresses;
    }

    /**
     * Nombre d'adresses occupees, pour tous les reseaux d'un coup.
     *
     * Une seule requete groupee plutot qu'un comptage par ligne : l'arborescence
     * affiche l'occupation de chaque reseau, et un plan un peu fourni ferait
     * sinon plusieurs centaines d'allers-retours.
     *
     * @return array<int, int> identifiant de reseau => nombre d'adresses occupees
     */
    public function countTakenGroupedBySubnet(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.subnet) AS subnetId, COUNT(a.id) AS total')
            ->andWhere('a.status != :free')
            ->setParameter('free', IpStatus::Free->value)
            ->groupBy('a.subnet')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['subnetId']] = (int) $row['total'];
        }

        return $counts;
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
