# Guide du développeur

## Environnement

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console doctrine:fixtures:load
```

http://localhost:8080 — `loic` / `MotDePasseDeTest2026` (fixtures, développement
uniquement). Adminer sur http://localhost:8081, MariaDB sur le port 13306.

Annuaire OpenLDAP de développement, à la demande :

```bash
docker compose --profile annuaire up -d
```

Deux comptes : `jdupont` / `motdepasse1` (groupe `mssub-admins`) et `mmartin` /
`motdepasse2` (`mssub-operateurs`).

Pour exercer LDAPS et StartTLS — les seuls modes qu'accepte un Active Directory
durci — il faut des certificats, engendrés localement et non versionnés :

```bash
./docker/openldap/engendrer-certificats.sh
docker compose --profile annuaire up -d
```

Les tests correspondants s'ignorent d'eux-mêmes s'ils sont absents.

### Tests

```bash
docker compose exec app php bin/phpunit
docker compose exec app php bin/phpunit tests/Service/SubnetAllocatorTest.php
```

Les tests d'intégration parlent à une vraie base, `mssub_test`, créée par
`docker/mariadb/init/02-test-database.sql` au premier démarrage du conteneur.
Sur une installation déjà initialisée :

```bash
docker compose exec -T db mariadb -uroot -proot < docker/mariadb/init/02-test-database.sql
docker compose exec app php bin/console --env=test doctrine:schema:update --force --complete
```

Les tests LDAP s'ignorent d'eux-mêmes si le conteneur `annuaire` ne tourne pas,
en disant quelle commande lancer.

### Vérifier une autre version de PHP

La version est un paramètre de construction, ce qui permet de valider une
distribution cible avant de l'annoncer supportée :

```bash
docker build --build-arg PHP_VERSION=8.4 -f docker/php/Dockerfile -t mssub-app:php84 .
docker run --rm --network mssub_default -v "$PWD":/var/www/html -w /var/www/html \
    mssub-app:php84 sh -c 'rm -rf var/cache/test; php bin/phpunit'
```

L'environnement de développement tourne sur **PHP 8.4**, la version de Debian 13 :
développer sur une version antérieure ferait découvrir les dépréciations chez le
client plutôt qu'ici.

## Décisions structurantes

Ces choix expliquent la forme du code ; les remettre en cause demande de
comprendre ce qu'ils évitent.

### Les adresses en `VARBINARY(16)`

`App\Doctrine\Type\IpAddressType` présente une adresse comme `10.0.0.1` côté PHP
et la stocke en binaire de longueur fixe.

Ce n'est pas une optimisation. En `VARCHAR`, `'10.0.0.9' > '10.0.0.10'` : tout
tri et toute recherche de plage seraient faux. En binaire, l'ordre
lexicographique **est** l'ordre numérique, pour IPv4 comme pour IPv6.

Conséquence à connaître : l'hydratation scalaire de Doctrine court-circuite la
conversion du type. Un `SELECT a.address` en DQL renvoie du binaire brut. Voir
`IpAddressRepository::findTakenAddresses()`, qui demande la conversion
explicitement.

### La hiérarchie par bornes, pas par parcours

Un `Subnet` porte `parent`, mais la vérité est dans le couple indexé
`(network_address, last_address)`. Le « longest prefix match » et la détection de
chevauchement sont des requêtes uniques, pas des parcours d'arbre.
`parent` sert à l'affichage et à la cohérence référentielle.

### Le site effectif

`Subnet::getEffectiveSite()` remonte la hiérarchie jusqu'au premier ancêtre
portant un site. Sans cette règle, le regroupement de l'arborescence, les fiches
de site et le filtrage par périmètre seraient vides : un plan bien tenu ne répète
pas le site sur chaque découpe, et un plan importé ne le fera jamais.

La remontée est bornée à 64 niveaux : une hiérarchie corrompue par une reprise de
données ne doit pas faire tourner l'affichage en rond.

### Calculateurs purs, façade impure

`SubnetAllocator` et `IpAllocator` ne connaissent que des plages d'adresses — ni
entité, ni dépôt. `AllocationService` leur fournit l'état du référentiel et
traduit leurs réponses en refus explicites.

C'est ce qui rend testables les cas tordus — occupant mal aligné, bloc plein,
`/31` point à point, trou d'un seul réseau — sans base de données.

Deux propriétés à préserver :

- le curseur de `SubnetAllocator` avance de bloc en bloc et saute après chaque
  occupant ; chercher un `/30` dans un `/16` coûte le nombre de réseaux posés,
  pas la taille de l'espace ;
- `IpAllocator` applique les vraies règles : réseau et diffusion exclus en IPv4,
  sauf sur `/31` (RFC 3021) et `/32`, rien d'exclu en IPv6.

### L'arithmétique sans extension

`IpTools` manipule des octets, sans `gmp` ni `bcmath` : rien de plus à installer
en production, et le même code sert IPv4 et IPv6. `sizeOf()` rend une chaîne, un
`/64` dépassant ce qu'un entier PHP peut porter.

### Le journal alimenté par Doctrine

`AuditListener` écoute `onFlush` et `postFlush`. Un journal qu'on peut oublier
d'alimenter ne vaut rien : ici toute écriture passant par l'ORM est tracée, y
compris depuis une commande ou un import.

Les lignes sont posées en `postFlush`, seul moment où une création connaît son
identifiant ; une suppression, elle, est relevée en `onFlush`, tant qu'elle porte
encore le sien.

### Les paramètres à trois niveaux

`App\Service\Settings` : base, puis variable d'environnement, puis défaut du
code. Une chaîne vide **efface** le paramètre au lieu d'enregistrer du vide,
ce qui rend la main à l'environnement.

Les secrets passent par `SecretBox` (XSalsa20-Poly1305, clé dérivée
d'`APP_SECRET`). Une valeur illisible retombe sur l'environnement en le
signalant, plutôt que de servir du faux.

## Organisation du code

```
src/
├── Command/            app:user:create
├── Controller/
│   └── Admin/          section d'administration
├── DataFixtures/       jeu de démonstration
├── Doctrine/Type/      IpAddressType
├── Entity/             modèle du domaine
│   └── Traits/         horodatage partagé
├── Enum/               statuts, sources d'authentification, actions d'audit
├── EventListener/      audit des écritures et des connexions
├── Exception/          AllocationException
├── Form/
│   └── Admin/
├── Repository/         requêtes, dont celles sur les bornes binaires
├── Security/           LDAP, OIDC, authentificateur, rôles, provisionnement
└── Service/
    ├── Export/         CSV, zones DNS, configuration dhcpd
    └── Import/         lecture de tableur, rapport, importeur
