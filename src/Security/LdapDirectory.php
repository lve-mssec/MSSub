<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\ExceptionInterface as LdapException;
use Symfony\Component\Ldap\Ldap;

/**
 * Authentification et lecture d'un annuaire LDAP ou Active Directory.
 *
 * Le mot de passe n'est jamais comparé par l'application : c'est l'annuaire qui
 * tranche, par un bind avec les identifiants fournis. Aucun secret d'annuaire
 * ne transite donc par MSSub, et rien n'est stocké en base pour ces comptes.
 *
 * La recherche préalable se fait avec un compte de service, parce que le DN
 * complet d'un utilisateur n'est pas déductible de son identifiant : « jdupont »
 * peut vivre sous n'importe quelle unité d'organisation.
 */
final class LdapDirectory
{
    /** Noms des paramètres, repris tels quels par l'écran d'administration. */
    public const KEYS = [
        'ldap.enabled', 'ldap.url', 'ldap.base_dn', 'ldap.search_dn',
        'ldap.search_password', 'ldap.uid_key', 'ldap.extra_filter',
        'ldap.encryption', 'ldap.tls_verification', 'ldap.tls_ca',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
        #[Autowire('%env(bool:LDAP_ENABLED)%')]
        private readonly bool $envEnabled,
        #[Autowire('%env(LDAP_URL)%')]
        private readonly string $envUrl,
        #[Autowire('%env(LDAP_BASE_DN)%')]
        private readonly string $envBaseDn,
        #[Autowire('%env(LDAP_SEARCH_DN)%')]
        private readonly string $envSearchDn,
        #[Autowire('%env(LDAP_SEARCH_PASSWORD)%')]
        private readonly string $envSearchPassword,
        #[Autowire('%env(LDAP_UID_KEY)%')]
        private readonly string $envUidKey,
        #[Autowire('%env(LDAP_EXTRA_FILTER)%')]
        private readonly string $envExtraFilter,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('ldap.enabled', $this->envEnabled);
    }

    private function url(): string
    {
        return (string) $this->settings->get('ldap.url', $this->envUrl);
    }

    private function baseDn(): string
    {
        return (string) $this->settings->get('ldap.base_dn', $this->envBaseDn);
    }

    private function searchDn(): string
    {
        return (string) $this->settings->get('ldap.search_dn', $this->envSearchDn);
    }

    private function searchPassword(): string
    {
        return (string) $this->settings->get('ldap.search_password', $this->envSearchPassword);
    }

    private function uidKey(): string
    {
        $key = (string) $this->settings->get('ldap.uid_key', $this->envUidKey);

        return '' === $key ? 'uid' : $key;
    }

    private function extraFilter(): string
    {
        return (string) $this->settings->get('ldap.extra_filter', $this->envExtraFilter);
    }

    /**
     * Mode de chiffrement : « ldaps », « starttls » ou « aucun ».
     *
     * Par défaut il se déduit de l'URL, pour que les configurations existantes
     * continuent de fonctionner sans qu'on ait à choisir quoi que ce soit.
     */
    private function encryption(): string
    {
        $configure = (string) $this->settings->get('ldap.encryption', '');
        if ('' !== $configure) {
            return $configure;
        }

        return str_starts_with(strtolower($this->url()), 'ldaps://') ? 'ldaps' : 'aucun';
    }

