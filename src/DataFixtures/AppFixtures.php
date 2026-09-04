<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Device;
use App\Entity\IpAddress;
use App\Entity\NetworkInterface;
use App\Entity\Organization;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Entity\User;
use App\Entity\Vlan;
use App\Enum\AuthSource;
use App\Enum\DeviceType;
use App\Enum\IpStatus;
use App\Enum\SubnetStatus;
use App\Service\IpTools;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de demonstration : un plan d'adressage credible, pas trois lignes.
 *
 * Il sert a voir le comportement reel de l'application — arborescence sur
 * plusieurs niveaux, reseaux partiellement remplis, plage DHCP, liaison /30 —
 * la ou un jeu minimal donnerait une fausse impression de simplicite.
 */
final class AppFixtures extends Fixture
{
    /** Mot de passe du compte de demonstration, valable en developpement seulement. */
    private const DEMO_PASSWORD = 'MotDePasseDeTest2026';

    public function __construct(
        private readonly IpTools $ip,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = (new User())
            ->setUsername('loic')
            ->setDisplayName('Loïc Veirman')
            ->setEmail('loic.veirman@mssec.fr')
            ->setAuthSource(AuthSource::Local)
            ->setRoles([User::ROLE_ADMIN]);
        $admin->setPassword($this->hasher->hashPassword($admin, self::DEMO_PASSWORD));
        $manager->persist($admin);

        $mssec = (new Organization())
            ->setCode('MSSEC')
            ->setName('MSSEC')
            ->setDescription('Périmètre interne.');
        $manager->persist($mssec);

        $paris = $this->site($manager, $mssec, 'PAR', 'Paris — Siège', 'Paris');
        $lyon = $this->site($manager, $mssec, 'LYO', 'Lyon — Agence', 'Lyon');

        $vlanUsers = $this->vlan($manager, $paris, 110, 'Utilisateurs');
        $vlanServers = $this->vlan($manager, $paris, 120, 'Serveurs');
        $vlanDmz = $this->vlan($manager, $paris, 130, 'DMZ');
        $vlanLyon = $this->vlan($manager, $lyon, 210, 'Utilisateurs Lyon');

        // --- Arborescence : un espace privé, deux sites, des réseaux terminaux.
        $racine = $this->subnet($manager, $mssec, '10.0.0.0/8', 'Espace privé MSSEC', SubnetStatus::Container);

        $blocParis = $this->subnet($manager, $mssec, '10.10.0.0/16', 'Bloc Paris', SubnetStatus::Container, $racine, $paris);
        $blocLyon = $this->subnet($manager, $mssec, '10.20.0.0/16', 'Bloc Lyon', SubnetStatus::Container, $racine, $lyon);

        $lanUsers = $this->subnet($manager, $mssec, '10.10.10.0/24', 'LAN Utilisateurs', SubnetStatus::Active, $blocParis, $paris, $vlanUsers);
        $lanUsers->setGateway('10.10.10.254')
            ->setDnsServers(['10.10.20.10', '10.10.20.11'])
            ->setDhcpEnabled(true)
            ->setDhcpRangeStart('10.10.10.100')
            ->setDhcpRangeEnd('10.10.10.200');

        $lanServers = $this->subnet($manager, $mssec, '10.10.20.0/24', 'LAN Serveurs', SubnetStatus::Active, $blocParis, $paris, $vlanServers);
        $lanServers->setGateway('10.10.20.254')->setDnsServers(['10.10.20.10']);

        $dmz = $this->subnet($manager, $mssec, '10.10.30.0/26', 'DMZ', SubnetStatus::Active, $blocParis, $paris, $vlanDmz);
        $dmz->setGateway('10.10.30.62');

        $this->subnet($manager, $mssec, '10.10.40.0/24', 'Réservé — extension', SubnetStatus::Reserved, $blocParis, $paris);

        $lanLyon = $this->subnet($manager, $mssec, '10.20.10.0/24', 'LAN Utilisateurs Lyon', SubnetStatus::Active, $blocLyon, $lyon, $vlanLyon);
        $lanLyon->setGateway('10.20.10.254');

        $liaisons = $this->subnet($manager, $mssec, '192.168.0.0/16', 'Infrastructure technique', SubnetStatus::Container);
        $liaison = $this->subnet($manager, $mssec, '192.168.100.0/30', 'Liaison opérateur Paris', SubnetStatus::Active, $liaisons, $paris);

        // --- Équipements et interfaces.
        $firewall = $this->device($manager, $paris, 'fw-par-01', DeviceType::Firewall, 'Fortinet', 'FortiGate 100F');
        $switch = $this->device($manager, $paris, 'sw-par-01', DeviceType::Switch_, 'Cisco', 'Catalyst 9200');
        $dc01 = $this->device($manager, $paris, 'srv-dc01', DeviceType::Server, 'Dell', 'PowerEdge R650');

        $fwLan = $this->interface($manager, $firewall, 'port1', '00:1B:44:11:3A:B7');
        $fwWan = $this->interface($manager, $firewall, 'wan1', '00:1B:44:11:3A:B8');
        $dcNic = $this->interface($manager, $dc01, 'eth0', '3C:52:82:0D:11:04');

        // --- Adresses documentées.
        $this->address($manager, $lanServers, '10.10.20.10', IpStatus::Used, 'srv-dc01', $dcNic, 'Contrôleur de domaine principal');
        $this->address($manager, $lanServers, '10.10.20.11', IpStatus::Used, 'srv-dc02', null, 'Contrôleur de domaine secondaire');
        $this->address($manager, $lanServers, '10.10.20.20', IpStatus::Used, 'srv-app01', null, 'Applicatif métier');
        $this->address($manager, $lanServers, '10.10.20.30', IpStatus::Reserved, null, null, 'Réservé pour la sauvegarde');
        $this->address($manager, $lanServers, '10.10.20.254', IpStatus::Gateway, 'gw-serveurs', $fwLan, 'Passerelle du VLAN 120');

        $this->address($manager, $lanUsers, '10.10.10.254', IpStatus::Gateway, 'gw-users', null, 'Passerelle du VLAN 110');
        $this->address($manager, $lanUsers, '10.10.10.10', IpStatus::Used, 'sw-par-01', null, 'Administration du commutateur');
        $this->address($manager, $lanUsers, '10.10.10.100', IpStatus::Dhcp, null, null, 'Début de plage DHCP');
        $this->address($manager, $lanUsers, '10.10.10.101', IpStatus::Dhcp, null, null, null);
        $this->address($manager, $lanUsers, '10.10.10.50', IpStatus::Free, null, null, 'Libérée après décommissionnement');

        $this->address($manager, $dmz, '10.10.30.10', IpStatus::Used, 'srv-web01', null, 'Portail public');
        $this->address($manager, $dmz, '10.10.30.62', IpStatus::Gateway, 'gw-dmz', null, null);

        $this->address($manager, $liaison, '192.168.100.1', IpStatus::Used, null, $fwWan, 'Côté MSSEC');
        $this->address($manager, $liaison, '192.168.100.2', IpStatus::Used, null, null, 'Côté opérateur');

        $this->address($manager, $lanLyon, '10.20.10.254', IpStatus::Gateway, 'gw-lyon', null, null);

        unset($switch);

        $manager->flush();
    }

