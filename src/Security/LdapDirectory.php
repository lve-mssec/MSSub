<?php

declare(strict_types=1);

namespace App\Security;

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
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:LDAP_ENABLED)%')]
        private readonly bool $enabled,
        #[Autowire('%env(LDAP_URL)%')]
        private readonly string $url,
        #[Autowire('%env(LDAP_BASE_DN)%')]
        private readonly string $baseDn,
        #[Autowire('%env(LDAP_SEARCH_DN)%')]
        private readonly string $searchDn,
        #[Autowire('%env(LDAP_SEARCH_PASSWORD)%')]
        private readonly string $searchPassword,
        #[Autowire('%env(LDAP_UID_KEY)%')]
        private readonly string $uidKey,
        #[Autowire('%env(LDAP_EXTRA_FILTER)%')]
        private readonly string $extraFilter,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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
        if (!$this->enabled || '' === $password) {
            return null;
        }

        try {
            $ldap = Ldap::create('ext_ldap', ['connection_string' => $this->url]);
            $ldap->bind($this->searchDn, $this->searchPassword);

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
            $ldap->bind($this->searchDn, $this->searchPassword);

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
            $this->uidKey,
            ldap_escape($username, '', \LDAP_ESCAPE_FILTER),
            $this->extraFilter,
        );

        $entries = $ldap->query($this->baseDn, $filter, ['maxItems' => 2])->execute();

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
                foreach ($ldap->query($this->baseDn, $filter, ['maxItems' => 200])->execute() as $group) {
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
