<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un paramètre d'exploitation, modifiable depuis le portail.
 *
 * Ce qui vit ici plutôt qu'en variable d'environnement : tout ce qu'un
 * administrateur doit pouvoir changer sans accès au serveur. Les variables
 * d'environnement restent la valeur de repli, ce qui permet de démarrer une
 * installation neuve déjà configurée, et de reprendre la main si la base dit
 * n'importe quoi.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
#[ORM\HasLifecycleCallbacks]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    /**
     * Marque une valeur chiffrée au repos.
     *
     * Le drapeau est stocké plutôt que déduit du nom : il faut savoir déchiffrer
     * une ligne existante même si la liste des champs sensibles change ensuite.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $secret = false;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function setSecret(bool $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
