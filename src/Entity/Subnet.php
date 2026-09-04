<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\IpVersion;
use App\Enum\SubnetStatus;
use App\Repository\SubnetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un reseau : bloc parent (supernet) ou sous-reseau terminal.
 *
 * La hierarchie est portee par `parent`, mais la verite est dans le couple
 * (networkAddress, lastAddress) : deux bornes binaires indexees. C'est ce qui
 * permet de repondre en une requete a « quel subnet contient cette IP ? » et a
 * « quel est le prochain /24 libre dans ce bloc ? », sans parcourir l'arbre.
 * `parent` reste la pour l'affichage et pour la coherence referentielle.
 */
#[ORM\Entity(repositoryClass: SubnetRepository::class)]
#[ORM\Table(name: 'subnet')]
#[ORM\UniqueConstraint(name: 'uniq_subnet_org_network', columns: ['organization_id', 'network_address', 'prefix_length'])]
#[ORM\Index(name: 'idx_subnet_range', columns: ['network_address', 'last_address'])]
#[ORM\Index(name: 'idx_subnet_parent', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
class Subnet
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, Subnet> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['networkAddress' => 'ASC'])]
    private Collection $children;

    #[ORM\ManyToOne(targetEntity: Vlan::class, inversedBy: 'subnets')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vlan $vlan = null;

    #[ORM\Column(type: Types::SMALLINT, enumType: IpVersion::class)]
    private IpVersion $version = IpVersion::V4;

    /** Premiere adresse du reseau, forme binaire ; le PHP la voit en pointe. */
    #[ORM\Column(name: 'network_address', type: 'ip_address')]
    #[Assert\NotBlank]
    #[Assert\Ip(version: Assert\Ip::ALL)]
    private ?string $networkAddress = null;

    /** Derniere adresse du reseau (diffusion en v4). Denormalisee pour l'indexation. */
    #[ORM\Column(name: 'last_address', type: 'ip_address')]
    private ?string $lastAddress = null;

    #[ORM\Column(name: 'prefix_length', type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 128)]
    private int $prefixLength = 24;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: SubnetStatus::class)]
    private SubnetStatus $status = SubnetStatus::Active;

    #[ORM\Column(type: 'ip_address', nullable: true)]
    private ?string $gateway = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dnsServers = null;

    #[ORM\Column(name: 'dhcp_enabled', type: Types::BOOLEAN)]
    private bool $dhcpEnabled = false;

    #[ORM\Column(name: 'dhcp_range_start', type: 'ip_address', nullable: true)]
    private ?string $dhcpRangeStart = null;

    #[ORM\Column(name: 'dhcp_range_end', type: 'ip_address', nullable: true)]
    private ?string $dhcpRangeEnd = null;

    /** @var Collection<int, IpAddress> */
    #[ORM\OneToMany(targetEntity: IpAddress::class, mappedBy: 'subnet', orphanRemoval: true)]
    #[ORM\OrderBy(['address' => 'ASC'])]
    private Collection $addresses;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->addresses = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getCidr();
    }

    /** La notation lisible, toujours derivee — jamais stockee, donc jamais desynchronisee. */
    public function getCidr(): string
    {
        return \sprintf('%s/%d', $this->networkAddress ?? '?', $this->prefixLength);
    }

    /** Un conteneur n'accueille que des sous-reseaux ; lui affecter des IP n'a pas de sens. */
    public function isContainer(): bool
    {
        return SubnetStatus::Container === $this->status;
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

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Subnet> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getVlan(): ?Vlan
    {
        return $this->vlan;
    }

    public function setVlan(?Vlan $vlan): self
    {
        $this->vlan = $vlan;

        return $this;
    }

    public function getVersion(): IpVersion
    {
        return $this->version;
    }

    public function setVersion(IpVersion $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getNetworkAddress(): ?string
    {
        return $this->networkAddress;
    }

    public function setNetworkAddress(?string $networkAddress): self
    {
        $this->networkAddress = $networkAddress;

        return $this;
    }

    public function getLastAddress(): ?string
    {
        return $this->lastAddress;
    }

    public function setLastAddress(?string $lastAddress): self
    {
        $this->lastAddress = $lastAddress;

        return $this;
    }

    public function getPrefixLength(): int
    {
        return $this->prefixLength;
    }

    public function setPrefixLength(int $prefixLength): self
    {
        $this->prefixLength = $prefixLength;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
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

    public function getStatus(): SubnetStatus
    {
        return $this->status;
    }

    public function setStatus(SubnetStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function setGateway(?string $gateway): self
    {
        $this->gateway = $gateway;

        return $this;
    }

    /** @return list<string>|null */
    public function getDnsServers(): ?array
    {
        return $this->dnsServers;
    }

    /** @param list<string>|null $dnsServers */
    public function setDnsServers(?array $dnsServers): self
    {
        $this->dnsServers = $dnsServers;

        return $this;
    }

    public function isDhcpEnabled(): bool
    {
        return $this->dhcpEnabled;
    }

    public function setDhcpEnabled(bool $dhcpEnabled): self
    {
        $this->dhcpEnabled = $dhcpEnabled;

        return $this;
    }

    public function getDhcpRangeStart(): ?string
    {
        return $this->dhcpRangeStart;
    }

    public function setDhcpRangeStart(?string $dhcpRangeStart): self
    {
        $this->dhcpRangeStart = $dhcpRangeStart;

        return $this;
    }

    public function getDhcpRangeEnd(): ?string
    {
        return $this->dhcpRangeEnd;
    }

    public function setDhcpRangeEnd(?string $dhcpRangeEnd): self
    {
        $this->dhcpRangeEnd = $dhcpRangeEnd;

        return $this;
    }

    /** @return Collection<int, IpAddress> */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }
}
