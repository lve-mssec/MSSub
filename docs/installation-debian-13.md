# Installation sur Debian 13 (Trixie)

Installation d'une instance de production sur une Debian 13 fraîche, sans
conteneur. Compter une trentaine de minutes.

Debian 13 fournit **PHP 8.4**, **MariaDB 11.8** et **Apache 2.4**. La suite de
tests de MSSub a été exécutée sur PHP 8.3 et 8.4 : les deux passent sans
dépréciation, aucun dépôt tiers n'est donc nécessaire.

> Vérifiez les versions réellement proposées par votre miroir avant de
> commencer : `apt-cache policy php mariadb-server apache2`.

## Installation assistée

```bash
git clone <dépôt> /tmp/mssub-source
sudo /tmp/mssub-source/deploy/installer-debian-13.sh
```

Le script **pose toutes les questions au début**, affiche un récapitulatif, puis
déroule l'installation sans plus rien demander : une installation qui
s'interrompt au bout de dix minutes pour réclamer un mot de passe est une
installation qu'on ne peut ni lancer le soir, ni automatiser.

Il vous demande le domaine, le répertoire, la source du code, la base, le compte
administrateur et le certificat TLS. Le mot de passe de la base et le secret
applicatif sont engendrés par défaut — en hexadécimal, donc sans caractère
réservé d'URL à encoder.

Il est **rejouable** : le relancer sur une installation existante met à jour le
code, réaligne le mot de passe de la base et reprend là où il faut.

### Sans interaction

Chaque réponse peut venir d'une variable d'environnement :

```bash
sudo MSSUB_DOMAINE=mssub.exemple.fr \
     MSSUB_ADMIN=loic MSSUB_ADMIN_MOTDEPASSE='...' \
     MSSUB_SOURCE=https://github.com/lve-mssec/MSSub MSSUB_REFERENCE=v1.1.0 \
     MSSUB_SANS_QUESTION=1 \
     ./deploy/installer-debian-13.sh
```

Variables reconnues : `MSSUB_DOMAINE`, `MSSUB_RACINE`, `MSSUB_SOURCE`,
`MSSUB_REFERENCE`, `MSSUB_BASE`, `MSSUB_BASE_UTILISATEUR`,
`MSSUB_BASE_MOTDEPASSE`, `MSSUB_BASE_MOTDEPASSE_AUTO`, `MSSUB_ADMIN`,
`MSSUB_ADMIN_MOTDEPASSE`, `MSSUB_APP_SECRET`, `MSSUB_TLS`, `MSSUB_TLS_COURRIEL`.

### Ce qu'il fait, dans l'ordre

1. Paquets et contrôle des extensions PHP
2. Base MariaDB et son compte
3. Déploiement du code
4. `.env.local`, avec contrôle du secret applicatif
5. Dépendances Composer, en environnement de production
6. Migrations, compilation des ressources, cache
7. Compte administrateur — mot de passe passé par l'entrée standard, jamais en
   argument de ligne de commande où il serait visible dans la liste des processus
8. Droits : code en lecture seule pour le serveur, seuls `var/` et
   `public/assets/` inscriptibles
9. Apache et réglages PHP, puis certificat TLS le cas échéant

Il termine par une vérification : connexion à la base, redirection de `/` vers
la page de connexion, affichage de celle-ci.

### En cas d'échec

Chaque commande consigne sa sortie complète dans `/var/log/mssub-installation.log`.
Si une étape échoue, le script affiche les trente dernières lignes du journal
puis s'arrête — il n'y a rien à deviner.

Deux situations sont détectées explicitement plutôt que de dégénérer plus loin :

- **le répertoire d'installation existe, n'est pas un dépôt git et n'est pas
  vide** — un clone n'y est pas possible ; déplacez-le ou changez de répertoire ;
- **le code n'a pas été déployé** (`composer.json` absent après l'étape 3) — la
  source ou la référence indiquée ne contient pas MSSub. Sans ce contrôle,
  l'échec n'apparaîtrait que deux étapes plus loin, sous la forme d'un
  « Composer could not find a composer.json file » sans rapport apparent.

## Installation manuelle

Ce qui suit décrit les mêmes gestes, un par un — utile pour adapter à une
infrastructure existante, ou pour comprendre ce que le script fait.

## 1. Paquets

```bash
sudo apt update
sudo apt install --no-install-recommends \
    apache2 mariadb-server \
    php8.4 libapache2-mod-php8.4 php8.4-cli \
    php8.4-mysql php8.4-intl php8.4-zip php8.4-ldap php8.4-gd \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-opcache \
    composer git unzip
```

