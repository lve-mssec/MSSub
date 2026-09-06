<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LdapDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Authentification contre un annuaire réel.
 *
 * Ces tests parlent au conteneur OpenLDAP du projet, démarré par
 * `docker compose --profile annuaire up -d`. Ils sont ignorés s'il ne répond
 * pas : un poste sans annuaire ne doit pas voir la suite échouer, mais les
 * ignorer silencieusement ferait passer du code LDAP jamais exécuté — le
 * message de saut indique donc quoi lancer.
 */
final class LdapDirectoryTest extends TestCase
{
    use EmptySettingsTrait;

    private const URL = 'ldap://annuaire:389';
    private const BASE_DN = 'dc=mssec,dc=local';
    private const AUTORITE = '/var/www/html/docker/openldap/certs/ca.crt';
    private const AUTORITE_SYSTEME = '/etc/ssl/certs/ca-certificates.crt';

    protected function setUp(): void
    {
        if (!$this->directoryIsUp()) {
            self::markTestSkipped('Annuaire absent — lancer : docker compose --profile annuaire up -d');
        }
    }

    public function testValidCredentialsReturnTheIdentity(): void
    {
        $identity = $this->directory()->authenticate('jdupont', 'motdepasse1');

        self::assertNotNull($identity);
        self::assertSame('jdupont', $identity->username);
        self::assertSame('uid=jdupont,ou=users,dc=mssec,dc=local', $identity->dn);
        self::assertSame('Jean Dupont', $identity->displayName);
        self::assertSame('jean.dupont@mssec.local', $identity->email);
    }

    /**
     * Les groupes doivent être lus sous le compte de service.
     *
     * Après le bind de vérification, la connexion porte l'identité de
     * l'utilisateur, qui n'a généralement pas le droit de parcourir l'unité des
     * groupes : la liste reviendrait vide et tout le monde entrerait en lecture
     * seule. C'est exactement ce qui s'est produit avant correction.
     */
    public function testGroupsAreReadDespiteTheUserBind(): void
    {
        self::assertSame(['mssub-admins'], $this->directory()->authenticate('jdupont', 'motdepasse1')?->groups);
        self::assertSame(['mssub-operateurs'], $this->directory()->authenticate('mmartin', 'motdepasse2')?->groups);
    }

    public function testWrongPasswordIsRefused(): void
    {
        self::assertNull($this->directory()->authenticate('jdupont', 'pas-le-bon'));
    }

    public function testUnknownAccountIsRefused(): void
    {
        self::assertNull($this->directory()->authenticate('personne', 'motdepasse1'));
    }

    /** Un mot de passe vide ferait un bind anonyme, que l'annuaire accepterait. */
    public function testEmptyPasswordIsRefusedWithoutReachingTheDirectory(): void
    {
        self::assertNull($this->directory()->authenticate('jdupont', ''));
    }

    public function testDisabledDirectoryNeverAuthenticates(): void
    {
        self::assertNull($this->directory(enabled: false)->authenticate('jdupont', 'motdepasse1'));
    }

    /** Le filtre additionnel doit réellement restreindre le périmètre. */
    public function testExtraFilterRestrictsTheSearch(): void
    {
        $directory = $this->directory(extraFilter: '(objectClass=organizationalUnit)');

        self::assertNull($directory->authenticate('jdupont', 'motdepasse1'));
    }

    /**
     * Le cas que rencontre tout Active Directory : LDAPS avec un certificat
     * signé par une autorité interne.
     *
     * Trois issues possibles, et il faut que les trois se comportent comme
     * annoncé — c'est ce réglage qui décide si l'annuaire est joignable ou non.
     */
    public function testLdapsRefusesAnUnknownAuthority(): void
    {
        $this->skipSansCertificats();

        // L'autorité du système est indiquée explicitement plutôt que laissée
        // vide : les options TLS d'OpenLDAP étant globales au processus, un test
        // précédent aurait sinon déjà posé la nôtre et celui-ci passerait.
        $annuaire = $this->directoryTls([
            'ldap.url' => 'ldaps://annuaire:636',
            'ldap.tls_ca' => self::AUTORITE_SYSTEME,
        ]);

        self::assertNull($annuaire->authenticate('jdupont', 'motdepasse1'));
        self::assertFalse($annuaire->testConnection()['ok']);
    }

    public function testLdapsAcceptsTheInternalAuthority(): void
    {
        $this->skipSansCertificats();

        $annuaire = $this->directoryTls([
            'ldap.url' => 'ldaps://annuaire:636',
            'ldap.tls_ca' => self::AUTORITE,
        ]);

        self::assertSame('jdupont', $annuaire->authenticate('jdupont', 'motdepasse1')?->username);
        self::assertTrue($annuaire->testConnection()['ok']);
    }

    /** Sans vérification, la liaison reste chiffrée mais n'authentifie plus le serveur. */
    public function testLdapsWithoutVerification(): void
    {
        $this->skipSansCertificats();

        $annuaire = $this->directoryTls([
            'ldap.url' => 'ldaps://annuaire:636',
            'ldap.tls_verification' => 'non',
        ]);

        self::assertSame('jdupont', $annuaire->authenticate('jdupont', 'motdepasse1')?->username);
    }

    /** StartTLS élève le port 389 en TLS : l'autre réponse à un annuaire durci. */
    public function testStartTlsOnThePlainPort(): void
    {
        $this->skipSansCertificats();

        $annuaire = $this->directoryTls([
            'ldap.url' => 'ldap://annuaire:389',
            'ldap.encryption' => 'starttls',
            'ldap.tls_ca' => self::AUTORITE,
        ]);

        self::assertSame('jdupont', $annuaire->authenticate('jdupont', 'motdepasse1')?->username);
    }

    /** Le message d'un annuaire durci doit dire quoi faire, pas seulement ce qui a raté. */
    public function testTheRefusalExplainsWhatToDo(): void
    {
        $this->skipSansCertificats();

        $message = $this->directoryTls([
            'ldap.url' => 'ldaps://annuaire:636',
            'ldap.tls_ca' => self::AUTORITE_SYSTEME,
        ])->testConnection()['message'];

        self::assertStringContainsString('certificat', mb_strtolower($message));
    }

    /** @param array<string, string> $reglages */
    private function directoryTls(array $reglages): LdapDirectory
    {
        return new LdapDirectory(
            new NullLogger(),
            $this->settingsAvec($reglages),
            true,
            self::URL,
            self::BASE_DN,
            'cn=admin,dc=mssec,dc=local',
            'adminpass',
            'uid',
            '',
        );
    }

    private function skipSansCertificats(): void
    {
        is_readable(self::AUTORITE) || self::markTestSkipped(
            'Certificats absents — lancer : ./docker/openldap/engendrer-certificats.sh puis '
            .'docker compose --profile annuaire up -d',
        );
    }

    private function directory(
        bool $enabled = true,
        string $extraFilter = '',
    ): LdapDirectory {
        return new LdapDirectory(
            new NullLogger(),
            $this->emptySettings(),
            $enabled,
            self::URL,
            self::BASE_DN,
            'cn=admin,dc=mssec,dc=local',
            'adminpass',
            'uid',
            $extraFilter,
        );
    }

    private function directoryIsUp(): bool
    {
        $socket = @fsockopen('annuaire', 389, $code, $message, 1);
        if (false === $socket) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
