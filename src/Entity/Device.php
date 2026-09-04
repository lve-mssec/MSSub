<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\DeviceType;
use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Un equipement inventorie : ce qui porte les interfaces, donc les adresses. */
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'device')]
#[ORM\Index(name: 'idx_device_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Device
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: DeviceType::class)]
    private DeviceType $type = DeviceType::Other;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $vendor = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'serial_number', length: 80, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, NetworkInterface> */
    #[ORM\OneToMany(targetEntity: NetworkInterface::class, mappedBy: 'device', orphanRemoval: true)]
    private Collection $interfaces;

    public function __construct()
    {
        $this->interfaces = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): DeviceType
    {
        return $this->type;
    }

    public function setType(DeviceType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    public function setVendor(?string $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): self
    {
        $this->serialNumber = $serialNumber;

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

    /** @return Collection<int, NetworkInterface> */
    public function getInterfaces(): Collection
    {
        return $this->interfaces;
    }
}
