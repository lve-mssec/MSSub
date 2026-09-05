# Installation sur Debian 13 (Trixie)

Installation d'une instance de production sur une Debian 13 fraîche, sans
conteneur. Compter une trentaine de minutes.

Debian 13 fournit **PHP 8.4**, **MariaDB 11.8** et **Apache 2.4**. La suite de
tests de MSSub a été exécutée sur PHP 8.3 et 8.4 : les deux passent sans
dépréciation, aucun dépôt tiers n'est donc nécessaire.

> Vérifiez les versions réellement proposées par votre miroir avant de
> commencer : `apt-cache policy php mariadb-server apache2`.

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

## 6. Droits

```bash
sudo chown -R root:www-data /var/www/mssub
sudo chmod -R g-w /var/www/mssub
sudo chown -R www-data:www-data /var/www/mssub/var
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

git pull
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console asset-map:compile --env=prod
php bin/console cache:clear --env=prod
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

## Ce qui n'est pas couvert ici

- **Haute disponibilité** : l'application est sans état hormis la session PHP,
  qui est en fichier par défaut. Derrière plusieurs frontaux, basculez les
  sessions vers un stockage partagé.
- **SAML** : non implémenté. Voir `docs/administration.md`.
- **Sauvegarde applicative de `var/`** : inutile, tout y est reconstructible.
