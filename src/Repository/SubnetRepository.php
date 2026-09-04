<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\SubnetStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Les requetes d'arborescence passent toutes par les bornes binaires
 * (network_address, last_address), jamais par un parcours recursif de `parent`.
 *
 * @extends ServiceEntityRepository<Subnet>
 */
class SubnetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subnet::class);
    }

    /**
     * Le subnet le plus specifique contenant cette adresse.
     *
     * Le tri par prefixe decroissant donne le « longest prefix match » : entre
     * 10.0.0.0/8 et 10.1.2.0/24, c'est le /24 qui repond.
     */
    public function findContaining(string $ip, Organization $organization): ?Subnet
    {
        return $this->containmentQuery($ip, $organization)
            ->orderBy('s.prefixLength', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Toute la chaine de blocs contenant cette adresse, du plus large au plus fin.
     *
     * @return list<Subnet>
     */
    public function findContainingChain(string $ip, Organization $organization): array
    {
        return $this->containmentQuery($ip, $organization)
            ->orderBy('s.prefixLength', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les reseaux qui chevauchent la plage donnee — le controle a faire avant
     * toute creation. Un chevauchement se detecte par la negation de « disjoint ».
     *
     * @return list<Subnet>
     */
    public function findOverlapping(string $firstIp, string $lastIp, Organization $organization, ?int $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.organization = :org')
            ->andWhere('s.networkAddress <= :last')
            ->andWhere('s.lastAddress >= :first')
            ->setParameter('org', $organization)
            ->setParameter('first', $firstIp, 'ip_address')
            ->setParameter('last', $lastIp, 'ip_address')
            ->orderBy('s.networkAddress', 'ASC');

        if (null !== $excludeId) {
            $qb->andWhere('s.id != :self')->setParameter('self', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les blocs de premier niveau d'une organisation.
     *
     * @return list<Subnet>
     */
    public function findRoots(Organization $organization): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.organization = :org')
            ->andWhere('s.parent IS NULL')
            ->setParameter('org', $organization)
            ->orderBy('s.networkAddress', 'ASC')
            ->addOrderBy('s.prefixLength', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les enfants directs d'un bloc, tries dans l'ordre du plan d'adressage.
     *
     * @return list<Subnet>
     */
    public function findChildren(Subnet $parent): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.parent = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('s.networkAddress', 'ASC')
            ->addOrderBy('s.prefixLength', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les sous-reseaux deja poses dans un bloc, tries — la base de calcul du
     * prochain espace libre.
     *
     * @return list<Subnet>
     */
    public function findAllocatedWithin(Subnet $container): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.organization = :org')
            ->andWhere('s.id != :self')
            ->andWhere('s.networkAddress >= :first')
            ->andWhere('s.lastAddress <= :last')
            ->setParameter('org', $container->getOrganization())
            ->setParameter('self', $container->getId())
            ->setParameter('first', $container->getNetworkAddress(), 'ip_address')
            ->setParameter('last', $container->getLastAddress(), 'ip_address')
            ->orderBy('s.networkAddress', 'ASC')
            ->addOrderBy('s.prefixLength', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Subnet> */
    public function findAssignable(Organization $organization): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.organization = :org')
            ->andWhere('s.status != :container')
            ->setParameter('org', $organization)
            ->setParameter('container', SubnetStatus::Container->value)
            ->orderBy('s.networkAddress', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function containmentQuery(string $ip, Organization $organization): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.organization = :org')
            ->andWhere('s.networkAddress <= :ip')
            ->andWhere('s.lastAddress >= :ip')
            ->setParameter('org', $organization)
            ->setParameter('ip', $ip, 'ip_address');
    }
}
