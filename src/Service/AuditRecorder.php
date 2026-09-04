<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ecrit dans le journal d'audit.
 *
 * Centralise deux choses qu'il ne faut pas disperser : d'ou vient l'acteur, et
 * comment une valeur devient lisible. Le journal doit rester comprehensible des
 * annees plus tard, quand l'entite concernee aura peut-etre disparu — d'ou le
 * libelle fige et les valeurs rendues en clair plutot qu'en identifiants.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}>|null $changes
     */
    public function build(
        AuditAction $action,
        ?object $entity = null,
        ?array $changes = null,
        ?string $label = null,
        ?string $actorUsername = null,
    ): AuditLog {
        $log = (new AuditLog())
            ->setAction($action)
            ->setChanges($changes)
            ->setLabel($label ?? $this->describe($entity));

        if (null !== $entity) {
            $log->setEntityClass($this->shortName($entity::class));
            $log->setEntityId($this->identify($entity));
        }

        $user = $this->security->getUser();
        $log->setActorUsername($actorUsername ?? $user?->getUserIdentifier() ?? 'système');
        if ($user instanceof User) {
            $log->setActorId($user->getId());
        }

        $request = $this->requests->getCurrentRequest();
        if (null !== $request) {
            $log->setClientIp($request->getClientIp())
                ->setUserAgent($request->headers->get('User-Agent'));
        }

        return $log;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}>|null $changes
     */
    public function record(
        EntityManagerInterface $em,
        AuditAction $action,
        ?object $entity = null,
        ?array $changes = null,
        ?string $label = null,
    ): void {
        $log = $this->build($action, $entity, $changes, $label);
        $em->persist($log);
    }

    /** Rend une valeur lisible : enum, date, objet ou scalaire. */
    public function normalize(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            \is_array($value) => implode(', ', array_map(fn ($item) => (string) $this->normalize($item), $value)),
            $value instanceof \Stringable => (string) $value,
            default => $this->identify($value),
        };
    }

    public function describe(?object $entity): ?string
    {
        if (null === $entity) {
            return null;
        }

        $label = $entity instanceof \Stringable ? (string) $entity : $this->shortName($entity::class);

        return '' === $label ? $this->shortName($entity::class) : mb_substr($label, 0, 255);
    }

    private function identify(object $entity): ?string
    {
        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();

            return null === $id ? null : (string) $id;
        }

        return null;
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
