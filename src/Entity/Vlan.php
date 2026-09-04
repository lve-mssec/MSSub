<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\VlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un VLAN, unique dans son domaine de diffusion — ici le site.
 *
 * Un site nul signifie un VLAN global (transverse a l'organisation) ; l'unicite
 * ne joue alors plus, ce que la contrainte SQL tolere puisque NULL n'est jamais
 * egal a NULL.
 */
#[ORM\Entity(repositoryClass: VlanRepository::class)]
#[ORM\Table(name: 'vlan')]
#[ORM\UniqueConstraint(name: 'uniq_vlan_site_number', columns: ['site_id', 'number'])]
#[ORM\HasLifecycleCallbacks]
class Vlan
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'vlans')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    /** 1 a 4094 : 0 et 4095 sont reserves par 802.1Q. */
    #[ORM\Column(name: 'number', type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 4094)]
    private int $number = 1;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Subnet> */
    #[ORM\OneToMany(targetEntity: Subnet::class, mappedBy: 'vlan')]
    private Collection $subnets;

    public function __construct()
    {
        $this->subnets = new ArrayCollection();
    }

    public function __toString(): string
    {
        return \sprintf('VLAN %d — %s', $this->number, $this->name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): self
    {
        $this->number = $number;

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

    /** @return Collection<int, Subnet> */
    public function getSubnets(): Collection
    {
        return $this->subnets;
    }
}
