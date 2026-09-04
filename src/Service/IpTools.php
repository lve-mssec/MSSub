<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IpVersion;

/**
 * Arithmetique d'adresses, en manipulation d'octets pure.
 *
 * Volontairement sans bcmath ni gmp : une adresse est un tableau d'octets de
 * longueur fixe (4 ou 16), et masquer, incrementer ou comparer se fait octet par
 * octet. Le code marche donc a l'identique en IPv4 et en IPv6, sur 32 comme sur
 * 64 bits, sans extension supplementaire a installer en production.
 */
final class IpTools
{
    /** @throws \InvalidArgumentException si l'adresse est invalide */
    public function pack(string $ip): string
    {
        $packed = @inet_pton($ip);
        if (false === $packed) {
            throw new \InvalidArgumentException(\sprintf('Adresse IP invalide : "%s".', $ip));
        }

        return $packed;
    }

    public function unpack(string $packed): string
    {
        $ip = @inet_ntop($packed);
        if (false === $ip) {
            throw new \InvalidArgumentException('Sequence binaire d\'adresse invalide.');
        }

        return $ip;
    }

    public function version(string $ip): IpVersion
    {
        return 4 === \strlen($this->pack($ip)) ? IpVersion::V4 : IpVersion::V6;
    }

    /** Premiere adresse du reseau : l'adresse masquee par le prefixe. */
    public function networkAddress(string $ip, int $prefix): string
    {
        return $this->unpack($this->applyMask($this->pack($ip), $prefix, false));
    }

    /** Derniere adresse du reseau : diffusion en v4, derniere du prefixe en v6. */
    public function lastAddress(string $ip, int $prefix): string
    {
        return $this->unpack($this->applyMask($this->pack($ip), $prefix, true));
    }

    /**
     * Decoupe une notation CIDR et en derive tout ce que le modele stocke.
     *
     * @return array{network: string, last: string, prefix: int, version: IpVersion}
     *
     * @throws \InvalidArgumentException
     */
    public function parseCidr(string $cidr): array
    {
        $parts = explode('/', trim($cidr));
        if (2 !== \count($parts)) {
            throw new \InvalidArgumentException(\sprintf('Notation CIDR attendue, recu "%s".', $cidr));
        }

        [$ip, $prefixText] = $parts;
        if (!ctype_digit($prefixText)) {
            throw new \InvalidArgumentException(\sprintf('Longueur de prefixe invalide : "%s".', $prefixText));
        }

        $version = $this->version($ip);
        $prefix = (int) $prefixText;
        if ($prefix < 0 || $prefix > $version->maxPrefix()) {
            throw new \InvalidArgumentException(\sprintf('Prefixe /%d hors bornes pour %s.', $prefix, $version->label()));
        }

        return [
            'network' => $this->networkAddress($ip, $prefix),
            'last' => $this->lastAddress($ip, $prefix),
            'prefix' => $prefix,
            'version' => $version,
        ];
    }

    /** Compare deux adresses comme des nombres : -1, 0 ou 1. */
    public function compare(string $a, string $b): int
    {
        $pa = $this->pack($a);
        $pb = $this->pack($b);
        if (\strlen($pa) !== \strlen($pb)) {
            throw new \InvalidArgumentException('Comparaison impossible entre IPv4 et IPv6.');
        }

        return strcmp($pa, $pb) <=> 0;
    }

    public function contains(string $network, int $prefix, string $ip): bool
    {
        $first = $this->networkAddress($network, $prefix);
        $last = $this->lastAddress($network, $prefix);

        return $this->compare($ip, $first) >= 0 && $this->compare($ip, $last) <= 0;
    }

    /** L'adresse suivante, ou null si on est au bout de l'espace. */
    public function next(string $ip): ?string
    {
        $bytes = $this->pack($ip);
        for ($i = \strlen($bytes) - 1; $i >= 0; --$i) {
            $value = \ord($bytes[$i]);
            if ($value < 255) {
                $bytes[$i] = \chr($value + 1);

                return $this->unpack($bytes);
            }
            $bytes[$i] = "\x00";
        }

        return null; // debordement : il n'y a pas d'adresse au-dela.
    }

    /**
     * Nombre d'adresses d'un prefixe, en chaine.
     *
     * Un /8 IPv4 tient dans un entier, un /64 IPv6 non : le resultat est rendu
     * en chaine pour rester exact dans les deux cas.
     */
    public function sizeOf(int $prefix, IpVersion $version): string
    {
        $hostBits = $version->maxPrefix() - $prefix;
        if ($hostBits <= 62) {
            return (string) (1 << $hostBits);
        }

        // Doublement decimal a la main, au-dela de ce qu'un int peut porter.
        $total = '1';
        for ($i = 0; $i < $hostBits; ++$i) {
            $total = $this->doubleDecimal($total);
        }

        return $total;
    }

    /** Masque les bits d'hote : a zero pour la premiere adresse, a un pour la derniere. */
    private function applyMask(string $packed, int $prefix, bool $fill): string
    {
        $length = \strlen($packed);
        if ($prefix < 0 || $prefix > 8 * $length) {
            throw new \InvalidArgumentException(\sprintf('Prefixe /%d incompatible avec cette adresse.', $prefix));
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        for ($i = $fullBytes; $i < $length; ++$i) {
            if ($i === $fullBytes && 0 !== $remainingBits) {
                $mask = 0xFF << (8 - $remainingBits) & 0xFF;
                $byte = \ord($packed[$i]) & $mask;
                $packed[$i] = \chr($fill ? $byte | (~$mask & 0xFF) : $byte);
                continue;
            }
            $packed[$i] = $fill ? "\xFF" : "\x00";
        }

        return $packed;
    }

    private function doubleDecimal(string $number): string
    {
        $result = '';
        $carry = 0;
        for ($i = \strlen($number) - 1; $i >= 0; --$i) {
            $digit = (int) $number[$i] * 2 + $carry;
            $carry = intdiv($digit, 10);
            $result = ($digit % 10).$result;
        }

        return $carry > 0 ? $carry.$result : $result;
    }
}
