<?php

declare(strict_types=1);

namespace App\Enum;

enum SubnetStatus: string
{
    /** Bloc decoupable : il n'accueille que des sous-reseaux, pas des adresses. */
    case Container = 'container';
    case Active = 'active';
    case Reserved = 'reserved';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Container => 'Conteneur',
            self::Active => 'Actif',
            self::Reserved => 'Reserve',
            self::Deprecated => 'Obsolete',
        };
    }
}
