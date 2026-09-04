<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\NetworkInterfaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une interface d'equipement.
 *
 * Le nom volontairement long evite la collision avec l'interface native de PHP
 * si un jour le code manipule les deux dans le meme fichier.
 */
#[ORM\Entity(repositoryClass: NetworkInterfaceRepository::class)]
#[ORM\Table(name: 'network_interface')]
#[ORM\UniqueConstraint(name: 'uniq_interface_device_name', columns: ['device_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class NetworkInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'interfaces')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Device $device = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(name: 'mac_address', length: 17, nullable: true)]
    #[Assert\Regex(pattern: '/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', message: 'Adresse MAC attendue au format AA:BB:CC:DD:EE:FF.')]
    private ?string $macAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, IpAddress> */
    #[ORM\OneToMany(targetEntity: IpAddress::class, mappedBy: 'interface')]
    private Collection $ipAddresses;

    public function __construct()
    {
        $this->ipAddresses = new ArrayCollection();
    }

    public function __toString(): string
    {
        return \sprintf('%s:%s', $this->device?->getName() ?? '?', $this->name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): self
    {
        $this->device = $device;

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

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function setMacAddress(?string $macAddress): self
    {
        $this->macAddress = null === $macAddress ? null : strtoupper(str_replace('-', ':', $macAddress));

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

    /** @return Collection<int, IpAddress> */
    public function getIpAddresses(): Collection
    {
        return $this->ipAddresses;
    }
}
