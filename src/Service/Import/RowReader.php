<?php

declare(strict_types=1);

namespace App\Service\Import;

use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lit un fichier CSV ou XLSX en lignes associatives.
 *
 * Les en-têtes sont normalisés — minuscules, sans accent, sans espace — et
 * passés par une table d'alias. Un plan d'adressage existant vient rarement
 * avec les colonnes exactement nommées comme on les attend, et refuser un
 * fichier parce qu'une colonne s'appelle « Réseau » au lieu de « cidr » ferait
 * perdre plus de temps que la normalisation n'en coûte.
 */
final class RowReader
{
    /** Noms acceptés pour chaque colonne connue. */
    private const ALIASES = [
        'cidr' => ['cidr', 'reseau', 'subnet', 'network', 'prefixe', 'plage'],
        'nom' => ['nom', 'name', 'libelle', 'designation'],
        'statut' => ['statut', 'status', 'etat'],
        'site' => ['site', 'agence', 'localisation'],
        'vlan' => ['vlan', 'vlanid', 'vlan_id', 'numerovlan'],
        'passerelle' => ['passerelle', 'gateway', 'gw', 'routeur'],
        'dns' => ['dns', 'serveursdns', 'dns_servers', 'resolveurs'],
        'dhcp' => ['dhcp', 'dhcpactif'],
        'dhcp_debut' => ['dhcpdebut', 'dhcp_debut', 'dhcpstart', 'debutdhcp'],
        'dhcp_fin' => ['dhcpfin', 'dhcp_fin', 'dhcpend', 'findhcp'],
        'description' => ['description', 'commentaire', 'note', 'remarque'],
        'adresse' => ['adresse', 'ip', 'address', 'adresseip'],
        'nom_hote' => ['nomhote', 'nom_hote', 'hostname', 'host', 'machine'],
        'mac' => ['mac', 'adressemac', 'macaddress', 'hardware'],
    ];

    /**
     * Lit un fichier, le format etant deduit du nom d'origine.
     *
     * Le nom transmis par le navigateur est le seul indice fiable : un fichier
     * televerse porte un nom temporaire sans extension, et le type MIME annonce
     * varie d'un poste a l'autre pour un meme CSV.
     *
     * @return list<array<string, string>>
     *
     * @throws \RuntimeException si le fichier est illisible ou vide
     */
    public function read(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));

        $rows = match ($extension) {
            'xlsx', 'xls', 'ods' => $this->readSpreadsheet($path),
            default => $this->readCsv($path),
        };

        if ([] === $rows) {
            throw new \RuntimeException('Le fichier ne contient aucune ligne exploitable.');
        }

        return $rows;
    }

    /** @return list<array<string, string>> */
    public function readCsv(string $path): array
    {
        $reader = Reader::createFromPath($path);
        $reader->setDelimiter($this->guessDelimiter($path));
        $reader->setHeaderOffset(0);

        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $rows[] = $this->normalizeRow($record);
        }

        return $rows;
    }

    /** @return list<array<string, string>> */
    public function readSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $raw = $sheet->toArray(null, true, false, false);

        if ([] === $raw) {
            return [];
        }

        $header = array_map(fn ($cell): string => $this->canonical((string) $cell), array_shift($raw));

        $rows = [];
        foreach ($raw as $line) {
            $row = [];
            foreach ($header as $index => $column) {
                if ('' === $column) {
                    continue;
                }
                $row[$column] = trim((string) ($line[$index] ?? ''));
            }

            if ('' !== implode('', $row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, string|null> $record
     *
     * @return array<string, string>
     */
    private function normalizeRow(array $record): array
    {
        $row = [];
        foreach ($record as $column => $value) {
            $canonical = $this->canonical((string) $column);
            if ('' !== $canonical) {
                $row[$canonical] = trim((string) $value);
            }
        }

        return $row;
    }

    /** Ramène un en-tête à son nom canonique, ou le laisse tel quel s'il est inconnu. */
    private function canonical(string $header): string
    {
        $slug = $this->slug($header);

        foreach (self::ALIASES as $canonical => $aliases) {
            if (\in_array($slug, $aliases, true)) {
                return $canonical;
            }
        }

        return $slug;
    }

    private function slug(string $value): string
    {
        $value = str_replace(
            ['à', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç'],
            ['a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c'],
            mb_strtolower(trim($value)),
        );

        // Le BOM d'Excel se colle au premier en-tête et le rendrait méconnaissable.
        $value = str_replace("\u{FEFF}", '', $value);

        return (string) preg_replace('/[^a-z0-9_]/', '', $value);
    }

    private function guessDelimiter(string $path): string
    {
        $firstLine = (string) fgets(fopen($path, 'r') ?: throw new \RuntimeException('Fichier illisible.'));

        // Un export français utilise le point-virgule, un export anglo-saxon la
        // virgule : on retient celui qui découpe le plus l'en-tête.
        return substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    }
}
