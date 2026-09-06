# Guide de l'administrateur

Tout ce qui suit se trouve sous **Administration**, réservé au rôle
`ROLE_ADMIN`.

## Où vivent les réglages

Trois niveaux, du plus fort au plus faible :

1. **La base** — ce que vous saisissez dans l'interface ;
2. **la variable d'environnement** — `.env.local`, figée au déploiement ;
3. **le défaut du code**.

Une installation neuve fonctionne sans qu'aucun écran n'ait été rempli. Vider un
champ dans l'interface ne l'efface pas : cela **rend la main** à la variable
d'environnement. C'est ce qui permet de revenir à une configuration de
déploiement connue quand une saisie a cassé quelque chose.

### Les secrets

Le mot de passe du compte de service LDAP et le secret client OIDC sont chiffrés
en base (XSalsa20-Poly1305) et **ne sont jamais renvoyés au navigateur**. Un
champ de mot de passe laissé vide conserve la valeur enregistrée ; pour la
changer, saisissez la nouvelle.

> La clé de chiffrement dérive d'`APP_SECRET`. **Le changer rend les secrets
> enregistrés illisibles** : l'application le détecte, retombe sur les variables
> d'environnement et l'écrit dans les journaux, mais il faudra les ressaisir.
> Sauvegardez `APP_SECRET` avec la base.

Une valeur altérée directement en base est rejetée, jamais déchiffrée à moitié.

## Comptes et rôles

**Administration → Comptes.**

| Rôle | Ce qu'il donne |
| --- | --- |
| `ROLE_USER` | Lecture, recherche, export. Attribué d'office à tout compte authentifié. |
| `ROLE_OPERATOR` | Écriture du référentiel, import, journal. |
| `ROLE_ADMIN` | Suppression et administration. |

Les rôles sont hiérarchiques : un administrateur est opérateur, un opérateur est
lecteur.

### Trois protections

- Vous ne pouvez pas **supprimer votre propre compte** (le bouton n'apparaît
  même pas sur votre ligne).
- Le **dernier administrateur actif** ne peut ni perdre son rôle, ni être
  désactivé. Un second administrateur *désactivé* ne lève pas le verrou : il ne
  peut pas se connecter, il ne garantit donc aucun accès.
- Un **compte désactivé** est refusé quelle que soit sa source
  d'authentification.

### Si l'administration devient inaccessible

La porte de secours est la ligne de commande, sur le serveur :

```bash
cd /var/www/mssub
sudo -u www-data php bin/console app:user:create <identifiant> --admin
```

La commande crée le compte ou met à jour l'existant. C'est la raison d'être du
compte local : il fonctionne quand l'annuaire et le SSO ne répondent plus.

### Comptes externes

Un compte venu de l'annuaire ou du SSO ne porte **aucun mot de passe** en base.
Son identifiant, son nom et son courriel sont réécrits à chaque connexion depuis
la source ; ses rôles aussi, s'il appartient à un groupe reconnu. Les modifier
dans l'interface n'a donc qu'un effet temporaire — c'est la correspondance des
rôles qu'il faut ajuster.

Un compte **local existant n'est jamais converti** par un homonyme d'annuaire :
il garde son mot de passe et ses rôles.

## Annuaire LDAP / Active Directory

**Administration → Annuaire.**

| Champ | Remarque |
| --- | --- |
| Serveur | `ldap://serveur:389` ou `ldaps://serveur:636`. |
| Chiffrement | LDAPS, StartTLS, ou aucun. Voir ci-dessous. |
| Vérification du certificat | À laisser active hors mise au point. |
| Autorité de certification | Chemin d'un fichier PEM, si votre annuaire utilise une autorité interne. |
| Base de recherche | `dc=exemple,dc=local` |
| Compte de service | Sert à retrouver le DN d'un utilisateur et à lire ses groupes. Un compte en lecture seule suffit. |
| Attribut d'identifiant | `uid` pour OpenLDAP, `sAMAccountName` pour Active Directory. |
| Filtre additionnel | Facultatif. `objectClass=user` et `(objectClass=user)` sont équivalents : les parenthèses extérieures sont ajoutées si vous les omettez. |

### Active Directory refuse les liaisons en clair

Depuis Windows Server 2019, un contrôleur de domaine rejette par défaut les
liaisons simples non chiffrées :

```
Strong(er) authentication required. The server requires binds to turn on
integrity checking if SSL\TLS are not already active on the connection
```

Deux réponses, au choix : **LDAPS** (`ldaps://serveur:636`) ou **StartTLS**
(`ldap://serveur:389` avec le chiffrement StartTLS). Les deux conviennent ; le
second évite d'ouvrir un port supplémentaire.

Vient alors le mur suivant, tout aussi systématique :

```
Can't contact LDAP server
error:0A000086:SSL routines::certificate verify failed (self-signed certificate)
```

