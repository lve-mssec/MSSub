<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\IpAddress;
use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\AuditAction;
use App\Enum\IpStatus;
use App\Enum\SubnetStatus;
use App\Exception\AllocationException;
use App\Repository\SiteRepository;
use App\Repository\SubnetRepository;
use App\Repository\VlanRepository;
use App\Service\AllocationService;
use App\Service\AuditRecorder;
use App\Service\IpTools;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Charge un plan d'adressage existant depuis un tableur.
 *
 * Deux partis pris gouvernent tout le reste.
 *
 * D'abord, la simulation est le mode normal : un import qui se découvre faux
 * après écriture coûte bien plus cher que deux passes. Le mode réel n'écrit
 * rien si la simulation a relevé la moindre erreur.
 *
 * Ensuite, une ligne fautive n'interrompt pas le lot. Un fichier de plusieurs
 * centaines de réseaux comporte presque toujours quelques scories ; s'arrêter à
 * la première obligerait à recommencer autant de fois qu'il y a d'erreurs. Tout
 * ce qui est valide est retenu, le reste est listé avec son numéro de ligne.
 */
final class PlanImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubnetRepository $subnets,
        private readonly SiteRepository $sites,
        private readonly VlanRepository $vlans,
        private readonly AllocationService $allocation,
        private readonly IpTools $ip,
        private readonly AuditRecorder $audit,
    ) {
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public function importSubnets(array $rows, Organization $organization, bool $dryRun): ImportReport
    {
        $report = new ImportReport($dryRun);
        $pending = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 pour l'en-tête, +1 pour compter à partir de 1
            $cidr = trim($row['cidr'] ?? '');

            if ('' === $cidr) {
                $report->error($line, '', 'Colonne « cidr » absente ou vide.');
                continue;
            }

            try {
                $parsed = $this->ip->parseCidr($cidr);
            } catch (\InvalidArgumentException $e) {
                $report->error($line, $cidr, $e->getMessage());
                continue;
            }

            $key = $parsed['network'].'/'.$parsed['prefix'];
            if (isset($pending[$key])) {
                $report->error($line, $cidr, \sprintf('Déjà présent ligne %d du fichier.', $pending[$key]));
                continue;
            }
            $pending[$key] = $line;

            $existing = $this->subnets->findOneBy([
                'organization' => $organization,
                'networkAddress' => $parsed['network'],
                'prefixLength' => $parsed['prefix'],
            ]);

            if (null === $existing) {
                try {
                    // Le moteur d'allocation refuse un chevauchement partiel et
                    // désigne le parent : l'import obéit aux mêmes règles que
                    // la saisie manuelle, sans porte dérobée.
                    $prepared = $this->allocation->prepare($organization, $cidr);
                } catch (AllocationException $e) {
                    $report->error($line, $cidr, $e->getMessage());
                    continue;
                }

                $subnet = (new Subnet())
                    ->setOrganization($organization)
                    ->setVersion($parsed['version'])
                    ->setNetworkAddress($parsed['network'])
                    ->setLastAddress($parsed['last'])
                    ->setPrefixLength($parsed['prefix'])
                    ->setParent($prepared['parent']);

                $this->fill($subnet, $row, $organization, $report, $line);

                if (!$dryRun) {
                    $this->em->persist($subnet);
                }
                $report->addCreated();
                continue;
            }

            $before = $this->snapshot($existing);
            $this->fill($existing, $row, $organization, $report, $line);

            if ($before === $this->snapshot($existing)) {
                $report->addUnchanged();
            } else {
                $report->addUpdated();
            }
        }

        $this->finish($report, $organization, 'réseaux', \count($rows));

        return $report;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public function importAddresses(array $rows, Organization $organization, bool $dryRun): ImportReport
    {
        $report = new ImportReport($dryRun);
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $value = trim($row['adresse'] ?? '');

            if ('' === $value) {
                $report->error($line, '', 'Colonne « adresse » absente ou vide.');
                continue;
            }

            try {
                $this->ip->pack($value);
            } catch (\InvalidArgumentException $e) {
                $report->error($line, $value, $e->getMessage());
                continue;
            }

            // Le réseau d'accueil est déduit de l'adresse : demander à
            // l'opérateur de le répéter sur chaque ligne serait une source
            // d'incohérence de plus.
            $subnet = $this->subnets->findContaining($value, $organization);

            if (null === $subnet) {
                $report->error($line, $value, 'Aucun réseau déclaré ne contient cette adresse.');
                continue;
            }

            if ($subnet->isContainer()) {
                $report->error($line, $value, \sprintf('%s est un conteneur : il n\'accueille pas d\'adresses.', $subnet->getCidr()));
                continue;
            }

            if (isset($seen[$value])) {
                $report->error($line, $value, \sprintf('Déjà présente ligne %d du fichier.', $seen[$value]));
                continue;
            }
            $seen[$value] = $line;

            $existing = $this->em->getRepository(IpAddress::class)
                ->findOneBy(['subnet' => $subnet, 'address' => $value]);

            if (null === $existing) {
                $address = (new IpAddress())->setSubnet($subnet)->setAddress($value);
                $this->fillAddress($address, $row);

                if (!$dryRun) {
                    $this->em->persist($address);
                }
                $report->addCreated();
                continue;
            }

            $before = $this->snapshotAddress($existing);
            $this->fillAddress($existing, $row);

            if ($before === $this->snapshotAddress($existing)) {
                $report->addUnchanged();
            } else {
                $report->addUpdated();
            }
        }

        $this->finish($report, $organization, 'adresses', \count($rows));

        return $report;
    }

    /** @param array<string, string> $row */
    private function fill(Subnet $subnet, array $row, Organization $organization, ImportReport $report, int $line): void
    {
        if ('' !== ($row['nom'] ?? '')) {
            $subnet->setName($row['nom']);
        }

        if ('' !== ($row['description'] ?? '')) {
            $subnet->setDescription($row['description']);
        }

        $status = SubnetStatus::tryFrom(strtolower(trim($row['statut'] ?? '')));
        if (null !== $status) {
            $subnet->setStatus($status);
        } elseif ('' !== ($row['statut'] ?? '')) {
            $report->warning($line, $row['statut'], 'Statut inconnu ; valeur ignorée.');
        }

        if ('' !== ($row['site'] ?? '')) {
            $site = $this->sites->findOneBy(['organization' => $organization, 'code' => strtoupper($row['site'])])
                ?? $this->sites->findOneBy(['organization' => $organization, 'name' => $row['site']]);

            if (null === $site) {
                $report->warning($line, $row['site'], 'Site inconnu ; rattachement ignoré.');
            } else {
                $subnet->setSite($site);
            }
        }

        if ('' !== ($row['vlan'] ?? '') && ctype_digit($row['vlan'])) {
            $vlan = $this->vlans->findOneBy(['number' => (int) $row['vlan']]);
            if (null === $vlan) {
                $report->warning($line, $row['vlan'], 'VLAN inconnu ; rattachement ignoré.');
            } else {
                $subnet->setVlan($vlan);
            }
        }

        foreach (['passerelle' => 'setGateway', 'dhcp_debut' => 'setDhcpRangeStart', 'dhcp_fin' => 'setDhcpRangeEnd'] as $column => $setter) {
            $candidate = trim($row[$column] ?? '');
            if ('' === $candidate) {
                continue;
            }

            try {
                $this->ip->pack($candidate);
                $subnet->{$setter}($candidate);
            } catch (\InvalidArgumentException) {
                $report->warning($line, $candidate, \sprintf('Colonne « %s » : adresse invalide ; valeur ignorée.', $column));
            }
        }

        if ('' !== ($row['dns'] ?? '')) {
            $servers = array_values(array_filter(preg_split('/[\s,;]+/', $row['dns']) ?: [], 'strlen'));
            $subnet->setDnsServers([] === $servers ? null : $servers);
        }

        if ('' !== ($row['dhcp'] ?? '')) {
            $subnet->setDhcpEnabled(\in_array(strtolower($row['dhcp']), ['oui', 'yes', '1', 'true', 'vrai', 'x'], true));
        }
    }

    /** @param array<string, string> $row */
    private function fillAddress(IpAddress $address, array $row): void
    {
        if ('' !== ($row['nom_hote'] ?? '')) {
            $address->setHostname($row['nom_hote']);
        }

        if ('' !== ($row['mac'] ?? '')) {
            $address->setMacAddress($row['mac']);
        }

        if ('' !== ($row['description'] ?? '')) {
            $address->setDescription($row['description']);
        }

        $status = IpStatus::tryFrom(strtolower(trim($row['statut'] ?? '')));
        $address->setStatus($status ?? $address->getStatus());
    }

    /** @return array<string, mixed> */
    private function snapshot(Subnet $subnet): array
    {
        return [
            $subnet->getName(), $subnet->getDescription(), $subnet->getStatus()->value,
            $subnet->getSite()?->getId(), $subnet->getVlan()?->getId(), $subnet->getGateway(),
            $subnet->getDnsServers(), $subnet->isDhcpEnabled(),
            $subnet->getDhcpRangeStart(), $subnet->getDhcpRangeEnd(),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotAddress(IpAddress $address): array
    {
        return [
            $address->getStatus()->value, $address->getHostname(),
            $address->getMacAddress(), $address->getDescription(),
        ];
    }

    /**
     * Écrit, ou pas.
     *
     * Une erreur suffit à tout retenir : importer la moitié d'un fichier
     * laisserait un référentiel dans un état que personne ne saurait décrire.
     */
    private function finish(ImportReport $report, Organization $organization, string $kind, int $lines): void
    {
        if ($report->dryRun) {
            $this->em->clear();

            return;
        }

        if ($report->hasErrors()) {
            $this->em->clear();

            return;
        }

        $this->audit->record(
            $this->em,
            AuditAction::Import,
            $organization,
            null,
            \sprintf('Import de %s : %d ligne(s), %d créée(s), %d mise(s) à jour', $kind, $lines, $report->getCreated(), $report->getUpdated()),
        );

        $this->em->flush();
    }
}
