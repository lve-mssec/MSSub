<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Une page du journal, du plus recent au plus ancien.
     *
     * @return array{entries: list<AuditLog>, total: int}
     */
    public function page(int $page, int $perPage, ?string $action = null, ?string $actor = null): array
    {
        $qb = $this->createQueryBuilder('a');

        if (null !== $action && '' !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }

        if (null !== $actor && '' !== $actor) {
            $qb->andWhere('a.actorUsername LIKE :actor')->setParameter('actor', '%'.$actor.'%');
        }

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $entries = $qb
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * L'historique d'une ligne precise du referentiel.
     *
     * @return list<AuditLog>
     */
    public function forEntity(string $entityClass, string $entityId, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityClass = :class')
            ->andWhere('a.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
