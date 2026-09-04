<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\SiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Un lieu physique rattache a une organisation : siege, agence, datacenter. */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'site')]
#[ORM\UniqueConstraint(name: 'uniq_site_org_code', columns: ['organization_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
class Site
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'sites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Organization $organization = null;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    private string $code = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country]
    private ?string $country = null;

    /** @var Collection<int, Vlan> */
    #[ORM\OneToMany(targetEntity: Vlan::class, mappedBy: 'site')]
    private Collection $vlans;

    /** @var Collection<int, Device> */
    #[ORM\OneToMany(targetEntity: Device::class, mappedBy: 'site')]
    private Collection $devices;

    public function __construct()
    {
        $this->vlans = new ArrayCollection();
        $this->devices = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): self
    {
        $this->organization = $organization;

        return $this;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    /** @return Collection<int, Vlan> */
    public function getVlans(): Collection
    {
        return $this->vlans;
    }

    /** @return Collection<int, Device> */
    public function getDevices(): Collection
    {
        return $this->devices;
    }
}
