<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Adapter\ExtLdap\Adapter;
use Symfony\Component\Ldap\Adapter\ExtLdap\Connection as ExtConnection;
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

    /** Conservé pour pouvoir interroger la connexion brute après un échec. */
    private ?Adapter $adaptateur = null;

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

    /**
     * Le filtre additionnel, ramené à une forme utilisable.
     *
     * Il est concaténé dans un « (&(uid=x)FILTRE) » et doit donc être
     * parenthésé. Personne ne saisit spontanément « (objectClass=user) » avec
     * ses parenthèses : la forme nue est la plus naturelle, et l'exiger
     * produisait un filtre malformé que l'annuaire rejetait en bloc — toutes
     * les connexions échouaient, pour une paire de parenthèses.
     *
     * Un filtre déséquilibré est écarté plutôt que transmis : mieux vaut une
     * recherche trop large, qui fonctionne, qu'une requête invalide qui refuse
     * tout le monde.
     */
    private function extraFilter(): string
    {
        $filtre = trim((string) $this->settings->get('ldap.extra_filter', $this->envExtraFilter));

        if ('' === $filtre) {
            return '';
        }

        if (!str_starts_with($filtre, '(')) {
            $filtre = '('.$filtre.')';
        }

        if (!$this->parenthesesEquilibrees($filtre)) {
            $this->logger->error('Filtre LDAP additionnel déséquilibré : ignoré.', ['filtre' => $filtre]);

            return '';
        }

        return $filtre;
    }

    private function parenthesesEquilibrees(string $filtre): bool
    {
        $profondeur = 0;

        for ($i = 0, $n = \strlen($filtre); $i < $n; ++$i) {
            $caractere = $filtre[$i];

            // Une parenthèse échappée ne compte pas : « \28 » et « \29 » sont
            // les formes littérales admises par la RFC 4515.
            if ('\\' === $caractere) {
                ++$i;
                continue;
            }

            if ('(' === $caractere) {
                ++$profondeur;
            } elseif (')' === $caractere) {
                if (--$profondeur < 0) {
                    return false;
                }
            }
        }

        return 0 === $profondeur;
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

        // L'adaptateur est construit à la main plutôt que par Ldap::create() :
        // il donne accès à la connexion brute, seule façon de lire le message de
        // diagnostic du serveur. Symfony le lit puis le jette lorsque l'erreur
        // est « Invalid credentials » — or c'est précisément là qu'Active
        // Directory explique son refus.
        $adaptateur = new Adapter($configuration);
        $this->adaptateur = $adaptateur;

        return new Ldap($adaptateur);
    }

    /**
     * Ce qu'Active Directory dit de l'état du compte.
     *
     * Un mot de passe qui ouvre une session Windows mais que l'annuaire refuse
     * en liaison simple n'est pas un mot de passe faux : c'est presque toujours
     * une restriction portée par le compte. Le compte de service peut la lire —
     * autant la montrer plutôt que de laisser deviner.
     *
     * Renvoie null sur un annuaire qui n'expose pas ces attributs, OpenLDAP par
     * exemple : il n'y a alors rien à dire.
     *
     * Publique parce que c'est une fonction pure de l'entrée : cela permet de
     * la vérifier sur des comptes fictifs portant chaque restriction, sans
     * dépendre d'un annuaire qui les expose.
     *
     * @return array{etape: string, ok: bool, detail: string}|null
     */
    public function etatDuCompte(Entry $entree): ?array
    {
        $controle = $entree->getAttribute('userAccountControl')[0] ?? null;
        $postes = $entree->getAttribute('logonWorkstations')[0] ?? null;
        $groupes = $entree->getAttribute('memberOf') ?? [];

        if (null === $controle && null === $postes && [] === $groupes) {
            return null;
        }

        $constats = [];
        $bloquant = false;

        if (null !== $controle) {
            // Bits documentés par Microsoft ; seuls ceux qui empêchent une
            // liaison simple sont retenus.
            $drapeaux = [
                0x0002 => 'le compte est désactivé',
                0x0010 => 'le compte est verrouillé',
                0x0040 => 'le mot de passe ne peut pas être changé',
                0x40000 => 'une carte à puce est exigée : la liaison par mot de passe est alors toujours refusée',
                0x800000 => 'le mot de passe a expiré',
            ];

            foreach ($drapeaux as $bit => $libelle) {
                if (((int) $controle & $bit) === $bit) {
                    $constats[] = $libelle;
                    if (0x0040 !== $bit) {
                        $bloquant = true;
                    }
                }
            }
        }

        if (null !== $postes && '' !== trim($postes)) {
            $constats[] = \sprintf(
                'la connexion est restreinte aux postes « %s » — si le serveur MSSub n\'y figure pas, '
                .'toute liaison depuis celui-ci sera refusée alors que la session Windows fonctionne',
                $postes,
            );
            $bloquant = true;
        }

        foreach ($groupes as $groupe) {
            if (str_contains(mb_strtolower((string) $groupe), 'protected users')) {
                $constats[] = 'le compte appartient au groupe « Protected Users », '
                    .'qui interdit l\'authentification par liaison simple LDAP';
                $bloquant = true;
            }
        }

        if ([] === $constats) {
            return ['etape' => 'État du compte', 'ok' => true, 'detail' => 'Aucune restriction lue sur le compte.'];
        }

        return [
            'etape' => 'État du compte',
            'ok' => !$bloquant,
            'detail' => ucfirst(implode(" ;\n", $constats)).'.',
        ];
    }

    /**
     * Le message de diagnostic du serveur après un échec.
     *
     * Active Directory y place un code qui distingue un mot de passe faux d'un
     * compte désactivé, expiré, verrouillé, ou interdit à cette heure — autant
     * de situations qu'un simple « identifiants invalides » confond.
     */
    private function messageDiagnostic(): ?string
    {
        $connexion = $this->adaptateur?->getConnection();

        if (!$connexion instanceof ExtConnection) {
            return null;
        }

        $ressource = $connexion->getResource();
        if (null === $ressource) {
            return null;
        }

        $message = '';
        @ldap_get_option($ressource, \LDAP_OPT_DIAGNOSTIC_MESSAGE, $message);

        return '' === $message ? null : $message;
    }

    /**
     * Traduit le code de refus d'Active Directory.
     *
     * Ces codes hexadécimaux sont documentés par Microsoft mais illisibles pour
     * qui n'a pas la table sous les yeux ; or ils portent toute l'information.
     */
    private function expliquerRefus(?string $diagnostic): string
    {
        if (null === $diagnostic) {
            return "Refusé par l'annuaire, sans précision — c'est le cas d'OpenLDAP, "
                ."qui ne détaille jamais un refus.\n\n"
                ."Si le mot de passe ouvre bien une session Windows, ce n'est probablement pas lui : "
                ."regardez l'étape « État du compte » ci-dessus, et le journal de sécurité du contrôleur "
                ."de domaine (événement 4625, dont le sous-état donne la raison exacte).";
        }

        $codes = [
            '525' => 'ce compte n\'existe pas.',
            '52e' => 'le mot de passe est incorrect pour ce compte. '
                .'Vérifiez que l\'identifiant recherché désigne bien la même personne '
                .'que le mot de passe saisi — la recherche a pu trouver un homonyme.',
            '530' => 'la connexion n\'est pas autorisée à cette heure-ci pour ce compte.',
            '531' => 'la connexion n\'est pas autorisée depuis ce poste. '
                .'L\'attribut « logonWorkstations » du compte restreint les machines, '
                .'et le serveur MSSub n\'en fait pas partie.',
            '532' => 'le mot de passe a expiré.',
            '533' => 'le compte est désactivé.',
            '701' => 'le compte a expiré.',
            '773' => 'le compte doit changer son mot de passe avant de pouvoir s\'authentifier.',
            '775' => 'le compte est verrouillé, probablement après plusieurs échecs.',
        ];

        foreach ($codes as $code => $explication) {
            if (1 === preg_match('/data\s+'.$code.'\b/i', $diagnostic)) {
                return 'Refusé par l\'annuaire : '.$explication."\n\nMessage du serveur : ".$diagnostic;
            }
        }

        return 'Refusé par l\'annuaire.'."\n\nMessage du serveur : ".$diagnostic;
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
     * Rejoue une authentification en rendant compte de chaque étape.
     *
     * Le message d'échec présenté à l'utilisateur est volontairement identique
     * dans tous les cas : distinguer « ce compte n'existe pas » de « mot de
     * passe faux » révélerait quels identifiants existent. Mais cette prudence
     * prive l'administrateur de tout moyen de comprendre, alors qu'il est le
     * seul à pouvoir corriger la configuration. D'où ce diagnostic, réservé à
     * l'administration, qui dit exactement où la chaîne casse.
     *
     * @return list<array{etape: string, ok: bool, detail: string}>
     */
    public function diagnose(string $username, string $password = ''): array
    {
        $etapes = [];

        if (!$this->isEnabled()) {
            return [['etape' => 'Annuaire', 'ok' => false, 'detail' => 'L\'authentification par annuaire est désactivée.']];
        }

        try {
            $ldap = $this->ouvrir();
        } catch (LdapException $e) {
            return [['etape' => 'Connexion', 'ok' => false, 'detail' => $this->expliquer($e->getMessage())]];
        }

        try {
            $ldap->bind($this->searchDn(), $this->searchPassword());
            $etapes[] = ['etape' => 'Compte de service', 'ok' => true, 'detail' => \sprintf(
                'Liaison établie en %s avec %s.',
                $this->encryption(),
                $this->searchDn(),
            )];
        } catch (LdapException $e) {
            $etapes[] = ['etape' => 'Compte de service', 'ok' => false, 'detail' => $this->expliquer($e->getMessage())];

            return $etapes;
        }

        $filtre = \sprintf(
            '(&(%s=%s)%s)',
            $this->uidKey(),
            ldap_escape($username, '', \LDAP_ESCAPE_FILTER),
            $this->extraFilter(),
        );

        try {
            $entrees = $ldap->query($this->baseDn(), $filtre, ['maxItems' => 5])->execute();
            $nombre = \count($entrees);
        } catch (LdapException $e) {
            $etapes[] = ['etape' => 'Recherche', 'ok' => false, 'detail' => $this->expliquer($e->getMessage())];

            return $etapes;
        }

        if (0 === $nombre) {
            $etapes[] = ['etape' => 'Recherche', 'ok' => false, 'detail' => \sprintf(
                "Aucune entrée pour le filtre %s sous %s.\n"
                ."C'est la cause la plus fréquente d'un « identifiants invalides » alors que le compte existe : "
                ."Active Directory nomme l'identifiant de connexion « sAMAccountName », là où OpenLDAP utilise « uid ». "
                ."Vérifiez aussi que la base de recherche englobe l'unité d'organisation du compte.",
                $filtre,
                $this->baseDn(),
            )];

            return $etapes;
        }

        if ($nombre > 1) {
            $etapes[] = ['etape' => 'Recherche', 'ok' => false, 'detail' => \sprintf(
                '%d entrées correspondent à %s : l\'identifiant est ambigu et la connexion sera refusée. '
                .'Restreignez la base de recherche ou ajoutez un filtre.',
                $nombre,
                $filtre,
            )];

            return $etapes;
        }

        $entree = $entrees[0];
        $etapes[] = ['etape' => 'Recherche', 'ok' => true, 'detail' => \sprintf('Compte trouvé : %s', $entree->getDn())];

        $etat = $this->etatDuCompte($entree);
        if (null !== $etat) {
            $etapes[] = $etat;
        }

        $etapes[] = ['etape' => 'Attributs', 'ok' => true, 'detail' => \sprintf(
            'Nom affiché : %s — courriel : %s',
            $this->attribute($entree, ['displayName', 'cn', 'givenName']) ?? '(absent)',
            $this->attribute($entree, ['mail', 'userPrincipalName']) ?? '(absent)',
        )];

        if ('' === $password) {
            $etapes[] = ['etape' => 'Mot de passe', 'ok' => true, 'detail' => 'Non vérifié : aucun mot de passe fourni.'];

            return $etapes;
        }

        try {
            $ldap->bind($entree->getDn(), $password);
            $etapes[] = ['etape' => 'Mot de passe', 'ok' => true, 'detail' => 'Accepté par l\'annuaire.'];
        } catch (LdapException $e) {
            $etapes[] = ['etape' => 'Mot de passe', 'ok' => false,
                'detail' => $this->expliquerRefus($this->messageDiagnostic())];

            return $etapes;
        }

        // Retour au compte de service : l'utilisateur n'a généralement pas le
        // droit de parcourir l'unité des groupes.
        try {
            $ldap->bind($this->searchDn(), $this->searchPassword());
            $groupes = $this->groupsOf($ldap, $entree);
            $etapes[] = ['etape' => 'Groupes', 'ok' => [] !== $groupes, 'detail' => [] === $groupes
                ? 'Aucun groupe lu. Le compte entrera en lecture seule. Vérifiez que le compte de service '
                    .'peut lire les groupes, et que la base de recherche les englobe.'
                : implode(', ', $groupes)];
        } catch (LdapException $e) {
            $etapes[] = ['etape' => 'Groupes', 'ok' => false, 'detail' => $e->getMessage()];
        }

        return $etapes;
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
            'Bad search filter' => 'Le filtre de recherche est invalide. Vérifiez le filtre additionnel : '
                .'il doit être une expression complète, par exemple « objectClass=user » ou '
                .'« (&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2))) ».',
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
            // Le motif détaillé n'est jamais rendu à l'utilisateur — il dirait
            // si le compte existe — mais il a toute sa place dans les journaux.
            $this->logger->info('Authentification LDAP refusée.', [
                'utilisateur' => $username,
                'motif' => $e->getMessage(),
                'diagnostic' => $this->messageDiagnostic(),
            ]);

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
