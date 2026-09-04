<?php

declare(strict_types=1);

namespace App\Enum;

/** Les trois provenances d'identite retenues pour la v1. */
enum AuthSource: string
{
    case Local = 'local';
    case Ldap = 'ldap';
    case Oidc = 'oidc';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Compte local',
            self::Ldap => 'Annuaire LDAP/AD',
            self::Oidc => 'SSO OIDC',
        };
    }

    /** Seul un compte local porte un mot de passe en base. */
    public function hasLocalPassword(): bool
    {
        return self::Local === $this;
    }
}
