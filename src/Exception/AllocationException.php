<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Subnet;

/** Refus metier lors d'une attribution : chevauchement, bloc plein, incoherence. */
class AllocationException extends \DomainException
{
    /** @param list<Subnet> $conflicts */
    public static function overlaps(string $cidr, array $conflicts): self
    {
        $list = implode(', ', array_map(static fn (Subnet $s): string => $s->getCidr(), $conflicts));

        return new self(\sprintf('%s chevauche un reseau existant : %s.', $cidr, $list));
    }

    public static function containerIsFull(Subnet $container, int $prefix): self
    {
        return new self(\sprintf('Plus aucun /%d disponible dans %s.', $prefix, $container->getCidr()));
    }

    public static function subnetIsFull(Subnet $subnet): self
    {
        return new self(\sprintf('Plus aucune adresse disponible dans %s.', $subnet->getCidr()));
    }

    public static function notAssignable(Subnet $subnet): self
    {
        return new self(\sprintf(
            '%s est un conteneur : il accueille des sous-reseaux, pas des adresses.',
            $subnet->getCidr(),
        ));
    }

    public static function outsideParent(string $cidr, Subnet $parent): self
    {
        return new self(\sprintf('%s ne tient pas dans son bloc parent %s.', $cidr, $parent->getCidr()));
    }
}
