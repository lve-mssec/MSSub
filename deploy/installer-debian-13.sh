#!/usr/bin/env bash
#
# MSSub — installation sur Debian 13 (Trixie).
#
# Le script pose TOUTES les questions au début, affiche un récapitulatif, puis
# exécute l'installation sans plus rien demander. Une installation qui
# s'interrompt au bout de dix minutes pour réclamer un mot de passe est une
# installation qu'on ne peut ni lancer le soir, ni automatiser.
#
# Chaque réponse peut être fournie par une variable d'environnement, ce qui rend
# le script utilisable sans interaction :
#
#   MSSUB_DOMAINE=mssub.exemple.fr MSSUB_BASE_MOTDEPASSE=... \
#   MSSUB_ADMIN=loic MSSUB_ADMIN_MOTDEPASSE=... MSSUB_SANS_QUESTION=1 \
#       sudo -E ./deploy/installer-debian-13.sh
#
# Usage : sudo ./deploy/installer-debian-13.sh

set -Eeuo pipefail

readonly VERSION_SCRIPT="1.0"

# ---------------------------------------------------------------- présentation

if [[ -t 1 ]]; then
    readonly GRAS=$'\e[1m' NORMAL=$'\e[0m' ROUGE=$'\e[31m' VERT=$'\e[32m' ORANGE=$'\e[33m' GRIS=$'\e[90m'
else
    readonly GRAS='' NORMAL='' ROUGE='' VERT='' ORANGE='' GRIS=''
fi

titre()   { printf '\n%s== %s ==%s\n' "$GRAS" "$1" "$NORMAL"; }
info()    { printf '   %s\n' "$1"; }
detail()  { printf '   %s%s%s\n' "$GRIS" "$1" "$NORMAL"; }
succes()  { printf '   %s✓%s %s\n' "$VERT" "$NORMAL" "$1"; }
alerte()  { printf '   %s!%s %s\n' "$ORANGE" "$NORMAL" "$1"; }
echouer() { printf '\n%s✗ %s%s\n\n' "$ROUGE" "$1" "$NORMAL" >&2; exit 1; }

# Une erreur non traitée doit dire où elle s'est produite, plutôt que de laisser
# une installation à moitié faite sans explication.
trap 'echouer "Échec ligne $LINENO. Corrigez la cause puis relancez : le script est rejouable."' ERR

# --------------------------------------------------------------------- outils