    private function site(ObjectManager $m, Organization $org, string $code, string $name, string $city): Site
    {
        $site = (new Site())
            ->setOrganization($org)
            ->setCode($code)
            ->setName($name)
            ->setCity($city)
            ->setCountry('FR');
        $m->persist($site);

        return $site;
    }

    private function vlan(ObjectManager $m, Site $site, int $number, string $name): Vlan
    {
        $vlan = (new Vlan())->setSite($site)->setNumber($number)->setName($name);
        $m->persist($vlan);

        return $vlan;
    }

    private function subnet(
        ObjectManager $m,
        Organization $org,
        string $cidr,
        string $name,
        SubnetStatus $status,
        ?Subnet $parent = null,
        ?Site $site = null,
        ?Vlan $vlan = null,
    ): Subnet {
        $parsed = $this->ip->parseCidr($cidr);

        $subnet = (new Subnet())
            ->setOrganization($org)
            ->setParent($parent)
            ->setSite($site)
            ->setVlan($vlan)
            ->setVersion($parsed['version'])
            ->setNetworkAddress($parsed['network'])
            ->setLastAddress($parsed['last'])
            ->setPrefixLength($parsed['prefix'])
            ->setName($name)
            ->setStatus($status);
        $m->persist($subnet);

        return $subnet;
    }

    private function device(ObjectManager $m, Site $site, string $name, DeviceType $type, string $vendor, string $model): Device
    {
        $device = (new Device())
            ->setSite($site)
            ->setName($name)
            ->setType($type)
            ->setVendor($vendor)
            ->setModel($model);
        $m->persist($device);

        return $device;
    }

    private function interface(ObjectManager $m, Device $device, string $name, string $mac): NetworkInterface
    {
        $interface = (new NetworkInterface())->setDevice($device)->setName($name)->setMacAddress($mac);
        $m->persist($interface);

        return $interface;
    }

    private function address(
        ObjectManager $m,
        Subnet $subnet,
        string $address,
        IpStatus $status,
        ?string $hostname,
        ?NetworkInterface $interface,
        ?string $description,
    ): void {
        $m->persist(
            (new IpAddress())
                ->setSubnet($subnet)
                ->setAddress($address)
                ->setStatus($status)
                ->setHostname($hostname)
                ->setInterface($interface)
                ->setDescription($description),
        );
    }
}
