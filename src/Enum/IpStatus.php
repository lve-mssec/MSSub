<?php

declare(strict_types=1);

namespace App\Enum;

enum IpStatus: string
{
    case Free = 'free';
    case Used = 'used';
    case Reserved = 'reserved';
    case Dhcp = 'dhcp';
    case Gateway = 'gateway';
    /** Adresse de reseau ou de diffusion : jamais attribuable. */
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Libre',
            self::Used => 'Utilisee',
            self::Reserved => 'Reservee',
            self::Dhcp => 'DHCP',
            self::Gateway => 'Passerelle',
            self::NotApplicable => 'Non applicable',
        };
    }

    public function isAssignable(): bool
    {
        return self::NotApplicable !== $this;
    }
}