```

## Conventions

- **Français** pour les commentaires, les libellés et les messages ; **anglais**
  pour le code. Les commentaires disent *pourquoi*, pas *quoi*.
- `declare(strict_types=1)` partout.
- Services `final`, dépendances en constructeur, propriétés `readonly`.
- Les routes sont en français : `/reseaux`, `/administration/comptes`.
- La charte graphique (`Doctrine/charte graphique.md`) fait foi pour toute
  interface. Les jetons CSS ne se redéfinissent pas hors de `assets/styles/app.css`.

## Pièges rencontrés

Ils ont tous coûté du temps ; ils sont documentés ici pour ne pas être repayés.

| Piège | Ce qui se passe |
| --- | --- |
| **CSRF sans état** | Symfony 7.4 rend un marqueur `csrf-token` remplacé par du JavaScript. Sans script, la page de connexion s'affiche mais refuse toute soumission. `config/packages/csrf.yaml` impose le CSRF de session. |
| **`APP_ENV` dans les tests** | Le recipe ne pose que `<server>` ; `KernelTestCase` lit `$_ENV` en premier et démarrait le noyau de *développement*. `phpunit.dist.xml` pose les deux. |
| **Suffixe `_test`** | Doctrine ajoute `_test` au nom de base en environnement de test. `.env.test` doit donc nommer la base *de développement*, pas `mssub_test`. |
| **`KernelBrowser` redémarre** | Entre deux requêtes il ouvre une autre connexion : les données d'une transaction de test deviennent invisibles. `disableReboot()`. |
| **Entité relue en mémoire** | Après un formulaire refusé, l'entité porte déjà les valeurs soumises. Un test qui la relit via le dépôt constate le changement qu'il cherchait à empêcher : interroger la ligne en base. |
| **`cache:clear --env=test`** | Ne suffit pas toujours à purger le conteneur compilé ; `rm -rf var/cache/test` si le comportement ne suit pas la configuration. |
| **Dépréciations en prod** | Le recipe Monolog envoie le canal `deprecation` sur `php://stderr` : chaque commande de déploiement se noie sous des centaines de lignes JSON qui masquent les vraies erreurs. Elles vont maintenant dans `var/log/deprecation.log`. |
| **`DATABASE_URL`** | C'est une URL : un `@` ou un `#` non encodé dans le mot de passe fait échouer la connexion avec un `Access denied` qui n'a rien à voir. |
| **Recipes Flex** | Le recipe Doctrine injecte un service PostgreSQL dans `compose.yaml` et une `DATABASE_URL` postgres *après* la vôtre dans `.env`. Relisez ces deux fichiers après tout `composer require`. |
| **Collision Twig** | Une méthode `created()` et un accesseur `getCreated()` : Twig appelle la première et affiche du vide. Les incréments s'appellent `addCreated()`. |
| **Options TLS d'OpenLDAP** | Elles sont globales au processus, pas attachées à la connexion : un test — ou une requête — qui pose une autorité la laisse en place pour les suivants. Les tests l'indiquent donc explicitement plutôt que de compter sur un état vierge. |
| **Groupes LDAP** | Les lire après le *bind* utilisateur renvoie une liste vide — il n'a pas le droit de parcourir l'unité des groupes. Revenir au compte de service. |
| **`AuthSource` par défaut** | Une entité `User` neuve vaut `Local`. Un garde-fou testant la seule source faisait naître les comptes d'annuaire « locaux » et sans mot de passe. |

## Ajouter une migration

```bash
docker compose exec app php bin/console doctrine:migrations:diff
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console --env=test doctrine:schema:update --force --complete
```

Relisez le fichier généré : `doctrine:migrations:diff` reprend parfois des
différences de collation ou d'index qui n'ont rien à voir avec votre changement.

## Écrire un test

- **Unitaire** quand la logique ne dépend que de ses entrées — allocateurs,
  `IpTools`, `RoleMapper`, héritage du site.
- **Intégration** (`KernelTestCase`) dès que Doctrine intervient : conversion de
  types, contraintes SQL, comportement du pilote. Un double n'y voit rien — c'est
  précisément par là qu'un bug est passé.
- **Fonctionnel** (`WebTestCase`) pour un parcours, y compris les refus.

Chaque test d'intégration s'exécute dans une transaction annulée en `tearDown` :
la base ressort dans l'état où elle est entrée, sans `TRUNCATE` ni ordre imposé.

Et une règle apprise à ses dépens : **vérifiez qu'un test de non-régression
échoue bien sans le correctif**. Un test qui passe dans les deux cas ne garde
rien.