    /**
     * Ouvre une connexion en appliquant le chiffrement demandé.
     *
     * Les options TLS sont posées globalement, et non sur la connexion : c'est
     * une particularité d'OpenLDAP côté client, où une exigence de certificat
     * définie après ldap_connect() n'est pas prise en compte. Les poser avant la
     * connexion est la seule façon fiable de les faire respecter.
     *
     * Conséquence à connaître : ces réglages survivent à la requête et valent
     * pour tout le processus. Sous mod_php ou PHP-FPM, un travailleur ayant
     * servi une requête qui désactivait la vérification la garde désactivée
     * jusqu'à son recyclage. Après avoir modifié ces réglages, un rechargement
     * d'Apache est donc la seule façon d'être certain de ce qui s'applique.
     */
    private function ouvrir(): Ldap
    {
        $verification = (string) $this->settings->get('ldap.tls_verification', 'oui');
        $autorite = (string) $this->settings->get('ldap.tls_ca', '');

        if ('' !== $autorite) {
            @ldap_set_option(null, \LDAP_OPT_X_TLS_CACERTFILE, $autorite);
        }

        if ('non' === $verification) {
            // Le certificat n'est plus vérifié : la liaison reste chiffrée mais
            // n'authentifie plus le serveur. À réserver à une mise au point.
            @ldap_set_option(null, \LDAP_OPT_X_TLS_REQUIRE_CERT, \LDAP_OPT_X_TLS_NEVER);
            $this->logger->warning('Vérification du certificat LDAP désactivée.');
        } else {
            @ldap_set_option(null, \LDAP_OPT_X_TLS_REQUIRE_CERT, \LDAP_OPT_X_TLS_DEMAND);
        }

        $configuration = ['connection_string' => $this->url()];

        // StartTLS élève une connexion en clair sur le port 389. Le mode
        // « ldaps » n'a rien à demander : le chiffrement est établi d'emblée par
        // le schéma de l'URL.
        if ('starttls' === $this->encryption()) {
            $configuration['encryption'] = 'tls';
        }

        return Ldap::create('ext_ldap', $configuration);
    }

    /**
     * Vérifie que l'annuaire répond et que le compte de service est accepté.
     *
     * Sans ce contrôle depuis l'écran, une erreur de configuration ne se
     * découvrirait qu'à la première tentative de connexion d'un utilisateur,
     * qui n'aurait aucun moyen de comprendre ce qui se passe.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $ldap = $this->ouvrir();
            $ldap->bind($this->searchDn(), $this->searchPassword());

            $count = \count($ldap->query($this->baseDn(), \sprintf('(%s=*)', $this->uidKey()), ['maxItems' => 50])->execute());

            return [
                'ok' => true,
                'message' => \sprintf(
                    'Connexion établie (%s). %d compte(s) visible(s) sous %s.',
                    $this->encryption(),
                    $count,
                    $this->baseDn(),
                ),
            ];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => $this->expliquer($e->getMessage())];
        } catch (LdapException $e) {
            return ['ok' => false, 'message' => $this->expliquer($e->getMessage())];
        }
    }

    /**
     * Traduit les échecs d'annuaire les plus courants en conduite à tenir.
     *
     * Les messages rendus par OpenLDAP et Active Directory décrivent la cause
     * technique sans jamais dire quoi faire. Or celui qui les lit est en train
     * de remplir un formulaire, pas de déboguer une pile réseau.
     */
    private function expliquer(string $message): string
    {
        $connu = [
            'Strong(er) authentication required' => 'Le contrôleur de domaine refuse les liaisons non chiffrées. '
                .'Passez l\'URL en « ldaps://serveur:636 », ou gardez le port 389 en choisissant le chiffrement StartTLS. '
                .'C\'est le réglage par défaut d\'Active Directory depuis Windows Server 2019.',
            'Can\'t contact LDAP server' => 'Serveur injoignable : vérifiez le nom, le port, et qu\'aucun pare-feu '
                .'ne bloque (389 pour LDAP et StartTLS, 636 pour LDAPS). En LDAPS, cette erreur signale aussi '
                .'un certificat rejeté — si l\'autorité est interne, indiquez-la ou désactivez la vérification le temps du test.',
            'Invalid credentials' => 'Le compte de service est refusé : vérifiez son DN complet et son mot de passe. '
                .'Sur Active Directory, le DN s\'écrit « CN=Service MSSub,OU=Comptes,DC=exemple,DC=local ».',
            'Invalid DN syntax' => 'Le DN du compte de service ou la base de recherche est mal formé.',
            'No such object' => 'La base de recherche n\'existe pas sur ce serveur : vérifiez « dc=... ».',
            'Operations error' => 'Active Directory refuse une recherche anonyme : renseignez un compte de service.',
        ];

        foreach ($connu as $fragment => $conduite) {
            if (str_contains($message, $fragment)) {
                return $conduite."\n\nMessage du serveur : ".$message;
            }
        }

        return 'Échec de la connexion : '.$message;
    }

