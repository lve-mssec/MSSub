<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AuditLog;
use App\Enum\AuditAction;
use App\Service\AuditRecorder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Journalise automatiquement les écritures du référentiel.
 *
 * Le choix d'un écouteur Doctrine plutôt que d'appels explicites dans chaque
 * contrôleur est délibéré : un journal qu'on peut oublier d'alimenter ne vaut
 * rien. Ici, toute écriture passant par l'ORM est tracée, y compris celles
 * venues d'une commande ou d'un import.
 *
 * Les entités sont collectées pendant onFlush — seul moment où les changements
 * sont connus et où une suppression porte encore son identifiant — puis écrites
 * en postFlush, une fois les identifiants des créations attribués.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditListener
{
    /** Ce qui n'a pas à figurer dans un journal, jamais. */
    private const REDACTED = ['password'];

    /** Bruit d'horodatage : le journal porte déjà sa propre date. */
    private const IGNORED = ['createdAt', 'updatedAt'];

    /** @var list<array{log: AuditLog, entity: object|null}> */
    private array $pending = [];

    private bool $writing = false;

    public function __construct(private readonly AuditRecorder $recorder)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($this->isAuditable($entity)) {
                $this->pending[] = [
                    'log' => $this->recorder->build(AuditAction::Create, $entity),
                    'entity' => $entity,
                ];
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$this->isAuditable($entity)) {
                continue;
            }

            $changes = $this->summarize($unitOfWork->getEntityChangeSet($entity));
            if ([] === $changes) {
                continue; // Rien de significatif n'a bougé : pas de ligne de journal.
            }

            $this->pending[] = [
                'log' => $this->recorder->build(AuditAction::Update, $entity, $changes),
                'entity' => $entity,
            ];
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$this->isAuditable($entity)) {
                continue;
            }

            // L'identifiant est relevé maintenant : après la suppression, il
            // aura disparu de l'objet et la ligne deviendrait anonyme.
            $this->pending[] = [
                'log' => $this->recorder->build(AuditAction::Delete, $entity),
                'entity' => null,
            ];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending || $this->writing) {
            return;
        }

        $entries = $this->pending;
        $this->pending = [];
        $this->writing = true;

        try {
            $em = $args->getObjectManager();

            foreach ($entries as $entry) {
                // Une création ne connaît son identifiant qu'ici.
                if (null !== $entry['entity'] && method_exists($entry['entity'], 'getId')) {
                    $entry['log']->setEntityId((string) $entry['entity']->getId());
                }
                $em->persist($entry['log']);
            }

            $em->flush();
        } finally {
            $this->writing = false;
        }
    }

    private function isAuditable(object $entity): bool
    {
        return !$entity instanceof AuditLog;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function summarize(array $changeSet): array
    {
        $changes = [];

        foreach ($changeSet as $field => [$before, $after]) {
            if (\in_array($field, self::IGNORED, true)) {
                continue;
            }

            if (\in_array($field, self::REDACTED, true)) {
                $changes[$field] = ['(masqué)', '(masqué)'];
                continue;
            }

            $changes[$field] = [$this->recorder->normalize($before), $this->recorder->normalize($after)];
        }

        return $changes;
    }
}
