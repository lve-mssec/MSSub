<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client OpenID Connect générique.
 *
 * Générique et non spécialisé : Entra ID, Keycloak, ADFS et Okta exposent tous
 * les trois mêmes points d'entrée, et une classe par fournisseur obligerait à
 * livrer une version pour chaque client.
 *
 * Le flux retenu est le code d'autorisation avec secret client. Le jeton
 * d'identité arrive alors directement du fournisseur, par le canal serveur à
 * serveur et sous TLS, jamais par le navigateur : sa signature n'a donc pas à
 * être revérifiée ici (OIDC Core, § 3.1.3.7). Ce raisonnement ne tiendrait plus
 * avec un flux implicite, qui ferait transiter le jeton par l'agent utilisateur.
 */
final class OidcClient
{
    public function __construct(
        #[Autowire('%env(bool:OIDC_ENABLED)%')]
        private readonly bool $enabled,
        #[Autowire('%env(OIDC_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(OIDC_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(OIDC_AUTHORIZATION_URL)%')]
        private readonly string $authorizationUrl,
        #[Autowire('%env(OIDC_TOKEN_URL)%')]
        private readonly string $tokenUrl,
        #[Autowire('%env(OIDC_USERINFO_URL)%')]
        private readonly string $userInfoUrl,
        #[Autowire('%env(OIDC_SCOPES)%')]
        private readonly string $scopes,
        #[Autowire('%env(OIDC_LABEL)%')]
        private readonly string $label,
        #[Autowire('%env(OIDC_USERNAME_CLAIM)%')]
        private readonly string $usernameClaim,
        #[Autowire('%env(OIDC_GROUPS_CLAIM)%')]
        private readonly string $groupsClaim,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && '' !== $this->clientId
            && '' !== $this->authorizationUrl
            && '' !== $this->tokenUrl;
    }

    public function label(): string
    {
        return '' === $this->label ? 'le fournisseur d\'identité' : $this->label;
    }

    public function provider(string $redirectUri): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $this->authorizationUrl,
            'urlAccessToken' => $this->tokenUrl,
            'urlResourceOwnerDetails' => $this->userInfoUrl,
            'scopes' => array_values(array_filter(explode(' ', $this->scopes), 'strlen')),
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * L'identifiant de connexion à retenir parmi les revendications reçues.
     *
     * L'ordre des replis n'est pas anodin : « sub » est stable mais illisible,
     * on ne s'en sert qu'à défaut de mieux, et jamais comme clé de rattachement
     * — c'est le rôle de l'identifiant externe, conservé à part.
     *
     * @param array<string, mixed> $claims
     */
    public function usernameFrom(array $claims): ?string
    {
        $candidates = array_filter([
            $this->usernameClaim,
            'preferred_username',
            'upn',
            'email',
            'sub',
        ], 'strlen');

        foreach ($candidates as $claim) {
            $value = $claims[$claim] ?? null;
            if (\is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Les groupes portés par le jeton.
     *
     * Certains fournisseurs rendent une chaîne unique plutôt qu'un tableau
     * lorsqu'il n'y a qu'un groupe : les deux formes sont acceptées.
     *
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    public function groupsFrom(array $claims): array
    {
        $claim = '' === $this->groupsClaim ? 'groups' : $this->groupsClaim;
        $value = $claims[$claim] ?? [];

        if (\is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => \is_string($item) ? trim($item) : '',
            $value,
        ), 'strlen'));
    }
}
