<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Settings;
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
    /** Noms des paramètres, repris tels quels par l'écran d'administration. */
    public const KEYS = [
        'oidc.enabled', 'oidc.label', 'oidc.client_id', 'oidc.client_secret',
        'oidc.authorization_url', 'oidc.token_url', 'oidc.userinfo_url',
        'oidc.scopes', 'oidc.username_claim', 'oidc.groups_claim',
    ];

    public function __construct(
        private readonly Settings $settings,
        #[Autowire('%env(bool:OIDC_ENABLED)%')]
        private readonly bool $envEnabled = false,
        #[Autowire('%env(OIDC_CLIENT_ID)%')]
        private readonly string $envClientId = '',
        #[Autowire('%env(OIDC_CLIENT_SECRET)%')]
        private readonly string $envClientSecret = '',
        #[Autowire('%env(OIDC_AUTHORIZATION_URL)%')]
        private readonly string $envAuthorizationUrl = '',
        #[Autowire('%env(OIDC_TOKEN_URL)%')]
        private readonly string $envTokenUrl = '',
        #[Autowire('%env(OIDC_USERINFO_URL)%')]
        private readonly string $envUserInfoUrl = '',
        #[Autowire('%env(OIDC_SCOPES)%')]
        private readonly string $envScopes = 'openid profile email',
        #[Autowire('%env(OIDC_LABEL)%')]
        private readonly string $envLabel = '',
        #[Autowire('%env(OIDC_USERNAME_CLAIM)%')]
        private readonly string $envUsernameClaim = 'preferred_username',
        #[Autowire('%env(OIDC_GROUPS_CLAIM)%')]
        private readonly string $envGroupsClaim = 'groups',
    ) {
    }

    public function isEnabled(): bool
    {
        // Un SSO annoncé mais incomplet afficherait un bouton menant à une
        // erreur : mieux vaut ne rien proposer tant que l'essentiel manque.
        return $this->settings->getBool('oidc.enabled', $this->envEnabled)
            && '' !== $this->clientId()
            && '' !== $this->authorizationUrl()
            && '' !== $this->tokenUrl();
    }

    public function label(): string
    {
        $label = (string) $this->settings->get('oidc.label', $this->envLabel);

        return '' === $label ? 'le fournisseur d\'identité' : $label;
    }

    public function clientId(): string
    {
        return (string) $this->settings->get('oidc.client_id', $this->envClientId);
    }

    public function authorizationUrl(): string
    {
        return (string) $this->settings->get('oidc.authorization_url', $this->envAuthorizationUrl);
    }

    public function tokenUrl(): string
    {
        return (string) $this->settings->get('oidc.token_url', $this->envTokenUrl);
    }

    public function provider(string $redirectUri): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $this->clientId(),
            'clientSecret' => (string) $this->settings->get('oidc.client_secret', $this->envClientSecret),
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $this->authorizationUrl(),
            'urlAccessToken' => $this->tokenUrl(),
            'urlResourceOwnerDetails' => (string) $this->settings->get('oidc.userinfo_url', $this->envUserInfoUrl),
            'scopes' => array_values(array_filter(explode(' ', (string) $this->settings->get('oidc.scopes', $this->envScopes)), 'strlen')),
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
            (string) $this->settings->get('oidc.username_claim', $this->envUsernameClaim),
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
        $configured = (string) $this->settings->get('oidc.groups_claim', $this->envGroupsClaim);
        $claim = '' === $configured ? 'groups' : $configured;
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
