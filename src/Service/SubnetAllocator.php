<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IpVersion;

/**
 * Recherche d'espace libre dans un bloc : « ou puis-je poser un /26 ? ».
 *
 * Ce calculateur est volontairement pur — il recoit des plages, pas des
 * entites ni un repository. C'est ce qui permet de le tester sur des cas
 * tordus (blocs jointifs, trous d'un seul reseau, bloc plein) sans base de
 * donnees, et de le reutiliser tel quel sur des donnees venues d'un import.
 *
 * @phpstan-type Range array{0: string, 1: string}
 */
final class SubnetAllocator
{
    public function __construct(private readonly IpTools $ip)
    {
    }

    /**
     * Les emplacements libres pour un prefixe donne, dans l'ordre du plan.
     *
     * Un sous-reseau ne peut pas commencer n'importe ou : un /26 se pose sur un
     * multiple de 64 adresses. Le curseur avance donc de bloc en bloc, jamais
     * d'adresse en adresse, et saute directement apres tout occupant rencontre.
     *
     * @param list<Range> $allocated plages deja prises, sous forme [premiere, derniere]
     *
     * @return list<string> notations CIDR, au plus $limit
     */
    public function findFreeBlocks(
        string $containerNetwork,
        int $containerPrefix,
        int $wantedPrefix,
        array $allocated,
        int $limit = 10,
    ): array {
        $version = $this->ip->version($containerNetwork);

        // Un prefixe plus court que son conteneur ne peut pas tenir dedans.
        if ($wantedPrefix < $containerPrefix || $wantedPrefix > $version->maxPrefix()) {
            return [];
        }

        $start = $this->ip->networkAddress($containerNetwork, $containerPrefix);
        $end = $this->ip->lastAddress($containerNetwork, $containerPrefix);
        $ranges = $this->normalize($allocated, $version);

        $found = [];
        $cursor = $start;

        while (\count($found) < $limit) {
            $candidateLast = $this->ip->lastAddress($cursor, $wantedPrefix);

            // Le candidat deborderait du conteneur : il n'y a plus de place.
            if ($this->ip->compare($candidateLast, $end) > 0) {
                break;
            }

            $blocker = $this->firstOverlapping($ranges, $cursor, $candidateLast);
            if (null === $blocker) {
                $found[] = \sprintf('%s/%d', $cursor, $wantedPrefix);
                $next = $this->ip->next($candidateLast);
            } else {
                // Inutile de tester les blocs couverts par l'occupant : on saute
                // directement apres lui, puis on se realigne sur la grille.
                $next = $this->ip->next($blocker[1]);
            }

            if (null === $next || $this->ip->compare($next, $end) > 0) {
                break;
            }

            $cursor = $this->alignUp($next, $wantedPrefix);
            if ($this->ip->compare($cursor, $end) > 0) {
                break;
            }
        }

        return $found;
    }

    /** Le premier emplacement libre, ou null si le bloc est plein. */
    public function findFirstFreeBlock(
        string $containerNetwork,
        int $containerPrefix,
        int $wantedPrefix,
        array $allocated,
    ): ?string {
        return $this->findFreeBlocks($containerNetwork, $containerPrefix, $wantedPrefix, $allocated, 1)[0] ?? null;
    }

    /**
     * Le plus grand prefixe libre disponible, ou null si le bloc est plein.
     *
     * Utile pour repondre a « qu'est-ce qui reste ? » sans que l'utilisateur
     * ait a deviner une taille.
     */
    public function largestFreeBlock(string $containerNetwork, int $containerPrefix, array $allocated): ?string
    {
        $version = $this->ip->version($containerNetwork);
        for ($prefix = $containerPrefix; $prefix <= $version->maxPrefix(); ++$prefix) {
            $block = $this->findFirstFreeBlock($containerNetwork, $containerPrefix, $prefix, $allocated);
            if (null !== $block) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Vrai si la plage proposee mord sur une plage existante.
     *
     * @param list<Range> $allocated
     */
    public function overlaps(string $first, string $last, array $allocated): bool
    {
        return null !== $this->firstOverlapping(
            $this->normalize($allocated, $this->ip->version($first)),
            $first,
            $last,
        );
    }

    /**
     * Remonte l'adresse a la prochaine frontiere de bloc.
     *
     * Masquer suffit quand l'adresse est deja alignee ; sinon il faut passer au
     * bloc suivant, ce que donne « derniere adresse du bloc courant, puis une
     * de plus » — sans jamais manipuler de grand entier.
     */
    private function alignUp(string $ip, int $prefix): string
    {
        $aligned = $this->ip->networkAddress($ip, $prefix);
        if (0 === $this->ip->compare($aligned, $ip)) {
            return $ip;
        }

        return $this->ip->next($this->ip->lastAddress($aligned, $prefix)) ?? $ip;
    }

    /**
     * @param list<Range> $ranges deja triees
     *
     * @return Range|null
     */
    private function firstOverlapping(array $ranges, string $first, string $last): ?array
    {
        foreach ($ranges as $range) {
            // Les plages sont triees : au-dela, plus rien ne peut chevaucher.
            if ($this->ip->compare($range[0], $last) > 0) {
                return null;
            }
            if ($this->ip->compare($range[1], $first) >= 0) {
                return $range;
            }
        }

        return null;
    }

    /**
     * Ne garde que les plages de la bonne famille, et les trie.
     *
     * Melanger v4 et v6 dans une meme comparaison leverait une exception ; on
     * ecarte donc en amont ce qui n'appartient pas au conteneur.
     *
     * @param list<Range> $allocated
     *
     * @return list<Range>
     */
    private function normalize(array $allocated, IpVersion $version): array
    {
        $ranges = array_values(array_filter(
            $allocated,
            fn (array $range): bool => $this->ip->version($range[0]) === $version,
        ));

        usort($ranges, fn (array $a, array $b): int => $this->ip->compare($a[0], $b[0]));

        return $ranges;
    }
}