Les extensions ne sont pas toutes décoratives :

| Extension | Sans elle |
| --- | --- |
| `intl` | Symfony refuse de démarrer |
| `mysql` (PDO) | aucune connexion à la base |
| `ldap` | l'authentification annuaire est inutilisable |
| `zip`, `gd`, `xml` | l'import XLSX échoue |
| `mbstring` | les accents des noms de sites se corrompent |
| `sodium` | les secrets ne peuvent être ni chiffrés ni relus (fournie d'office par Debian) |

Vérification :

```bash
php -v
php -m | grep -E '^(intl|pdo_mysql|ldap|zip|gd|mbstring|sodium)$'
```

## 2. Base de données

```bash
sudo mariadb-secure-installation
```

Puis créer la base et son compte — remplacez le mot de passe :

```bash
sudo mariadb <<'SQL'
CREATE DATABASE mssub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mssub'@'localhost' IDENTIFIED BY 'un-mot-de-passe-solide';
GRANT ALL PRIVILEGES ON mssub.* TO 'mssub'@'localhost';
FLUSH PRIVILEGES;
SQL
```

`utf8mb4` n'est pas facultatif : les noms de sites et de VLAN sont accentués, et
les adresses IP sont stockées en binaire dans des colonnes que MariaDB ne doit
pas tenter de réinterpréter.

## 3. Déploiement du code

```bash
sudo mkdir -p /var/www/mssub
sudo chown "$USER" /var/www/mssub
git clone <dépôt> /var/www/mssub
cd /var/www/mssub
```

**N'installez pas encore les dépendances** : la configuration doit exister
d'abord. L'étape suivante explique pourquoi.

## 4. Configuration

Créer `/var/www/mssub/.env.local` — ce fichier n'est pas versionné.

Le secret applicatif est engendré dans le même geste : il n'y a rien à
remplacer, donc rien à oublier.

```bash
umask 027
{
    echo "APP_ENV=prod"
    echo "APP_DEBUG=0"
    echo "APP_SECRET=$(openssl rand -hex 32)"
    echo 'DATABASE_URL="mysql://mssub:MOT_DE_PASSE@127.0.0.1:3306/mssub?serverVersion=11.8.0-MariaDB&charset=utf8mb4"'
} > /var/www/mssub/.env.local
```

Puis remplacer `MOT_DE_PASSE` **avec un éditeur** plutôt qu'en ligne de
commande : un mot de passe contient volontiers des caractères que le shell
interpréterait.

> **`DATABASE_URL` est une URL, pas une chaîne de connexion libre.** Les
> caractères réservés du mot de passe doivent y être encodés, sans quoi la
> lecture s'arrête au premier d'entre eux et vous obtiendrez un
> `Access denied` incompréhensible :
>
> | Caractère | À écrire |
> | --- | --- |
> | `@` | `%40` |
> | `:` | `%3A` |
> | `/` | `%2F` |
> | `#` | `%23` |
> | `?` | `%3F` |
> | `%` | `%25` |
> | `&` | `%26` |
>
> Un mot de passe sans ces caractères évite la question. Pour encoder le vôtre :
> `php -r 'echo rawurlencode($argv[1]), PHP_EOL;' 'votre-mot-de-passe'`

Contrôler tout de suite que le secret est bien là — un secret prévisible rend
les jetons anti-CSRF forgeables :

```bash
grep -qE '^APP_SECRET=[0-9a-f]{64}$' /var/www/mssub/.env.local \
    && echo "secret applicatif : correct" \
    || echo "secret applicatif : À CORRIGER"
```

S'il est à corriger :

```bash
sed -i "s|^APP_SECRET=.*|APP_SECRET=$(openssl rand -hex 32)|" /var/www/mssub/.env.local
```

> **`APP_SECRET` ne se change pas à la légère.** Il sert à dériver la clé qui
> chiffre les secrets enregistrés depuis l'interface — mot de passe du compte de
> service LDAP, secret client OIDC. Le remplacer les rend illisibles : ils
> devront être ressaisis. Sauvegardez-le avec la base.

Le reste — annuaire, SSO, correspondance des rôles — se configure ensuite depuis
l'interface, sans toucher à ce fichier. Les variables d'environnement décrites
dans `.env` restent disponibles comme valeurs de repli si vous préférez figer la
configuration au déploiement.

### Dépendances

```bash
cd /var/www/mssub
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
```

`--no-dev` écarte PHPUnit et les outils de développement ; `--optimize-autoloader`
évite de parcourir le disque à chaque classe chargée.

**`APP_ENV=prod` n'est pas décoratif ici.** Composer enchaîne un `cache:clear`
après l'installation, qui démarre l'application. Sans cette variable — et tant
que `.env.local` n'existe pas — elle démarrerait en environnement de
développement et chercherait `DoctrineFixturesBundle`, que `--no-dev` vient
précisément de retirer :

```
Attempted to load class "DoctrineFixturesBundle"
    from namespace "Doctrine\Bundle\FixturesBundle".
Script cache:clear returned with error code 255
```

L'installation des paquets, elle, a bien eu lieu : seul le script de fin a
échoué. Si vous rencontrez cette erreur, créez `.env.local` puis relancez
`php bin/console cache:clear` — inutile de tout recommencer.

## 5. Base et ressources

```bash
cd /var/www/mssub
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console asset-map:compile --env=prod
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

`asset-map:compile` écrit les feuilles de style et les scripts dans
`public/assets/` avec une empreinte dans leur nom. **À rejouer à chaque mise à
jour**, faute de quoi l'interface s'affichera sans style.

Ces commandes s'exécutent **avant** l'étape suivante, qui retire à votre compte
le droit d'écrire dans l'arborescence. Si vous devez les rejouer après, voir la
procédure de mise à jour.

## 6. Droits

```bash
sudo chown -R root:www-data /var/www/mssub
sudo chmod -R g-w /var/www/mssub
sudo chown -R www-data:www-data /var/www/mssub/var /var/www/mssub/public/assets
sudo chmod 640 /var/www/mssub/.env.local
sudo chown root:www-data /var/www/mssub/.env.local
```

Seul `var/` a besoin d'être inscriptible par le serveur : cache, journaux et
sessions. Le reste du code doit rester en lecture seule pour www-data — un
serveur qui peut réécrire son propre code transforme n'importe quelle faille de
téléversement en exécution de code.

## 7. Apache

```bash
sudo a2enmod rewrite headers
sudo a2dissite 000-default
```

`/etc/apache2/sites-available/mssub.conf` :

```apache
<VirtualHost *:80>
    ServerName mssub.exemple.fr
    DocumentRoot /var/www/mssub/public

    <Directory /var/www/mssub/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    # Rien en dehors de public/ ne doit être servi : ni config, ni var,
    # ni vendor, ni .env.local.
    <Directory /var/www/mssub>
        AllowOverride None
        Require all denied
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "same-origin"

    ErrorLog  ${APACHE_LOG_DIR}/mssub-error.log
    CustomLog ${APACHE_LOG_DIR}/mssub-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite mssub
sudo apachectl configtest && sudo systemctl reload apache2
```

### Réglages PHP

`/etc/php/8.4/apache2/conf.d/99-mssub.ini` :

```ini
date.timezone = Europe/Paris
memory_limit = 512M
expose_php = Off

; Les fichiers d'import d'un plan d'adressage existant peuvent être gros.
upload_max_filesize = 32M
post_max_size = 32M
max_execution_time = 120

opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
; En production, le code ne change qu'au déploiement : ne pas le revalider.
opcache.validate_timestamps = 0

session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.cookie_secure = 1
session.use_strict_mode = 1
```

`opcache.validate_timestamps = 0` impose `systemctl reload apache2` après chaque
déploiement, sans quoi l'ancien code continuera de s'exécuter.

## 8. TLS

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d mssub.exemple.fr
```

Le portail transporte des identifiants et, si vous activez l'annuaire, des mots
de passe : `session.cookie_secure = 1` ci-dessus suppose HTTPS, et la connexion
cassera en clair. C'est voulu.

## 9. Premier compte

```bash
cd /var/www/mssub
sudo -u www-data php bin/console app:user:create <identifiant> --admin
```

Le mot de passe est demandé sans être affiché ; douze caractères au minimum.
**Ce compte local est votre porte de secours** : gardez-le même après avoir
branché l'annuaire, il est le seul à fonctionner quand celui-ci est injoignable.

## 10. Vérification

```bash
# Le secret applicatif est bien aléatoire, et non le gabarit du guide.
grep -qE '^APP_SECRET=[0-9a-f]{64}$' /var/www/mssub/.env.local \
    && echo "secret applicatif : correct" \
    || echo "secret applicatif : À CORRIGER"

curl -sI https://mssub.exemple.fr/ | head -1          # 302 vers /connexion
curl -s  https://mssub.exemple.fr/connexion | grep -c csrf_token   # 1
sudo -u www-data php bin/console about --env=prod | head -12
```

Puis, dans un navigateur : connexion, **Administration → Annuaire → Tester la
connexion** si vous utilisez LDAP, et **Import** pour charger votre plan
existant.

## Mise à jour

```bash
cd /var/www/mssub

# Toujours avant une migration : elle n'est pas réversible sans sauvegarde.
mysqldump --single-transaction -u mssub -p mssub > ~/mssub-avant-maj.sql

# Les droits de l'étape 6 rendent l'arborescence non inscriptible : on la
# rouvre le temps du déploiement, puis on la referme.
sudo chown -R "$USER":www-data /var/www/mssub

git pull
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console asset-map:compile --env=prod
php bin/console cache:clear --env=prod

# Refermer, comme à l'étape 6.
sudo chown -R root:www-data /var/www/mssub
sudo chmod -R g-w /var/www/mssub
sudo chown -R www-data:www-data /var/www/mssub/var /var/www/mssub/public/assets
sudo chown root:www-data /var/www/mssub/.env.local

sudo systemctl reload apache2
```

`APP_ENV=prod` devant `composer install` pour la même raison qu'à
l'installation, et `--env=prod` sur chaque commande : `.env.local` le dit déjà,
mais l'expliciter évite qu'une console lancée depuis un autre répertoire ou par
un autre compte ne retombe silencieusement sur l'environnement de développement.

## Sauvegarde

Deux choses, et elles vont ensemble :

```bash
mysqldump --single-transaction -u mssub -p mssub | gzip > mssub-$(date +%F).sql.gz
cp /var/www/mssub/.env.local mssub-env-$(date +%F)
```

La base sans `APP_SECRET` est inexploitable pour les secrets qu'elle contient :
une restauration avec un autre secret applicatif rendra les mots de passe LDAP
et OIDC illisibles, et il faudra les ressaisir. Le reste du référentiel, lui,
sera intact.

## Dépannage

### `Access denied for user 'mssub'@'localhost'`

Trois causes, par ordre de fréquence.

**Le mot de passe contient un caractère réservé non encodé** dans
`DATABASE_URL`. C'est de loin la plus courante — voir l'encadré de l'étape 4.
Testez le mot de passe hors de l'URL :

```bash
mariadb -u mssub -p -e "SELECT 'connexion ok';"
```

S'il passe ici mais pas dans l'application, le problème est l'encodage.

**Le compte n'existe pas, ou pas pour cet hôte.** MariaDB distingue
`'mssub'@'localhost'` de `'mssub'@'127.0.0.1'` :

```bash
sudo mariadb -e "SELECT user, host FROM mysql.user WHERE user='mssub';"
```

L'étape 2 crée `'mssub'@'localhost'`, ce qui couvre une `DATABASE_URL` pointant
sur `127.0.0.1` **ou** sur `localhost` — MariaDB résout les deux vers le même
compte tant que la connexion reste locale.

**Le mot de passe ne correspond pas.** Le réinitialiser :

```bash
sudo mariadb -e "ALTER USER 'mssub'@'localhost' IDENTIFIED BY 'nouveau'; FLUSH PRIVILEGES;"
```

Puis reporter la valeur — encodée — dans `.env.local`.

### `Failed to create "public/assets": mkdir(): Permission denied`

L'étape 6 a déjà été jouée : l'arborescence appartient à `root` et votre compte
ne peut plus y écrire. Rouvrez, compilez, refermez — c'est la séquence de la
procédure de mise à jour :

```bash
sudo chown -R "$USER":www-data /var/www/mssub
php bin/console asset-map:compile --env=prod
sudo chown -R root:www-data /var/www/mssub
sudo chmod -R g-w /var/www/mssub
sudo chown -R www-data:www-data /var/www/mssub/var /var/www/mssub/public/assets
sudo chown root:www-data /var/www/mssub/.env.local
```

### La console noie sa sortie sous des dépréciations JSON

Corrigé en v1.0.3 : les dépréciations vont désormais dans
`var/log/deprecation.log` au lieu de la sortie d'erreur, et la configuration
Doctrine n'en produit plus. Si vous déployez une version antérieure, elles sont
sans conséquence — mais elles masquent les vraies erreurs, ce qui est la raison
du correctif.

## Ce qui n'est pas couvert ici

- **Haute disponibilité** : l'application est sans état hormis la session PHP,
  qui est en fichier par défaut. Derrière plusieurs frontaux, basculez les
  sessions vers un stockage partagé.
- **SAML** : non implémenté. Voir `docs/administration.md`.
- **Sauvegarde applicative de `var/`** : inutile, tout y est reconstructible.