Le certificat du contrôleur est signé par l'autorité interne du domaine, que le
serveur MSSub ne connaît pas. **La bonne réponse est de lui faire connaître
cette autorité**, pas de renoncer à vérifier.

Exporter le certificat de l'autorité depuis un contrôleur de domaine :

```powershell
certutil -ca.cert C:\ad-ca.crt
```

puis, sur le serveur MSSub, au choix :

```bash
# Soit pour tout le système — recommandé, cela vaut aussi pour les autres outils
sudo cp ad-ca.crt /usr/local/share/ca-certificates/ad-interne.crt
sudo update-ca-certificates

# Soit pour MSSub seulement : indiquer le chemin dans le champ
# « Autorité de certification » de l'écran Annuaire.
```

Le réglage « ne pas vérifier » existe pour dépanner. Il garde la liaison
chiffrée mais cesse d'authentifier le serveur : n'importe quelle machine
capable de se faire passer pour le contrôleur recevrait alors les mots de passe
de vos utilisateurs. À ne pas laisser en place.

> Ces options TLS sont **globales au processus** — une particularité du client
> OpenLDAP. Après les avoir modifiées, rechargez Apache
> (`sudo systemctl reload apache2`) : un travailleur déjà démarré conserverait
> sinon les anciennes jusqu'à son recyclage.

### « Identifiants invalides » alors que le compte existe

La page de connexion refuse volontairement de dire *pourquoi* elle refuse :
distinguer un compte absent d'un mot de passe faux révélerait quels
identifiants existent. Cette prudence, juste pour un visiteur, prive
l'administrateur de tout moyen de comprendre — d'où le **diagnostic** au bas de
l'écran Annuaire.

Saisissez un identifiant, éventuellement son mot de passe, et le diagnostic
rejoue la chaîne étape par étape : compte de service, recherche, attributs lus,
vérification du mot de passe, groupes, et rôles qui en découleraient.

Les deux causes les plus fréquentes, quand « le compte est pourtant bon » :

**L'attribut d'identifiant.** Active Directory nomme l'identifiant de connexion
`sAMAccountName` ; OpenLDAP utilise `uid`. Avec le mauvais, la recherche ne
trouve rien et la connexion est refusée sans que le mot de passe soit même
soumis. Le diagnostic affiche le filtre exact employé.

**Un compte local homonyme.** Un compte local porte le même identifiant : il
prime sur l'annuaire, et le mot de passe du domaine est comparé à un hachage
local qui ne correspond pas. C'est délibéré — le compte de secours ne doit pas
pouvoir être absorbé par un homonyme du domaine — mais il faut le savoir. Le
diagnostic le signale explicitement. Renommez ou supprimez le compte local si
vous vouliez authentifier cette personne par l'annuaire.

**Le filtre additionnel.** Il vient s'ajouter à la recherche par identifiant.
Pour ne retenir que les comptes actifs d'un Active Directory :

```
(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))
```

Un filtre aux parenthèses déséquilibrées est ignoré, avec une trace dans les
journaux : une recherche trop large fonctionne, une requête invalide refuserait
tout le monde. Le diagnostic affiche le filtre réellement employé.

Vérifiez aussi que la **base de recherche** englobe l'unité d'organisation du
compte : `dc=exemple,dc=local` couvre tout le domaine, `ou=Paris,dc=exemple,dc=local`
ne couvre que Paris.

**Testez la connexion** avant de sortir de l'écran : le bouton vérifie que
l'annuaire répond, que le compte de service est accepté, et compte les comptes
visibles. Sans cela, une erreur de configuration ne se découvrirait qu'à la
première tentative de connexion d'un utilisateur, qui n'aurait aucun moyen de
comprendre ce qui se passe.

### Ce que MSSub fait et ne fait pas

Le mot de passe d'un utilisateur **n'est jamais comparé par l'application** :
c'est l'annuaire qui tranche, par un *bind*. Rien n'est stocké, rien ne peut
fuir de ce côté.

Le compte de service, lui, sert à deux choses : trouver le DN complet de
l'utilisateur (« jdupont » peut vivre sous n'importe quelle unité
d'organisation), puis **relire ses groupes après vérification** — un utilisateur
n'a généralement pas le droit de parcourir l'unité des groupes, et lire sous son
identité renverrait une liste vide, laissant tout le monde en lecture seule.

Un identifiant qui remonte **deux entrées** est refusé : choisir au hasard ferait
entrer la mauvaise personne.

### Comptes locaux et annuaire sur le même écran

Les deux partagent le formulaire de connexion : personne n'a à savoir où vit son
compte. C'est l'identifiant saisi qui détermine le mode de vérification. Un
compte SSO, en revanche, refuse le mot de passe — accepter une seconde voie
d'entrée viderait la délégation de son sens.

## Fournisseur d'identité (OIDC)

**Administration → Fournisseur d'identité.**

L'écran affiche l'**URL de retour** à déclarer côté fournisseur, à l'identique :
`https://<votre-hôte>/connexion/sso/retour`.

