<?php

declare(strict_types=1);

namespace App\Enum;

enum DeviceType: string
{
    case Router = 'router';
    case Switch_ = 'switch';
    case Firewall = 'firewall';
    case Server = 'server';
    case Hypervisor = 'hypervisor';
    case AccessPoint = 'access_point';
    case Printer = 'printer';
    case Appliance = 'appliance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Router => 'Routeur',
            self::Switch_ => 'Commutateur',
            self::Firewall => 'Pare-feu',
            self::Server => 'Serveur',
            self::Hypervisor => 'Hyperviseur',
            self::AccessPoint => 'Borne Wi-Fi',
            self::Printer => 'Imprimante',
            self::Appliance => 'Appliance',
            self::Other => 'Autre',
        };
    }
}
