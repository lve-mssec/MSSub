<?php

declare(strict_types=1);

namespace App\Enum;

enum IpVersion: int
{
    case V4 = 4;
    case V6 = 6;

    public function label(): string
    {
        return match ($this) {
            self::V4 => 'IPv4',
            self::V6 => 'IPv6',
        };
    }

    /** Longueur du prefixe maximal : /32 en v4, /128 en v6. */
    public function maxPrefix(): int
    {
        return match ($this) {
            self::V4 => 32,
            self::V6 => 128,
        };
    }
}
