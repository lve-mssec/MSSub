# MSSub

MSSub (MSSec Subnets) est un portail web pour gérer les plans d'adressage réseaux de MSSEC.

## Démarrage

Un runtime Docker suffit — ni PHP ni Composer ne sont requis sur le poste.

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate
```

| Service | Adresse | Rôle |
| --- | --- | --- |
| `app` | http://localhost:8080 | Apache 2.4 + PHP 8.3, Symfony 7.4 LTS |
| `db` | `localhost:13306` | MariaDB 11.4 |
| `adminer` | http://localhost:8081 | inspection de la base |

## Se connecter

Charger le jeu de démonstration, puis ouvrir http://localhost:8080 :

```bash
docker compose exec app php bin/console doctrine:fixtures:load
```

Compte de démonstration : `loic` / `MotDePasseDeTest2026` — **développement uniquement**.
Pour un compte réel :

```bash
docker compose exec app php bin/console app:user:create <identifiant> --admin
```

## Tests

```bash
docker compose exec app php bin/phpunit
```

Les tests d'intégration s'exécutent sur la base `mssub_test`, créée par
`docker/mariadb/init/02-test-database.sql` au premier démarrage du conteneur.
Sur une installation déjà initialisée, l'appliquer à la main :

```bash
docker compose exec -T db mariadb -uroot -proot < docker/mariadb/init/02-test-database.sql
docker compose exec app php bin/console --env=test doctrine:schema:create
```

## Organisations et sites

Le référentiel se lit à deux niveaux : **organisation**, puis **site**. Une barre
de périmètre en tête de page permet de se placer dans l'un ou l'autre ; le choix
est retenu en session et filtre les plans d'adressage, le tableau de bord et les
exports CSV. La recherche d'adresse suit l'organisation mais ignore le site :
on cherche une adresse justement parce qu'on ignore d'où elle vient.

**Un réseau appartient au site de son bloc parent** s'il n'en déclare pas.
Découper `10.10.0.0/16` « Paris » en `/24` n'oblige donc pas à répéter « Paris »
sur chacun, et un plan importé ne le fera de toute façon pas. L'arborescence
indique « (hérité) » pour distinguer un rattachement déduit d'un rattachement
saisi.

Chaque site a sa fiche — `/sites/{id}` — avec son occupation agrégée, ses
réseaux, ses VLAN et ses équipements. Les blocs conteneurs sont exclus du total :
additionner un bloc et les sous-réseaux qu'il contient compterait deux fois les
mêmes adresses.

## Administration

Tout ce qui se configurait par variables d'environnement se règle désormais
depuis le portail, sous **Administration** (réservé à `ROLE_ADMIN`) :
organisations, sites, VLAN, équipements et interfaces, comptes, et les
paramètres d'authentification.

Les paramètres suivent trois niveaux, du plus fort au plus faible : **la base**,
puis **la variable d'environnement**, puis le défaut du code. Une installation
neuve fonctionne donc sans qu'aucun écran n'ait été rempli, et vider un champ
rend la main à la configuration de déploiement.

Les secrets — mot de passe du compte de service LDAP, secret client OIDC — sont
chiffrés en base (XSalsa20-Poly1305) avec une clé dérivée de `APP_SECRET`, et
ne sont jamais renvoyés au navigateur. **Changer `APP_SECRET` les rend
illisibles** : l'application retombe alors sur les variables d'environnement et
il faut les ressaisir.

Si l'administration devient inaccessible — plus aucun compte administrateur —
la porte de secours reste la ligne de commande :

```bash
docker compose exec app php bin/console app:user:create <identifiant> --admin
```

## Authentification

Trois sources cohabitent, toutes désactivées sauf la première :

- **Comptes locaux** — `app:user:create`, hachage Argon2id. C'est le compte de
  secours : il reste utilisable quand l'annuaire ou le SSO sont indisponibles.
- **LDAP / Active Directory** — `LDAP_ENABLED=true` dans `.env.local`. Le mot de
  passe n'est jamais comparé par MSSub : c'est l'annuaire qui tranche, par un
  bind. Rien n'est stocké en base pour ces comptes.
- **OIDC** — `OIDC_ENABLED=true` et les points d'entrée du fournisseur. Testé
  avec un flux code d'autorisation ; l'URL de retour à déclarer côté fournisseur
  est `https://<hôte>/connexion/sso/retour`.

Les comptes locaux et d'annuaire partagent le **même formulaire** : personne n'a
à savoir où vit son compte. Un compte SSO refuse en revanche le mot de passe —
accepter une seconde voie d'entrée viderait la délégation de son sens.

Les rôles viennent des groupes, via `APP_ROLE_MAP` (nom court du groupe, casse
ignorée). Un groupe inconnu n'interdit pas l'accès : il donne la lecture seule.

### Annuaire de développement

```bash
docker compose --profile annuaire up -d
```

Deux comptes : `jdupont` / `motdepasse1` (groupe `mssub-admins`) et `mmartin` /
`motdepasse2` (groupe `mssub-operateurs`). Les tests LDAP s'ignorent d'eux-mêmes
si ce conteneur n'est pas démarré.

## Import et export

L'export CSV utilise les colonnes que l'import sait relire : un plan exporté,
retouché dans un tableur, se recharge sans rien perdre. Les intitulés courants
sont reconnus à l'import (« Réseau », « Hostname », « Commentaire »…).

L'import se fait en deux temps : **Simuler** lit le fichier et décrit ce qui se
passerait sans rien écrire ; **Importer réellement** n'écrit que si aucune
erreur ne subsiste. Les avertissements — statut, site ou VLAN inconnu — ne
bloquent pas et sont listés à part.

Les exports DNS et DHCP sont des **fragments à inclure**, pas des fichiers
complets : ni SOA, ni NS, ni options globales de dhcpd. Ces éléments
appartiennent à l'infrastructure, et les inventer produirait un fichier
d'apparence valide qui écraserait la configuration réelle.

## Conventions

Le stockage des adresses IP, la hiérarchie des sous-réseaux et le découpage des
services d'allocation sont documentés dans les classes concernées
(`src/Doctrine/Type/IpAddressType.php`, `src/Repository/SubnetRepository.php`,
`src/Service/`). La charte graphique fait foi pour toute interface : voir
`Doctrine/charte graphique.md`.
