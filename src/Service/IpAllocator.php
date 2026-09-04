<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IpVersion;

/**
 * Recherche d'adresses libres dans un reseau.
 *
 * Pur lui aussi : il recoit la liste des adresses prises, pas un repository.
 *
 * Le parti pris du modele est que les adresses libres ne sont pas stockees —
 * un /16 en materialiserait 65 536 pour ne rien dire. « Libre » se deduit donc
 * par difference entre la plage du reseau et les lignes presentes.
 */
final class IpAllocator
{
    public function __construct(private readonly IpTools $ip)
    {
    }

    /**
     * Les adresses attribuables d'un reseau, bornes comprises ou non.
     *
     * En IPv4, la premiere adresse designe le reseau et la derniere la
     * diffusion : ni l'une ni l'autre ne s'attribue a une machine. Les /31
     * (liaisons point a point, RFC 3021) et les /32 (hote unique) echappent a
     * cette regle, faute de quoi ils n'auraient aucune adresse utilisable.
     * En IPv6, il n'y a pas d'adresse de diffusion : tout le prefixe est offert.
     *
     * @return array{0: string, 1: string}|null [premiere, derniere], ou null si aucune
     */
    public function usableRange(string $network, int $prefix): ?array
    {
        $version = $this->ip->version($network);
        $first = $this->ip->networkAddress($network, $prefix);
        $last = $this->ip->lastAddress($network, $prefix);

        if (IpVersion::V6 === $version || $prefix >= $version->maxPrefix() - 1) {
            return [$first, $last];
        }

        $firstUsable = $this->ip->next($first);
        if (null === $firstUsable) {
            return null;
        }

        return [$firstUsable, $this->previous($last)];
    }

    /**
     * Les premieres adresses libres, dans l'ordre.
     *
     * La liste des adresses prises est parcourue une seule fois, en parallele du
     * curseur : le cout depend du nombre d'adresses documentees, pas de la
     * taille du reseau. Chercher dans un /16 vide coute donc le meme prix que
     * dans un /30.
     *
     * @param list<string> $taken adresses occupees, dans n'importe quel ordre
     *
     * @return list<string>
     */
    public function findFreeAddresses(string $network, int $prefix, array $taken, int $limit = 10): array
    {
        $range = $this->usableRange($network, $prefix);
        if (null === $range || $limit < 1) {
            return [];
        }

        [$cursor, $last] = $range;
        $sorted = $this->sortAddresses($taken);
        $free = [];
        $index = 0;
        $count = \count($sorted);

        while (\count($free) < $limit && $this->ip->compare($cursor, $last) <= 0) {
            // On avance dans les adresses prises tant qu'elles sont derriere le curseur.
            while ($index < $count && $this->ip->compare($sorted[$index], $cursor) < 0) {
                ++$index;
            }

            if ($index < $count && 0 === $this->ip->compare($sorted[$index], $cursor)) {
                ++$index;
            } else {
                $free[] = $cursor;
            }

            $next = $this->ip->next($cursor);
            if (null === $next) {
                break;
            }
            $cursor = $next;
        }

        return $free;
    }

    public function findFirstFreeAddress(string $network, int $prefix, array $taken): ?string
    {
        return $this->findFreeAddresses($network, $prefix, $taken, 1)[0] ?? null;
    }

    /**
     * Taux d'occupation d'un reseau.
     *
     * Le total est rendu en chaine parce qu'un /64 IPv6 depasse ce qu'un entier
     * PHP peut porter ; le pourcentage n'a alors plus grand sens et vaut 0.
     *
     * @return array{used: int, usable: string, percent: float}
     */
    public function usage(string $network, int $prefix, int $usedCount): array
    {
        $version = $this->ip->version($network);
        $total = $this->ip->sizeOf($prefix, $version);

        // Deux adresses partent en reseau et diffusion, sauf sur les tout petits prefixes v4.
        $usable = $total;
        if (IpVersion::V4 === $version && $prefix < $version->maxPrefix() - 1) {
            $usable = (string) (max(0, (int) $total - 2));
        }

        $percent = 0.0;
        if (is_numeric($usable) && (float) $usable > 0.0 && \strlen($usable) < 16) {
            $percent = round($usedCount / (float) $usable * 100, 2);
        }

        return ['used' => $usedCount, 'usable' => $usable, 'percent' => $percent];
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private function sortAddresses(array $addresses): array
    {
        $packed = [];
        foreach ($addresses as $address) {
            $packed[] = $this->ip->pack($address);
        }
        sort($packed);

        return array_map($this->ip->unpack(...), $packed);
    }

    /** L'adresse precedente : le miroir de IpTools::next(), en propagation d'emprunt. */
    private function previous(string $ip): string
    {
        $bytes = $this->ip->pack($ip);
        for ($i = \strlen($bytes) - 1; $i >= 0; --$i) {
            $value = \ord($bytes[$i]);
            if ($value > 0) {
                $bytes[$i] = \chr($value - 1);

                return $this->ip->unpack($bytes);
            }
            $bytes[$i] = "\xFF";
        }

        return $ip;
    }
}
