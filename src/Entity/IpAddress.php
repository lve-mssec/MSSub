<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\IpStatus;
use App\Repository\IpAddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une adresse effectivement documentee dans un subnet.
 *
 * Les adresses libres ne sont volontairement pas materialisees : un /16 en
 * ferait 65 536 par reseau pour ne rien dire. Une adresse absente de cette table
 * est libre par definition, et la recherche de la prochaine libre se fait par
 * difference entre la plage du subnet et les lignes presentes.
 */
#[ORM\Entity(repositoryClass: IpAddressRepository::class)]
#[ORM\Table(name: 'ip_address')]
#[ORM\UniqueConstraint(name: 'uniq_ip_subnet_address', columns: ['subnet_id', 'address'])]
#[ORM\Index(name: 'idx_ip_address', columns: ['address'])]
#[ORM\HasLifecycleCallbacks]
class IpAddress
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subnet::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Subnet $subnet = null;

    #[ORM\Column(type: 'ip_address')]
    #[Assert\NotBlank]
    #[Assert\Ip(version: Assert\Ip::ALL)]
    private ?string $address = null;

    #[ORM\Column(length: 20, enumType: IpStatus::class)]
    private IpStatus $status = IpStatus::Used;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $hostname = null;

    #[ORM\Column(name: 'mac_address', length: 17, nullable: true)]
    #[Assert\Regex(pattern: '/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', message: 'Adresse MAC attendue au format AA:BB:CC:DD:EE:FF.')]
    private ?string $macAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class, inversedBy: 'ipAddresses')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?NetworkInterface $interface = null;

    /** Renseigne par une future decouverte reseau ; nul tant que rien ne l'a vue. */
    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __toString(): string
    {
        return $this->address ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubnet(): ?Subnet
    {
        return $this->subnet;
    }

    public function setSubnet(?Subnet $subnet): self
    {
        $this->subnet = $subnet;

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

    public function getStatus(): IpStatus
    {
        return $this->status;
    }

    public function setStatus(IpStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function setHostname(?string $hostname): self
    {
        $this->hostname = $hostname;

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

    public function getInterface(): ?NetworkInterface
    {
        return $this->interface;
    }

    public function setInterface(?NetworkInterface $interface): self
    {
        $this->interface = $interface;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }
}
