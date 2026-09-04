<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Subnet;
use App\Enum\IpVersion;
use App\Exception\AllocationException;
use App\Repository\IpAddressRepository;
use App\Repository\SubnetRepository;

/**
 * La façade metier : elle branche les calculateurs purs sur la base.
 *
 * Toute la logique d'allocation vit dans SubnetAllocator et IpAllocator, qui
 * ne connaissent que des plages. Cette classe se contente de leur fournir
 * l'etat du referentiel et de traduire le resultat en refus explicites.
 */
final class AllocationService
{
    public function __construct(
        private readonly SubnetRepository $subnets,
        private readonly IpAddressRepository $addresses,
        private readonly SubnetAllocator $subnetAllocator,
        private readonly IpAllocator $ipAllocator,
        private readonly IpTools $ip,
    ) {
    }

    /**
     * Les prochains emplacements libres pour un prefixe donne dans un bloc.
     *
     * @return list<string> notations CIDR
     */
    public function freeSubnetsIn(Subnet $container, int $prefix, int $limit = 10): array
    {
        return $this->subnetAllocator->findFreeBlocks(
            (string) $container->getNetworkAddress(),
            $container->getPrefixLength(),
            $prefix,
            $this->allocatedRangesIn($container),
            $limit,
        );
    }

    /** @throws AllocationException si le bloc n'a plus de place */
    public function nextFreeSubnetIn(Subnet $container, int $prefix): string
    {
        $free = $this->freeSubnetsIn($container, $prefix, 1);
        if ([] === $free) {
            throw AllocationException::containerIsFull($container, $prefix);
        }

        return $free[0];
    }

    public function largestFreeSubnetIn(Subnet $container): ?string
    {
        return $this->subnetAllocator->largestFreeBlock(
            (string) $container->getNetworkAddress(),
            $container->getPrefixLength(),
            $this->allocatedRangesIn($container),
        );
    }

    /**
     * Les prochaines adresses libres d'un reseau.
     *
     * @return list<string>
     *
     * @throws AllocationException si le reseau est un conteneur
     */
    public function freeAddressesIn(Subnet $subnet, int $limit = 10): array
    {
        if ($subnet->isContainer()) {
            throw AllocationException::notAssignable($subnet);
        }

        return $this->ipAllocator->findFreeAddresses(
            (string) $subnet->getNetworkAddress(),
            $subnet->getPrefixLength(),
            $this->addresses->findTakenAddresses($subnet),
            $limit,
        );
    }

    /** @throws AllocationException si le reseau est plein ou n'accepte pas d'adresses */
    public function nextFreeAddressIn(Subnet $subnet): string
    {
        $free = $this->freeAddressesIn($subnet, 1);
        if ([] === $free) {
            throw AllocationException::subnetIsFull($subnet);
        }

        return $free[0];
    }

    /**
     * Occupation d'un reseau : adresses prises, attribuables, pourcentage.
     *
     * @return array{used: int, usable: string, percent: float}
     */
    public function usageOf(Subnet $subnet): array
    {
        return $this->ipAllocator->usage(
            (string) $subnet->getNetworkAddress(),
            $subnet->getPrefixLength(),
            \count($this->addresses->findTakenAddresses($subnet)),
        );
    }

    /**
     * Verifie qu'un CIDR peut etre cree, et renvoie de quoi le poser.
     *
     * Deux reseaux CIDR bien formes sont forcement disjoints, imbriques ou
     * identiques — un chevauchement partiel n'existe pas. Le tri se fait donc
     * en trois cas : un englobant est un parent, un englobe deviendra un
     * enfant, un identique est un doublon. Le reste ne peut venir que de
     * donnees corrompues, et est refuse.
     *
     * Le controle passe par la base plutot que par la memoire : une
     * organisation peut porter des milliers de reseaux, et l'index sur les
     * bornes rend la question triviale pour MariaDB.
     *
     * @return array{network: string, last: string, prefix: int, version: IpVersion, parent: Subnet|null, children: list<Subnet>}
     *
     * @throws AllocationException       si le reseau en doublonne ou en chevauche un autre
     * @throws \InvalidArgumentException si la notation CIDR est invalide
     */
    public function prepare(Organization $organization, string $cidr, ?int $excludeId = null): array
    {
        $parsed = $this->ip->parseCidr($cidr);
        $first = $parsed['network'];
        $last = $parsed['last'];

        $parent = null;
        $children = [];
        $conflicts = [];

        foreach ($this->subnets->findOverlapping($first, $last, $organization, $excludeId) as $existing) {
            $existingFirst = (string) $existing->getNetworkAddress();
            $existingLast = (string) $existing->getLastAddress();

            $startsBefore = $this->ip->compare($existingFirst, $first) <= 0;
            $endsAfter = $this->ip->compare($existingLast, $last) >= 0;
            $identical = $this->ip->compare($existingFirst, $first) === 0
                && $this->ip->compare($existingLast, $last) === 0;

            if ($identical) {
                $conflicts[] = $existing;
            } elseif ($startsBefore && $endsAfter) {
                // Englobant : le plus specifique fera le parent.
                if (null === $parent || $existing->getPrefixLength() > $parent->getPrefixLength()) {
                    $parent = $existing;
                }
            } elseif (!$startsBefore && !$endsAfter) {
                // Englobe : le nouveau bloc deviendra son parent.
                $children[] = $existing;
            } else {
                $conflicts[] = $existing;
            }
        }

        if ([] !== $conflicts) {
            throw AllocationException::overlaps($cidr, $conflicts);
        }

        return [...$parsed, 'parent' => $parent, 'children' => $children];
    }

    /**
     * Les plages occupees a l'interieur d'un bloc, prêtes pour l'allocateur.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function allocatedRangesIn(Subnet $container): array
    {
        $ranges = [];
        foreach ($this->subnets->findAllocatedWithin($container) as $child) {
            $ranges[] = [(string) $child->getNetworkAddress(), (string) $child->getLastAddress()];
        }

        return $ranges;
    }
}
