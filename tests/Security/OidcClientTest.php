<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\OidcClient;
use PHPUnit\Framework\TestCase;

/**
 * Les revendications d'un jeton varient d'un fournisseur à l'autre.
 *
 * Entra ID rend « upn », Keycloak « preferred_username », certains rien du tout
 * hormis « sub ». Ces tests fixent l'ordre des replis, qui détermine sous quel
 * nom la personne apparaîtra dans le journal d'audit.
 */
final class OidcClientTest extends TestCase
{
    public function testConfiguredClaimWinsOverTheFallbacks(): void
    {
        $client = $this->client(usernameClaim: 'upn');

        self::assertSame('jean@mssec.fr', $client->usernameFrom([
            'upn' => 'jean@mssec.fr',
            'preferred_username' => 'jdupont',
            'sub' => 'abc-123',
        ]));
    }

    public function testFallbackOrderIsPredictable(): void
    {
        $client = $this->client(usernameClaim: 'inexistant');

        self::assertSame('jdupont', $client->usernameFrom(['preferred_username' => 'jdupont', 'sub' => 'abc']));
        self::assertSame('jean@mssec.fr', $client->usernameFrom(['email' => 'jean@mssec.fr', 'sub' => 'abc']));
        // « sub » est stable mais illisible : dernier recours seulement.
        self::assertSame('abc', $client->usernameFrom(['sub' => 'abc']));
    }

    public function testNoUsableClaimYieldsNull(): void
    {
        self::assertNull($this->client()->usernameFrom(['nom' => 'Jean']));
        self::assertNull($this->client()->usernameFrom(['sub' => '   ']));
    }

    /** Certains fournisseurs rendent une chaîne quand il n'y a qu'un groupe. */
    public function testGroupsAcceptBothArrayAndString(): void
    {
        $client = $this->client();

        self::assertSame(['admins', 'lecteurs'], $client->groupsFrom(['groups' => ['admins', 'lecteurs']]));
        self::assertSame(['admins'], $client->groupsFrom(['groups' => 'admins']));
        self::assertSame(['admins', 'lecteurs'], $client->groupsFrom(['groups' => 'admins, lecteurs']));
        self::assertSame([], $client->groupsFrom([]));
        self::assertSame([], $client->groupsFrom(['groups' => 42]));
    }

    public function testGroupsClaimIsConfigurable(): void
    {
        $client = $this->client(groupsClaim: 'roles');

        self::assertSame(['admins'], $client->groupsFrom(['roles' => ['admins'], 'groups' => ['ignorer']]));
    }

    /** Sans identifiant client ni point d'entrée, le SSO ne doit pas s'afficher. */
    public function testDisabledWhenIncompletelyConfigured(): void
    {
        self::assertFalse($this->client(enabled: true, clientId: '')->isEnabled());
        self::assertFalse($this->client(enabled: false, clientId: 'mssub')->isEnabled());
        self::assertTrue($this->client(enabled: true, clientId: 'mssub')->isEnabled());
    }

    private function client(
        bool $enabled = true,
        string $clientId = 'mssub',
        string $usernameClaim = 'preferred_username',
        string $groupsClaim = 'groups',
    ): OidcClient {
        return new OidcClient(
            enabled: $enabled,
            clientId: $clientId,
            clientSecret: 'secret',
            authorizationUrl: 'https://idp.example/authorize',
            tokenUrl: 'https://idp.example/token',
            userInfoUrl: 'https://idp.example/userinfo',
            scopes: 'openid profile email',
            label: '',
            usernameClaim: $usernameClaim,
            groupsClaim: $groupsClaim,
        );
    }
}
