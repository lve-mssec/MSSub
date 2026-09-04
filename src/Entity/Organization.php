<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le plus haut niveau du referentiel : un client, une entite, un tenant.
 *
 * C'est aussi la frontiere d'unicite des plans d'adressage — deux organisations
 * peuvent legitimement declarer 10.0.0.0/8 chacune de leur cote.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'uniq_organization_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class Organization
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    #[Assert\Regex(pattern: '/^[A-Z0-9_-]+$/', message: 'Le code doit etre en majuscules, chiffres, tiret ou souligne.')]
    private string $code = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Site> */
    #[ORM\OneToMany(targetEntity: Site::class, mappedBy: 'organization')]
    private Collection $sites;

    public function __construct()
    {
        $this->sites = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper($code);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** @return Collection<int, Site> */
    public function getSites(): Collection
    {
        return $this->sites;
    }
}
