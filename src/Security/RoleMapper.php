<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\Settings;
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
    public const KEY = 'roles.map';

    /** @var array<string, string> nom de groupe en minuscules => rôle */
    private readonly array $envMap;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
        #[Autowire('%env(json:APP_ROLE_MAP)%')]
        array $rawMap = [],
    ) {
        $this->envMap = $this->normalize($rawMap);
    }

    /**
     * La correspondance en vigueur, la base l'emportant sur l'environnement.
     *
     * @return array<string, string>
     */
    private function map(): array
    {
        $raw = $this->settings->get(self::KEY);
        if (null === $raw || '' === trim($raw)) {
            return $this->envMap;
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Une correspondance illisible ne doit pas bloquer les connexions :
            // on retombe sur l'environnement, en le disant dans les journaux.
            $this->logger->error('Correspondance des rôles illisible.', ['exception' => $e]);

            return $this->envMap;
        }

        return \is_array($decoded) ? $this->normalize($decoded) : $this->envMap;
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @return array<string, string>
     */
    private function normalize(array $raw): array
    {
        $map = [];
        foreach ($raw as $group => $role) {
            $map[mb_strtolower(trim((string) $group))] = (string) $role;
        }

        return $map;
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string>
     */
    public function rolesFor(array $groups): array
    {
        $map = $this->map();
        $roles = [];

        foreach ($groups as $group) {
            $key = mb_strtolower(trim($group));
            if (isset($map[$key])) {
                $roles[] = $map[$key];
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
