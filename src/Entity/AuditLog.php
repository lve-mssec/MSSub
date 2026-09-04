<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditAction;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le journal : qui a fait quoi, quand, depuis ou.
 *
 * Volontairement en append-only et sans cle etrangere vers l'auteur : le nom de
 * l'acteur est fige au moment du fait. Un compte supprime ou renomme ne doit pas
 * pouvoir reecrire l'histoire, ni faire disparaitre la ligne en cascade.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_target', columns: ['entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_username'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'actor_username', length: 180)]
    private string $actorUsername = 'system';

    /** Copie de l'identifiant du compte, sans contrainte : purement indicatif. */
    #[ORM\Column(name: 'actor_id', nullable: true)]
    private ?int $actorId = null;

    #[ORM\Column(length: 20, enumType: AuditAction::class)]
    private AuditAction $action = AuditAction::Update;

    #[ORM\Column(name: 'entity_class', length: 120, nullable: true)]
    private ?string $entityClass = null;

    #[ORM\Column(name: 'entity_id', length: 64, nullable: true)]
    private ?string $entityId = null;

    /** Ce que la ligne representait, en clair — pour rester lisible apres suppression. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    /** @var array<string, array{0: mixed, 1: mixed}>|null avant/apres, par champ */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes = null;

    #[ORM\Column(name: 'client_ip', type: 'ip_address', nullable: true)]
    private ?string $clientIp = null;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getActorUsername(): string
    {
        return $this->actorUsername;
    }

    public function setActorUsername(string $actorUsername): self
    {
        $this->actorUsername = $actorUsername;

        return $this;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function setActorId(?int $actorId): self
    {
        $this->actorId = $actorId;

        return $this;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function setAction(AuditAction $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    public function setEntityClass(?string $entityClass): self
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setEntityId(?string $entityId): self
    {
        $this->entityId = $entityId;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** @return array<string, array{0: mixed, 1: mixed}>|null */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    /** @param array<string, array{0: mixed, 1: mixed}>|null $changes */
    public function setChanges(?array $changes): self
    {
        $this->changes = $changes;

        return $this;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): self
    {
        $this->clientIp = $clientIp;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);

        return $this;
    }
}