    /**
     * Vérifie un couple identifiant/mot de passe auprès de l'annuaire.
     *
     * Renvoie null pour tout échec — compte absent, mot de passe faux, annuaire
     * injoignable. La distinction n'est pas rendue à l'appelant : elle
     * indiquerait à un attaquant quels identifiants existent.
     */
    public function authenticate(string $username, string $password): ?LdapIdentity
    {
        if (!$this->isEnabled() || '' === $password) {
            return null;
        }

        try {
            $ldap = $this->ouvrir();
            $ldap->bind($this->searchDn(), $this->searchPassword());

            $entry = $this->findEntry($ldap, $username);
            if (null === $entry) {
                return null;
            }

            // Le vrai contrôle : seul l'annuaire sait si ce mot de passe est bon.
            $ldap->bind($entry->getDn(), $password);

            // Retour au compte de service avant de lire les groupes : la
            // connexion est désormais celle de l'utilisateur, qui n'a
            // généralement pas le droit de parcourir l'unité des groupes. Lire
            // sous son identité rendrait une liste vide, et tout le monde
            // arriverait avec les seuls droits de lecture.
            $ldap->bind($this->searchDn(), $this->searchPassword());

            return new LdapIdentity(
                username: $username,
                dn: $entry->getDn(),
                displayName: $this->attribute($entry, ['displayName', 'cn', 'givenName']),
                email: $this->attribute($entry, ['mail', 'userPrincipalName']),
                groups: $this->groupsOf($ldap, $entry),
            );
        } catch (ConnectionException $e) {
            // Annuaire injoignable : c'est un incident d'exploitation, pas une
            // erreur d'identifiants. Il doit se voir dans les journaux.
            $this->logger->error('Annuaire LDAP injoignable.', ['exception' => $e]);

            return null;
        } catch (LdapException $e) {
            $this->logger->info('Authentification LDAP refusée.', ['utilisateur' => $username, 'motif' => $e->getMessage()]);

            return null;
        }
    }

    private function findEntry(Ldap $ldap, string $username): ?Entry
    {
        $filter = \sprintf(
            '(&(%s=%s)%s)',
            $this->uidKey(),
            ldap_escape($username, '', \LDAP_ESCAPE_FILTER),
            $this->extraFilter(),
        );

        $entries = $ldap->query($this->baseDn(), $filter, ['maxItems' => 2])->execute();

        // Deux résultats pour un identifiant signalent un annuaire incohérent :
        // choisir au hasard ferait entrer la mauvaise personne.
        if (1 !== \count($entries)) {
            if (\count($entries) > 1) {
                $this->logger->error('Identifiant LDAP ambigu.', ['utilisateur' => $username]);
            }

            return null;
        }

        return $entries[0];
    }

    /**
     * Les groupes d'appartenance, sous leur nom court.
     *
     * Active Directory renseigne memberOf sur l'utilisateur ; OpenLDAP ne le
     * fait qu'avec une surcouche, d'où la recherche inverse en repli.
     *
     * @return list<string>
     */
    private function groupsOf(Ldap $ldap, Entry $entry): array
    {
        $dns = $entry->getAttribute('memberOf') ?? [];

        if ([] === $dns) {
            try {
                $filter = \sprintf('(member=%s)', ldap_escape($entry->getDn(), '', \LDAP_ESCAPE_FILTER));
                foreach ($ldap->query($this->baseDn(), $filter, ['maxItems' => 200])->execute() as $group) {
                    $dns[] = $group->getDn();
                }
            } catch (LdapException $e) {
                $this->logger->warning('Recherche des groupes LDAP impossible.', ['exception' => $e]);
            }
        }

        $names = [];
        foreach ($dns as $dn) {
            $names[] = $this->commonName((string) $dn);
        }

        return array_values(array_unique(array_filter($names, 'strlen')));
    }

    /** Extrait le CN d'un DN : « cn=admins,ou=groups,… » donne « admins ». */
    private function commonName(string $dn): string
    {
        if (1 === preg_match('/^\s*cn=([^,]+)/i', $dn, $matches)) {
            return trim(str_replace('\\,', ',', $matches[1]));
        }

        return trim($dn);
    }

    /** @param list<string> $candidates */
    private function attribute(Entry $entry, array $candidates): ?string
    {
        foreach ($candidates as $name) {
            $values = $entry->getAttribute($name);
            if (null !== $values && [] !== $values && '' !== trim((string) $values[0])) {
                return trim((string) $values[0]);
            }
        }

        return null;
    }
}
