<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Organization;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\SubnetRepository;
use League\Csv\Bom;
use League\Csv\Writer;

/**
 * Export CSV du référentiel.
 *
 * Le séparateur est le point-virgule et le fichier porte une BOM UTF-8 : c'est
 * ce qu'attend Excel en configuration française, et un export qu'il faut
 * retravailler avant de pouvoir l'ouvrir n'est pas un export.
 *
 * Les colonnes sont volontairement celles que l'import sait relire : un export
 * doit pouvoir servir de gabarit de saisie et revenir tel quel.
 */
final class CsvExporter
{
    public const SUBNET_HEADER = [
        'cidr', 'nom', 'statut', 'site', 'vlan', 'passerelle', 'dns',
        'dhcp', 'dhcp_debut', 'dhcp_fin', 'description',
    ];

    public const ADDRESS_HEADER = [
        'reseau', 'adresse', 'statut', 'nom_hote', 'mac', 'description',
    ];

    public function __construct(
        private readonly SubnetRepository $subnets,
        private readonly IpAddressRepository $addresses,
    ) {
    }

    public function subnets(Organization $organization, ?Site $site = null): string
    {
        $csv = $this->writer(self::SUBNET_HEADER);

        foreach ($this->retained($organization, $site) as $subnet) {
            $csv->insertOne([
                $subnet->getCidr(),
                $subnet->getName() ?? '',
                $subnet->getStatus()->value,
                $subnet->getSite()?->getCode() ?? '',
                $subnet->getVlan()?->getNumber() ?? '',
                $subnet->getGateway() ?? '',
                implode(' ', $subnet->getDnsServers() ?? []),
                $subnet->isDhcpEnabled() ? 'oui' : 'non',
                $subnet->getDhcpRangeStart() ?? '',
                $subnet->getDhcpRangeEnd() ?? '',
                $subnet->getDescription() ?? '',
            ]);
        }

        return $csv->toString();
    }

    public function addresses(Organization $organization, ?Site $site = null): string
    {
        $csv = $this->writer(self::ADDRESS_HEADER);

        foreach ($this->retained($organization, $site) as $subnet) {
            foreach ($this->addresses->findBySubnet($subnet) as $address) {
                $csv->insertOne([
                    $subnet->getCidr(),
                    $address->getAddress(),
                    $address->getStatus()->value,
                    $address->getHostname() ?? '',
                    $address->getMacAddress() ?? '',
                    $address->getDescription() ?? '',
                ]);
            }
        }

        return $csv->toString();
    }

    /**
     * Les reseaux du perimetre, site effectif compris.
     *
     * @return list<Subnet>
     */
    private function retained(Organization $organization, ?Site $site): array
    {
        $all = $this->subnets->findBy(
            ['organization' => $organization],
            ['networkAddress' => 'ASC', 'prefixLength' => 'ASC'],
        );

        if (null === $site) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (Subnet $subnet): bool => $subnet->getEffectiveSite()?->getId() === $site->getId(),
        ));
    }

    /** @param list<string> $header */
    private function writer(array $header): Writer
    {
        $csv = Writer::fromString();
        $csv->setDelimiter(';');
        $csv->setOutputBOM(Bom::Utf8);
        $csv->insertOne($header);

        return $csv;
    }
}
