<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\IpVersion;
use App\Repository\IpAddressRepository;
use App\Repository\SubnetRepository;
use App\Service\IpTools;

/**
 * Génère des fragments de zone DNS à partir des adresses documentées.
 *
 * Volontairement des fragments, pas des zones complètes : ni SOA ni NS ne sont
 * émis. Le numéro de série et les serveurs faisant autorité appartiennent à
 * l'infrastructure DNS, pas à l'IPAM — les inventer ici produirait un fichier
 * d'apparence valide qui écraserait la configuration réelle à la première
 * inclusion. Le fragment s'inclut dans une zone existante.
 */
final class DnsZoneExporter
{
    public function __construct(
        private readonly SubnetRepository $subnets,
        private readonly IpAddressRepository $addresses,
        private readonly IpTools $ip,
    ) {
    }

    /** Enregistrements directs : nom d'hôte vers adresse. */
    public function forward(Organization $organization, string $domain): string
    {
        $lines = $this->header(\sprintf('Enregistrements directs — %s', $domain));
        $seen = [];

        foreach ($this->subnetsOf($organization) as $subnet) {
            $rows = [];

            foreach ($this->addresses->findBySubnet($subnet) as $address) {
                $hostname = $this->hostname($address->getHostname(), $domain);
                if (null === $hostname) {
                    continue;
                }

                // Un même nom sur deux adresses est légitime (répartition de
                // charge), un même nom deux fois sur la même adresse ne l'est pas.
                $key = $hostname.'|'.$address->getAddress();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $type = IpVersion::V6 === $this->ip->version((string) $address->getAddress()) ? 'AAAA' : 'A';
                $rows[] = \sprintf('%-30s IN  %-5s %s', $hostname, $type, $address->getAddress());
            }

            if ([] !== $rows) {
                $lines[] = \sprintf('; %s%s', $subnet->getCidr(), null !== $subnet->getName() ? ' — '.$subnet->getName() : '');
                $lines = [...$lines, ...$rows, ''];
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Enregistrements inversés d'un réseau, dans sa zone in-addr.arpa.
     *
     * Le nom relatif est le dernier octet pour un /24 et plus fin ; au-delà, la
     * délégation classée (RFC 2317) sort du cadre d'un IPAM et le fragment est
     * émis en noms absolus.
     */
    public function reverse(Subnet $subnet, string $domain): string
    {
        $lines = $this->header(\sprintf('Enregistrements inversés — %s', $subnet->getCidr()));

        if (IpVersion::V4 !== $subnet->getVersion()) {
            $lines[] = '; IPv6 : hors périmètre de cette version.';

            return implode("\n", $lines)."\n";
        }

        $lines[] = \sprintf('; Zone attendue : %s', $this->reverseZone($subnet));
        $lines[] = '';

        foreach ($this->addresses->findBySubnet($subnet) as $address) {
            $hostname = $this->hostname($address->getHostname(), $domain);
            if (null === $hostname) {
                continue;
            }

            $lines[] = \sprintf('%-8s IN  PTR   %s', $this->reverseLabel($subnet, (string) $address->getAddress()), $hostname);
        }

        return implode("\n", $lines)."\n";
    }

    public function reverseZone(Subnet $subnet): string
    {
        $octets = explode('.', (string) $subnet->getNetworkAddress());
        $prefix = $subnet->getPrefixLength();

        $keep = match (true) {
            $prefix >= 24 => 3,
            $prefix >= 16 => 2,
            default => 1,
        };

        return implode('.', array_reverse(\array_slice($octets, 0, $keep))).'.in-addr.arpa.';
    }

    /** Le nom relatif d'une adresse dans sa zone inversée. */
    private function reverseLabel(Subnet $subnet, string $address): string
    {
        $octets = explode('.', $address);
        $prefix = $subnet->getPrefixLength();

        $drop = match (true) {
            $prefix >= 24 => 3,
            $prefix >= 16 => 2,
            default => 1,
        };

        return implode('.', array_reverse(\array_slice($octets, $drop)));
    }

    private function hostname(?string $hostname, string $domain): ?string
    {
        if (null === $hostname || '' === trim($hostname)) {
            return null;
        }

        $hostname = trim($hostname);

        // Un nom déjà qualifié est laissé tel quel, juste terminé par un point.
        if (str_contains($hostname, '.')) {
            return rtrim($hostname, '.').'.';
        }

        return \sprintf('%s.%s.', $hostname, rtrim($domain, '.'));
    }

    /** @return list<string> */
    private function header(string $title): array
    {
        return [
            '; '.$title,
            '; Généré par MSSub le '.date('d/m/Y H:i'),
            '; Fragment à inclure : ni SOA ni NS ne sont émis, ils appartiennent à la zone.',
            '',
        ];
    }

    /** @return list<Subnet> */
    private function subnetsOf(Organization $organization): array
    {
        return $this->subnets->findBy(
            ['organization' => $organization],
            ['networkAddress' => 'ASC', 'prefixLength' => 'ASC'],
        );
    }
}