| Champ | Exemple |
| --- | --- |
| URL d'autorisation | `https://idp/realms/mssec/protocol/openid-connect/auth` |
| URL du jeton | `.../token` |
| URL des informations utilisateur | `.../userinfo` |
| Portées | `openid profile email groups` — `openid` est indispensable |
| Revendication d'identifiant | `preferred_username` (Keycloak), `upn` (Entra ID) |
| Revendication de groupes | `groups` — à demander explicitement chez la plupart des fournisseurs |

Le bouton de connexion n'apparaît que si l'option est active **et** que
l'identifiant client et les deux premières URL sont renseignés : un bouton menant
à une erreur vaut moins que pas de bouton.

Le client est générique : Entra ID, Keycloak, ADFS et Okta exposent les mêmes
points d'entrée.

### SAML

**SAML n'est pas implémenté**, ni son écran ni le protocole. Ce n'est pas un
réglage manquant : SAML ne partage rien avec OIDC — bibliothèque dédiée,
certificats de signature, échange de métadonnées, déconnexion propre. Les
fournisseurs qui parlent SAML parlent presque tous OIDC ; c'est la voie
recommandée.

## Correspondance des rôles

**Administration → Correspondance des rôles.** Une ligne par groupe :

```
mssub-admins     = ROLE_ADMIN
mssub-operateurs = ROLE_OPERATOR
# les lignes commençant par # sont ignorées
```

Le nom court du groupe suffit, la casse est ignorée. Elle vaut pour l'annuaire
comme pour le SSO.

- Un rôle inconnu est **refusé** et la ligne signalée : accepter n'importe quel
  libellé écrirait un rôle inexistant et laisserait le groupe concerné en lecture
  seule sans que personne comprenne pourquoi.
- Un compte dont aucun groupe n'est reconnu entre en **lecture seule**. Un
  mapping incomplet dégrade vers le moindre privilège, il ne bloque pas l'accès.
- Les rôles sont **recalculés à chaque connexion** : retirer quelqu'un d'un
  groupe dans l'annuaire lui retire le droit ici, sans intervention.

## Référentiel

### Organisations

Le plus haut niveau ; chacune porte son propre plan d'adressage.

Une organisation **ne se supprime que vide**. Elle porte des années de
référentiel : l'effacer avec son contenu sur un clic serait irréparable.
L'interface indique combien de réseaux et de sites la retiennent.

### Sites

Rattachés à une organisation, code unique en son sein — c'est ce code que l'import
reconnaît.

Supprimer un site ne bloque pas : ses réseaux, VLAN et équipements s'en
détachent. Un site qui ferme ne doit pas empêcher de garder l'historique de ses
réseaux, qui deviennent simplement non localisés. L'interface dit combien de
réseaux sont concernés.

### VLAN

Le numéro va de 1 à 4094 ; 0 et 4095 sont réservés par 802.1Q. L'unicité se joue
**au sein d'un site** : deux sites peuvent avoir un VLAN 110. Un VLAN sans site
est transverse, et n'est alors plus contraint à l'unicité.

Le doublon est refusé par la base, pas seulement par une vérification préalable :
deux saisies simultanées passeraient sinon toutes les deux.

### Équipements et interfaces

Les interfaces se gèrent sur la fiche de l'équipement — une interface n'existe
que portée par un équipement.

Supprimer un équipement supprime ses interfaces mais **conserve les adresses
documentées** : elles décrivent le plan d'adressage, pas le matériel, et
survivent au remplacement d'un boîtier.

## Journal d'audit

**Journal**, accessible aux opérateurs.

Toute écriture passant par l'application y figure — y compris celles venues d'un
import ou d'une commande, car le journal est alimenté au niveau de la couche de
persistance et non par des appels que l'on pourrait oublier d'écrire.

Sont tracés : créations, modifications (avec le détail champ par champ, valeur
avant → après), suppressions, imports, exports, connexions, **échecs de
connexion** et déconnexions.

Deux choix à connaître :

- Un **hachage de mot de passe** n'y figure jamais : le champ est remplacé par
  « (masqué) ».
- Un **échec de connexion** ne dit pas pourquoi il a échoué. Distinguer un compte
  absent d'un mot de passe faux révélerait quels identifiants existent — ce que
  la page de connexion se garde justement de faire.

Le libellé d'un objet supprimé est figé au moment du fait : le journal reste
lisible des années plus tard, quand la ligne concernée n'existe plus.

## Sauvegarde et restauration

```bash
mysqldump --single-transaction -u mssub -p mssub | gzip > mssub-$(date +%F).sql.gz
cp /var/www/mssub/.env.local mssub-env-$(date +%F)
```

Les deux vont ensemble : restaurer la base avec un autre `APP_SECRET` rend les
secrets LDAP et OIDC illisibles. Le reste du référentiel sera intact.
