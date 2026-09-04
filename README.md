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

## Conventions

Le stockage des adresses IP, la hiérarchie des sous-réseaux et le découpage des
services d'allocation sont documentés dans les classes concernées
(`src/Doctrine/Type/IpAddressType.php`, `src/Repository/SubnetRepository.php`,
`src/Service/`). La charte graphique fait foi pour toute interface : voir
`Doctrine/charte graphique.md`.
