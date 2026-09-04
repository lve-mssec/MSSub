<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Traduit des groupes d'annuaire ou des revendications SSO en rôles applicatifs.
 *
 * La correspondance vit dans une variable d'environnement plutôt qu'en base :
 * elle relève du déploiement, pas des données, et doit pouvoir être relue et
 * corrigée sans accès au portail — notamment le jour où une erreur de mapping
 * a retiré à tout le monde le rôle d'administrateur.
 */
final class RoleMapper
{
    /** @var array<string, string> nom de groupe en minuscules => rôle */
    private array $map;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(json:APP_ROLE_MAP)%')]
        array $rawMap = [],
    ) {
        $this->map = [];
        foreach ($rawMap as $group => $role) {
            $this->map[mb_strtolower(trim((string) $group))] = (string) $role;
        }
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string>
     */
    public function rolesFor(array $groups): array
    {
        $roles = [];

        foreach ($groups as $group) {
            $key = mb_strtolower(trim($group));
            if (isset($this->map[$key])) {
                $roles[] = $this->map[$key];
            }
        }

        if ([] === $roles && [] !== $groups) {
            $this->logger->info('Aucun groupe reconnu ; accès en lecture seule.', ['groupes' => $groups]);
        }

        // Tout compte authentifié obtient au moins la lecture : un annuaire mal
        // mappé doit dégrader vers le moindre privilège, pas vers une erreur.
        $roles[] = User::ROLE_USER;

        return array_values(array_unique($roles));
    }
}
