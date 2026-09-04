<?php

declare(strict_types=1);

namespace App\Security;

/** Ce qu'un annuaire nous apprend d'un compte, une fois le bind réussi. */
final class LdapIdentity
{
    /** @param list<string> $groups noms courts (CN) des groupes d'appartenance */
    public function __construct(
        public readonly string $username,
        public readonly string $dn,
        public readonly ?string $displayName,
        public readonly ?string $email,
        public readonly array $groups,
    ) {
    }
}
