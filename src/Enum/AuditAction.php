<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Import = 'import';
    case Export = 'export';
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Creation',
            self::Update => 'Modification',
            self::Delete => 'Suppression',
            self::Import => 'Import',
            self::Export => 'Export',
            self::Login => 'Connexion',
            self::LoginFailed => 'Echec de connexion',
            self::Logout => 'Deconnexion',
        };
    }
}