# Encode les caractères réservés d'une URL. Indispensable : DATABASE_URL est une
# URL, et un « @ » ou un « # » brut dans le mot de passe y couperait la lecture,
# produisant un « Access denied » sans rapport avec les droits.
urlencoder() {
    local chaine="$1" i caractere sortie=''
    for (( i = 0; i < ${#chaine}; i++ )); do
        caractere="${chaine:i:1}"
        case "$caractere" in
            [a-zA-Z0-9.~_-]) sortie+="$caractere" ;;
            *) sortie+=$(printf '%%%02X' "'$caractere") ;;
        esac
    done
    printf '%s' "$sortie"
}

# Engendre une chaîne hexadécimale aléatoire.
#
# Hexadécimale à dessein : aucun caractère réservé d'URL, donc rien à encoder
# dans DATABASE_URL. Et sans tuyau interrompu : « tr … | head -c » fait recevoir
# un SIGPIPE à tr, que « set -o pipefail » transforme en échec du script.
engendrer_secret() {
    local octets="$1"

    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex "$octets"
    else
        od -An -tx1 -N "$octets" /dev/urandom | tr -d ' \n'
    fi
}

demander() {
    local invite="$1" defaut="${2:-}" variable="$3" saisie=''

    # Une valeur déjà fournie par l'environnement ne se redemande pas.
    if [[ -n "${!variable:-}" ]]; then
        detail "$invite : ${!variable} (fourni)"
        return 0
    fi

    if [[ -n "${MSSUB_SANS_QUESTION:-}" ]]; then
        [[ -n "$defaut" ]] || echouer "Mode sans question : $variable doit être défini."
        printf -v "$variable" '%s' "$defaut"
        detail "$invite : $defaut (défaut)"
        return 0
    fi

    while :; do
        if [[ -n "$defaut" ]]; then
            read -r -p "   $invite [$defaut] : " saisie || true
            saisie="${saisie:-$defaut}"
        else
            read -r -p "   $invite : " saisie || true
        fi
        [[ -n "$saisie" ]] && break
        alerte "Une valeur est attendue."
    done

    printf -v "$variable" '%s' "$saisie"
}

demander_secret() {
    local invite="$1" variable="$2" minimum="${3:-8}" premier='' second=''

    if [[ -n "${!variable:-}" ]]; then
        detail "$invite : (fourni)"
        return 0
    fi

    [[ -n "${MSSUB_SANS_QUESTION:-}" ]] && echouer "Mode sans question : $variable doit être défini."

    while :; do
        read -r -s -p "   $invite : " premier; printf '\n'
        if (( ${#premier} < minimum )); then
            alerte "$minimum caractères au minimum."
            continue
        fi
        read -r -s -p "   Confirmer : " second; printf '\n'
        [[ "$premier" == "$second" ]] && break
        alerte "Les deux saisies diffèrent."
    done

    printf -v "$variable" '%s' "$premier"
}

demander_oui_non() {
    local invite="$1" defaut="$2" variable="$3" reponse=''

    if [[ -n "${!variable:-}" ]]; then
        detail "$invite : ${!variable} (fourni)"
        return 0
    fi

    if [[ -n "${MSSUB_SANS_QUESTION:-}" ]]; then
        printf -v "$variable" '%s' "$defaut"
        return 0
    fi

    while :; do
        read -r -p "   $invite (o/n) [$defaut] : " reponse || true
        reponse="${reponse:-$defaut}"
        case "${reponse,,}" in
            o|oui|y|yes) printf -v "$variable" '%s' 'oui'; return 0 ;;
            n|non|no)    printf -v "$variable" '%s' 'non'; return 0 ;;
            *) alerte "Répondre o ou n." ;;
        esac
    done
}

# ------------------------------------------------------- contrôles préalables

titre "Contrôles préalables"

[[ $EUID -eq 0 ]] || echouer "À lancer en root : sudo $0"

if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    info "Système : ${PRETTY_NAME:-inconnu}"
    if [[ "${ID:-}" != "debian" ]]; then
        alerte "Ce script vise Debian. Sur ${ID:-un autre système}, les noms de paquets diffèrent."
    elif [[ "${VERSION_ID:-}" != "13" ]]; then
        alerte "Prévu pour Debian 13 ; détecté ${VERSION_ID:-?}. Les versions de PHP peuvent différer."
    fi
else
    alerte "Système non identifié."
fi

command -v apt-get >/dev/null || echouer "apt-get est introuvable."
succes "Prérequis réunis"

# ----------------------------------------------------------------- questions
#
# Tout se demande ici, et nulle part ailleurs : au-delà de la confirmation,
# le script ne s'arrête plus.

titre "Configuration — aucune modification n'est faite avant votre confirmation"

demander "Nom de domaine du portail" "mssub.$(hostname -d 2>/dev/null || echo 'local')" MSSUB_DOMAINE
demander "Répertoire d'installation" "/var/www/mssub" MSSUB_RACINE

printf '\n'
info "Source du code :"
detail "chemin local (dépôt déjà cloné, ou fichier .bundle), ou URL git"
demander "Source" "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" MSSUB_SOURCE
demander "Branche ou étiquette" "main" MSSUB_REFERENCE

printf '\n'
info "Base de données MariaDB :"
demander "Nom de la base" "mssub" MSSUB_BASE
demander "Compte de la base" "mssub" MSSUB_BASE_UTILISATEUR
demander_oui_non "Engendrer le mot de passe de la base" "o" MSSUB_BASE_MOTDEPASSE_AUTO

if [[ "$MSSUB_BASE_MOTDEPASSE_AUTO" == "oui" && -z "${MSSUB_BASE_MOTDEPASSE:-}" ]]; then
    MSSUB_BASE_MOTDEPASSE="$(engendrer_secret 16)"
    detail "Mot de passe de la base engendré (32 caractères)"
else
    demander_secret "Mot de passe de la base" MSSUB_BASE_MOTDEPASSE 12
fi

printf '\n'
info "Compte administrateur du portail :"
demander "Identifiant" "admin" MSSUB_ADMIN
demander_secret "Mot de passe" MSSUB_ADMIN_MOTDEPASSE 12

printf '\n'
info "Secret applicatif :"
detail "il chiffre les secrets d'annuaire et de SSO enregistrés depuis l'interface"
if [[ -z "${MSSUB_APP_SECRET:-}" ]]; then
    MSSUB_APP_SECRET="$(engendrer_secret 32)"
    detail "Secret applicatif engendré (64 caractères hexadécimaux)"
fi

printf '\n'
demander_oui_non "Obtenir un certificat TLS avec Let's Encrypt" "n" MSSUB_TLS
if [[ "$MSSUB_TLS" == "oui" ]]; then
    demander "Adresse de courriel pour Let's Encrypt" "" MSSUB_TLS_COURRIEL
fi

# ------------------------------------------------------------- récapitulatif

titre "Récapitulatif"

cat <<RESUME
   Domaine              : $MSSUB_DOMAINE
   Répertoire           : $MSSUB_RACINE
   Source               : $MSSUB_SOURCE ($MSSUB_REFERENCE)
   Base                 : $MSSUB_BASE (compte $MSSUB_BASE_UTILISATEUR)
   Mot de passe base    : ******** (${#MSSUB_BASE_MOTDEPASSE} caractères)
   Administrateur       : $MSSUB_ADMIN
   Mot de passe admin   : ******** (${#MSSUB_ADMIN_MOTDEPASSE} caractères)
   Secret applicatif    : ******** (${#MSSUB_APP_SECRET} caractères)
   Certificat TLS       : $MSSUB_TLS
RESUME

if [[ -z "${MSSUB_SANS_QUESTION:-}" ]]; then
    printf '\n'
    read -r -p "   Lancer l'installation ? (o/n) [o] : " confirmation || true
    case "${confirmation:-o}" in
        o|O|oui|Oui|y|Y) : ;;
        *) echouer "Interrompu à votre demande. Rien n'a été modifié." ;;
    esac
fi

readonly MSSUB_DOMAINE MSSUB_RACINE MSSUB_SOURCE MSSUB_REFERENCE
readonly MSSUB_BASE MSSUB_BASE_UTILISATEUR MSSUB_ADMIN MSSUB_TLS

# ================================================================ installation
# À partir d'ici, plus aucune question.

titre "1/9 — Paquets"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends \
    apache2 mariadb-server \
    php8.4 libapache2-mod-php8.4 php8.4-cli \
    php8.4-mysql php8.4-intl php8.4-zip php8.4-ldap php8.4-gd \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-opcache \
    composer git unzip openssl curl >/dev/null
succes "Paquets installés"

for extension in intl pdo_mysql ldap zip gd mbstring sodium; do
    php -m | grep -qx "$extension" || echouer "Extension PHP manquante : $extension"
done
succes "Extensions PHP présentes ($(php -r 'echo PHP_VERSION;'))"

titre "2/9 — Base de données"

systemctl enable --now mariadb >/dev/null 2>&1 || service mariadb start >/dev/null 2>&1 || true

for _ in $(seq 1 30); do
    mariadb -e "SELECT 1" >/dev/null 2>&1 && break
    sleep 1
done
mariadb -e "SELECT 1" >/dev/null 2>&1 || echouer "MariaDB ne répond pas."

# Idempotent : une réexécution ne doit pas échouer sur « déjà existant », et doit
# aligner le mot de passe sur celui que le script vient d'enregistrer.
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${MSSUB_BASE}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${MSSUB_BASE_UTILISATEUR}'@'localhost'
    IDENTIFIED BY '${MSSUB_BASE_MOTDEPASSE}';
ALTER USER '${MSSUB_BASE_UTILISATEUR}'@'localhost'
    IDENTIFIED BY '${MSSUB_BASE_MOTDEPASSE}';
GRANT ALL PRIVILEGES ON \`${MSSUB_BASE}\`.* TO '${MSSUB_BASE_UTILISATEUR}'@'localhost';
FLUSH PRIVILEGES;
SQL
succes "Base « $MSSUB_BASE » et compte « $MSSUB_BASE_UTILISATEUR » prêts"

titre "3/9 — Code"

if [[ -d "$MSSUB_RACINE/.git" ]]; then
    git -C "$MSSUB_RACINE" fetch --all --tags --quiet
    git -C "$MSSUB_RACINE" checkout --quiet "$MSSUB_REFERENCE"
    git -C "$MSSUB_RACINE" pull --quiet --ff-only 2>/dev/null || true
    succes "Dépôt existant mis à jour sur $MSSUB_REFERENCE"
else
    mkdir -p "$(dirname "$MSSUB_RACINE")"
    git clone --quiet --branch "$MSSUB_REFERENCE" "$MSSUB_SOURCE" "$MSSUB_RACINE"
    succes "Code déployé dans $MSSUB_RACINE"
fi

cd "$MSSUB_RACINE"

titre "4/9 — Configuration"

motdepasse_encode="$(urlencoder "$MSSUB_BASE_MOTDEPASSE")"

umask 027
cat > "$MSSUB_RACINE/.env.local" <<ENV
# Engendré par installer-debian-13.sh v$VERSION_SCRIPT le $(date '+%F %T').
#
# APP_SECRET chiffre les secrets enregistrés depuis l'interface (compte de
# service LDAP, secret client OIDC). Le remplacer les rend illisibles : ils
# devront être ressaisis. À sauvegarder avec la base.
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$MSSUB_APP_SECRET
DATABASE_URL="mysql://${MSSUB_BASE_UTILISATEUR}:${motdepasse_encode}@127.0.0.1:3306/${MSSUB_BASE}?serverVersion=11.8.0-MariaDB&charset=utf8mb4"
ENV
unset motdepasse_encode

grep -qE '^APP_SECRET=[0-9a-f]{64}$' "$MSSUB_RACINE/.env.local" \
    || echouer "Le secret applicatif n'a pas la forme attendue."
succes "Fichier .env.local écrit et contrôlé"

titre "5/9 — Dépendances"

# APP_ENV=prod n'est pas décoratif : Composer enchaîne un cache:clear qui
# démarre l'application, et sans cette variable elle chercherait les bundles de
# développement que --no-dev vient de retirer.
COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod APP_DEBUG=0 \
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
succes "Dépendances installées"

titre "6/9 — Base et ressources"

php bin/console doctrine:migrations:migrate --no-interaction --env=prod --quiet
succes "Migrations appliquées"

php bin/console asset-map:compile --env=prod --quiet
php bin/console cache:clear --env=prod --quiet
php bin/console cache:warmup --env=prod --quiet
succes "Ressources compilées et cache préparé"

titre "7/9 — Compte administrateur"

# Par l'entrée standard, jamais par --password : un argument de ligne de
# commande est visible dans la liste des processus.
printf '%s\n' "$MSSUB_ADMIN_MOTDEPASSE" \
    | php bin/console app:user:create "$MSSUB_ADMIN" --admin --env=prod >/dev/null
succes "Compte administrateur « $MSSUB_ADMIN » créé"

titre "8/9 — Droits"

# Le code appartient à root : un serveur qui peut réécrire son propre code
# transforme n'importe quelle faille de téléversement en exécution de code.
# Seuls var/ et public/assets/ ont besoin d'être inscriptibles.
chown -R root:www-data "$MSSUB_RACINE"
chmod -R g-w "$MSSUB_RACINE"
chown -R www-data:www-data "$MSSUB_RACINE/var" "$MSSUB_RACINE/public/assets"
chown root:www-data "$MSSUB_RACINE/.env.local"
chmod 640 "$MSSUB_RACINE/.env.local"
succes "Droits appliqués"

titre "9/9 — Apache"

cat > /etc/php/8.4/apache2/conf.d/99-mssub.ini <<'INI'
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
; Le code ne change qu'au déploiement : reload d'Apache après chaque mise à jour.
opcache.validate_timestamps = 0

session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.use_strict_mode = 1
INI

cat > /etc/apache2/sites-available/mssub.conf <<VHOST
<VirtualHost *:80>
    ServerName $MSSUB_DOMAINE
    DocumentRoot $MSSUB_RACINE/public

    <Directory $MSSUB_RACINE/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    # Rien en dehors de public/ ne doit être servi : ni config, ni var,
    # ni vendor, ni .env.local.
    <Directory $MSSUB_RACINE>
        AllowOverride None
        Require all denied
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "same-origin"

    ErrorLog  \${APACHE_LOG_DIR}/mssub-error.log
    CustomLog \${APACHE_LOG_DIR}/mssub-access.log combined
</VirtualHost>
VHOST

a2enmod rewrite headers >/dev/null
a2ensite mssub >/dev/null
a2dissite 000-default >/dev/null 2>&1 || true

apachectl configtest 2>&1 | grep -q 'Syntax OK' || echouer "Configuration Apache invalide."
systemctl reload apache2 >/dev/null 2>&1 || service apache2 restart >/dev/null 2>&1 || true
succes "Apache configuré pour $MSSUB_DOMAINE"

if [[ "$MSSUB_TLS" == "oui" ]]; then
    titre "Certificat TLS"
    apt-get install -y -qq --no-install-recommends certbot python3-certbot-apache >/dev/null
    if certbot --apache --non-interactive --agree-tos \
        --email "$MSSUB_TLS_COURRIEL" -d "$MSSUB_DOMAINE" >/dev/null 2>&1; then
        # Le portail transporte des mots de passe : le cookie de session ne doit
        # plus circuler en clair une fois HTTPS en place.
        sed -i 's/^session.cookie_secure.*//' /etc/php/8.4/apache2/conf.d/99-mssub.ini
        echo 'session.cookie_secure = 1' >> /etc/php/8.4/apache2/conf.d/99-mssub.ini
        systemctl reload apache2 >/dev/null 2>&1 || true
        succes "Certificat obtenu, cookies de session marqués « secure »"
    else
        alerte "Certbot a échoué — le portail reste en HTTP. Relancez : certbot --apache -d $MSSUB_DOMAINE"
    fi
fi

# ------------------------------------------------------------- vérification

titre "Vérification"

sortie_about="$(php bin/console about --env=prod 2>/dev/null || true)"
grep -q 'prod' <<<"$sortie_about" || alerte "L'application ne semble pas en environnement de production."

php bin/console dbal:run-sql "SELECT 1" --env=prod >/dev/null 2>&1 \
    && succes "Connexion à la base établie" \
    || echouer "L'application ne parvient pas à joindre la base."

code_racine="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $MSSUB_DOMAINE" http://127.0.0.1/ || echo '000')"
[[ "$code_racine" == "302" ]] \
    && succes "Le portail répond et redirige vers la page de connexion" \
    || alerte "Réponse inattendue sur / : $code_racine (attendu 302)"

code_connexion="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $MSSUB_DOMAINE" http://127.0.0.1/connexion || echo '000')"
[[ "$code_connexion" == "200" ]] \
    && succes "La page de connexion s'affiche" \
    || alerte "Réponse inattendue sur /connexion : $code_connexion (attendu 200)"

# ------------------------------------------------------------------- résumé

protocole="http"
[[ "$MSSUB_TLS" == "oui" ]] && protocole="https"

titre "Installation terminée"

cat <<FIN
   Portail        : $protocole://$MSSUB_DOMAINE/
   Identifiant    : $MSSUB_ADMIN
   Mot de passe   : celui que vous avez saisi

   À faire maintenant :
     · sauvegarder $MSSUB_RACINE/.env.local — il contient le secret qui
       déchiffre les mots de passe d'annuaire et de SSO enregistrés ensuite ;
     · configurer l'annuaire dans Administration → Annuaire, le cas échéant ;
     · charger votre plan existant par Import.

   Mise à jour ultérieure : voir docs/installation-debian-13.md.
FIN

printf '\n'
