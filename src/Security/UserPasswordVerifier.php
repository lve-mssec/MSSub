<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérifie le mot de passe d'un compte local.
 *
 * Cette indirection d'apparence inutile évite une dépendance circulaire :
 * l'authentificateur a besoin du hacheur, que le conteneur construit à partir
 * de la configuration de sécurité, laquelle référence l'authentificateur.
 */
final class UserPasswordVerifier
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function isValid(User $user, string $password): bool
    {
        if (null === $user->getPassword() || '' === $password) {
            return false;
        }

        return $this->hasher->isPasswordValid($user, $password);
    }
}
