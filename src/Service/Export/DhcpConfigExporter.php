<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\IpStatus;
use App\Enum\IpVersion;
use App\Repository\IpAddressRepository;
use App\Repository\SubnetRepository;

/**
 * Génère une configuration ISC dhcpd à partir des réseaux porteurs d'une plage.
 *
 * Seuls les réseaux marqués DHCP et disposant d'un début et d'une fin de plage
 * produisent une déclaration : émettre un « subnet » sans « range » donnerait un
 * fichier accepté par dhcpd mais qui ne distribuerait rien, ce qui est pire
 * qu'une absence de déclaration.
 *
 * Les adresses documentées portant une MAC deviennent des réservations, afin
 * que ce que l'IPAM affiche corresponde à ce que le serveur attribue.
 */
final class DhcpConfigExporter
{
    public function __construct(
        private readonly SubnetRepository $subnets,
        private readonly IpAddressRepository $addresses,
    ) {
    }

    public function export(Organization $organization, string $domain): string
    {
        $lines = [
            '# Configuration ISC dhcpd — '.$organization->getName(),
            '# Générée par MSSub le '.date('d/m/Y H:i'),
            '# À relire avant mise en service : ce fichier ne porte ni authoritative,',
            '# ni durées de bail, ni options globales — elles relèvent du serveur.',
            '',
            \sprintf('option domain-name "%s";', rtrim($domain, '.')),
            '',
        ];

        $declared = 0;

        foreach ($this->subnets->findBy(['organization' => $organization], ['networkAddress' => 'ASC']) as $subnet) {
            if (!$this->isDistributable($subnet)) {
                continue;
            }

            ++$declared;
            $lines = [...$lines, ...$this->declare($subnet)];
        }

        if (0 === $declared) {
            $lines[] = '# Aucun réseau ne porte de plage DHCP complète.';
        }

        return implode("\n", $lines)."\n";
    }

    private function isDistributable(Subnet $subnet): bool
    {
        return $subnet->isDhcpEnabled()
            && !$subnet->isContainer()
            && IpVersion::V4 === $subnet->getVersion()
            && null !== $subnet->getDhcpRangeStart()
            && null !== $subnet->getDhcpRangeEnd();
    }

    /** @return list<string> */
    private function declare(Subnet $subnet): array
    {
        $mask = $this->netmask($subnet->getPrefixLength());

        $lines = [];
        if (null !== $subnet->getName()) {
            $lines[] = '# '.$subnet->getName();
        }

        $lines[] = \sprintf('subnet %s netmask %s {', $subnet->getNetworkAddress(), $mask);
        $lines[] = \sprintf('    range %s %s;', $subnet->getDhcpRangeStart(), $subnet->getDhcpRangeEnd());

        if (null !== $subnet->getGateway()) {
            $lines[] = \sprintf('    option routers %s;', $subnet->getGateway());
        }

        $dns = $subnet->getDnsServers();
        if (null !== $dns && [] !== $dns) {
            $lines[] = \sprintf('    option domain-name-servers %s;', implode(', ', $dns));
        }

        $lines[] = \sprintf('    option subnet-mask %s;', $mask);

        foreach ($this->reservations($subnet) as $reservation) {
            $lines[] = $reservation;
        }

        $lines[] = '}';
        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function reservations(Subnet $subnet): array
    {
        $lines = [];

        foreach ($this->addresses->findBySubnet($subnet) as $address) {
            $mac = $address->getMacAddress();
            if (null === $mac || IpStatus::Free === $address->getStatus()) {
                continue;
            }

            $name = $address->getHostname() ?? str_replace('.', '-', (string) $address->getAddress());

            $lines[] = '';
            $lines[] = \sprintf('    host %s {', $name);
            $lines[] = \sprintf('        hardware ethernet %s;', strtolower($mac));
            $lines[] = \sprintf('        fixed-address %s;', $address->getAddress());
            $lines[] = '    }';
        }

        return $lines;
    }

    /**
     * Le masque en notation pointée, dérivé du préfixe.
     *
     * Le décalage se fait sur l'entier 64 bits de PHP puis se tronque à 32 :
     * un /0 donne bien 0.0.0.0 et un /32 255.255.255.255, sans cas particulier.
     */
    private function netmask(int $prefix): string
    {
        return long2ip(-1 << (32 - $prefix) & 0xFFFFFFFF);
    }
}
