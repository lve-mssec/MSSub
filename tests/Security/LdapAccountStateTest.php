<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LdapDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Ldap\Entry;

/**
 * Lecture des restrictions portées par un compte Active Directory.
 *
 * Un mot de passe qui ouvre une session Windows mais que l'annuaire refuse en
 * liaison simple n'est presque jamais un mauvais mot de passe : c'est une
 * restriction du compte. Le compte de service peut la lire, et le diagnostic
 * doit la nommer — sans quoi on cherche des heures du côté du mot de passe.
 */
final class LdapAccountStateTest extends TestCase
{
    use EmptySettingsTrait;

    /**
     * @param array<string, list<string>> $attributs
     */
    #[DataProvider('provideComptes')]
    public function testRestrictionsAreNamed(array $attributs, bool $bloquant, ?string $attendu): void
    {
        $etat = $this->annuaire()->etatDuCompte(new Entry('CN=Test,DC=exemple,DC=local', $attributs));

        if (null === $attendu) {
            self::assertNull($etat, 'Sans attribut connu, il n\'y a rien à dire.');

            return;
        }

        self::assertNotNull($etat);
        self::assertSame(!$bloquant, $etat['ok']);
        self::assertStringContainsString($attendu, $etat['detail']);
    }

    /** @return iterable<string, array{array<string, list<string>>, bool, string|null}> */
    public static function provideComptes(): iterable
    {
        // 512 = compte ordinaire : rien à signaler.
        yield 'compte ordinaire' => [['userAccountControl' => ['512']], false, 'Aucune restriction'];

        // 514 = 512 + 2 (désactivé)
        yield 'désactivé' => [['userAccountControl' => ['514']], true, 'désactivé'];

        // 528 = 512 + 16 (verrouillé)
        yield 'verrouillé' => [['userAccountControl' => ['528']], true, 'verrouillé'];

        // 262656 = 512 + 262144 : carte à puce exigée. La liaison par mot de
        // passe est alors toujours refusée, quel que soit le mot de passe.
        yield 'carte à puce exigée' => [['userAccountControl' => ['262656']], true, 'carte à puce'];

        // 8389120 = 512 + 8388608 (mot de passe expiré)
        yield 'mot de passe expiré' => [['userAccountControl' => ['8389120']], true, 'expiré'];

        yield 'postes restreints' => [
            ['userAccountControl' => ['512'], 'logonWorkstations' => ['POSTE-A,POSTE-B']],
            true,
            'POSTE-A,POSTE-B',
        ];

        // Le cas qui a coûté le plus de temps : la session Windows fonctionne,
        // la liaison simple LDAP est interdite par appartenance au groupe.
        yield 'Protected Users' => [
            ['userAccountControl' => ['512'], 'memberOf' => ['CN=Protected Users,CN=Users,DC=exemple,DC=local']],
            true,
            'Protected Users',
        ];

        yield 'plusieurs restrictions' => [
            [
                'userAccountControl' => ['514'],
                'memberOf' => ['CN=Protected Users,CN=Users,DC=exemple,DC=local'],
            ],
            true,
            'Protected Users',
        ];

        // Un annuaire qui n'expose pas ces attributs — OpenLDAP par exemple —
        // ne doit pas produire d'étape vide.
        yield 'annuaire sans ces attributs' => [['cn' => ['Jean']], false, null];
    }

    /** L'appartenance à un groupe ordinaire ne doit rien déclencher. */
    public function testAnOrdinaryGroupIsNotARestriction(): void
    {
        $etat = $this->annuaire()->etatDuCompte(new Entry('CN=Test,DC=exemple,DC=local', [
            'userAccountControl' => ['512'],
            'memberOf' => ['CN=mssub-admins,OU=Groupes,DC=exemple,DC=local'],
        ]));

        self::assertNotNull($etat);
        self::assertTrue($etat['ok']);
    }

    private function annuaire(): LdapDirectory
    {
        return new LdapDirectory(
            new NullLogger(),
            $this->emptySettings(),
            true,
            'ldap://exemple:389',
            'dc=exemple,dc=local',
            'cn=service,dc=exemple,dc=local',
            'secret',
            'sAMAccountName',
            '',
        );
    }
}
