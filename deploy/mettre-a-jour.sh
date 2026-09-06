#!/usr/bin/env bash
#
# MSSub — mise à jour d'une instance installée.
#
# Reprend le code, applique les migrations, recompile les ressources et
# recharge Apache. La procédure manuelle équivalente tient en douze commandes
# dont une danse de droits au milieu : c'est précisément ce qui se rate un soir
# de déploiement.
#
# Usage : sudo ./deploy/mettre-a-jour.sh [référence]
#
#   sudo ./deploy/mettre-a-jour.sh          # dernière version de la branche courante
#   sudo ./deploy/mettre-a-jour.sh v1.3.0   # une version précise

set -Eeuo pipefail

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

# Chemin absolu résolu tout de suite : le script se déplace dans l'arborescence
# et pourrait être remplacé sous ses propres pieds par la mise à jour.
SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"

RACINE="${MSSUB_RACINE:-/var/www/mssub}"
JOURNAL="${MSSUB_JOURNAL:-/var/log/mssub-mise-a-jour.log}"
REFERENCE="${1:-}"

trap 'echouer "Échec ligne $LINENO. Détail dans $JOURNAL."' ERR

echouer_avec_journal() {
    printf '\n%s✗ %s%s\n' "$ROUGE" "$1" "$NORMAL" >&2
    printf '\n   %sDernières lignes de %s :%s\n\n' "$GRIS" "$JOURNAL" "$NORMAL" >&2
    tail -n 30 "$JOURNAL" 2>/dev/null | sed 's/^/     /' >&2
    printf '\n   %sLa sauvegarde de la base est dans %s%s\n\n' "$GRIS" "${SAUVEGARDE:-(non prise)}" "$NORMAL" >&2
    exit 1
}

executer() {
    local description="$1"; shift
    [[ -t 1 ]] && printf '   %s…\r' "$description"
    if "$@" >>"$JOURNAL" 2>&1; then
        [[ -t 1 ]] && printf '\033[2K\r'
        succes "$description"
    else
        [[ -t 1 ]] && printf '\033[2K\r'
        echouer_avec_journal "$description"
    fi
}

# ------------------------------------------------------------- vérifications

titre "Contrôles"

[[ $EUID -eq 0 ]] || echouer "À lancer en root : sudo $0"
[[ -f "$RACINE/composer.json" ]] || echouer "$RACINE ne contient pas MSSub."
[[ -d "$RACINE/.git" ]] || echouer "$RACINE n'est pas un dépôt git : la mise à jour automatique ne s'applique pas."
[[ -f "$RACINE/.env.local" ]] || echouer "$RACINE/.env.local est absent : l'instance n'est pas configurée."

: > "$JOURNAL"
chmod 600 "$JOURNAL"

cd "$RACINE"
version_avant="$(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD)"
empreinte_avant="$(sha256sum "$SCRIPT" | cut -d' ' -f1)"
info "Version installée : $version_avant"
detail "Journal détaillé : $JOURNAL"

# ------------------------------------------------------------- sauvegarde

titre "Sauvegarde de la base"

# Les identifiants sont lus dans .env.local plutôt que redemandés : ils y sont
# déjà, et les retaper à chaque mise à jour finirait par produire une faute.
lecture="$(php -r '
    $ligne = "";
    foreach (file($argv[1]) as $l) {
        $l = trim($l);
        if (str_starts_with($l, "DATABASE_URL=")) { $ligne = trim(substr($l, 13), "\"\x27"); }
    }
    $p = parse_url($ligne);
    printf("%s\n%s\n%s\n%s\n%s\n",
        $p["user"] ?? "", rawurldecode($p["pass"] ?? ""), ltrim($p["path"] ?? "", "/"),
        $p["host"] ?? "127.0.0.1", $p["port"] ?? 3306);
' "$RACINE/.env.local")"

mapfile -t connexion <<<"$lecture"
base_utilisateur="${connexion[0]}"
base_motdepasse="${connexion[1]}"
base_nom="${connexion[2]}"
base_hote="${connexion[3]}"
base_port="${connexion[4]}"

[[ -n "$base_nom" ]] || echouer "DATABASE_URL est illisible dans .env.local."

SAUVEGARDE="${MSSUB_SAUVEGARDE:-/var/backups/mssub-$(date +%Y%m%d-%H%M%S).sql.gz}"
mkdir -p "$(dirname "$SAUVEGARDE")"

# Le mot de passe passe par un fichier temporaire et non par la ligne de
# commande, où il serait visible dans la liste des processus.
identifiants="$(mktemp)"
chmod 600 "$identifiants"
trap 'rm -f "$identifiants"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
    "$base_utilisateur" "$base_motdepasse" "$base_hote" "$base_port" > "$identifiants"

if mysqldump --defaults-extra-file="$identifiants" --single-transaction "$base_nom" 2>>"$JOURNAL" | gzip > "$SAUVEGARDE"; then
    succes "Base sauvegardée dans $SAUVEGARDE ($(du -h "$SAUVEGARDE" | cut -f1))"
else
    echouer_avec_journal "Sauvegarde de la base"
fi

# ------------------------------------------------------------------ code

titre "Code"

# Les droits de production rendent l'arborescence non inscriptible : on la
# rouvre le temps du déploiement, elle sera refermée plus bas.
chown -R root:root "$RACINE"
chmod -R u+w "$RACINE"
git config --global --add safe.directory "$RACINE" 2>/dev/null || true

executer "Récupération des modifications" git fetch --all --tags --prune

if [[ -n "$REFERENCE" ]]; then
    executer "Bascule sur $REFERENCE" git checkout --force "$REFERENCE"
else
    branche="$(git rev-parse --abbrev-ref HEAD)"
    if [[ "$branche" == "HEAD" ]]; then
        echouer "Le dépôt est sur une étiquette, pas une branche — c'est l'état normal
   après un retour arrière. Indiquez explicitement où aller :
       sudo $0 v1.3.0     # une version précise
       sudo $0 main       # revenir sur la branche et suivre les nouveautés"
    fi
    executer "Mise à jour de la branche $branche" git merge --ff-only "origin/$branche"
fi

version_apres="$(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD)"

# Le script vient peut-être de se mettre à jour lui-même. Poursuivre avec la
# version chargée en mémoire déploierait la nouvelle version du code avec
# l'ancienne procédure — et priverait cette instance de toute correction
# apportée au déploiement lui-même. On repart donc avec la nouvelle.
#
# La garde évite la boucle : la relance ne se relance pas. Une seconde
# sauvegarde est prise au passage — les deux précèdent les migrations, elles
# valent donc la même chose, et une sauvegarde de trop n'a jamais nui.
if [[ -z "${MSSUB_RELANCE:-}" ]] && [[ "$empreinte_avant" != "$(sha256sum "$SCRIPT" | cut -d' ' -f1)" ]]; then
    info "Le script de mise à jour a lui-même changé : reprise avec la nouvelle version."
    export MSSUB_RELANCE=1
    exec "$SCRIPT" "$@"
fi

if [[ "$version_avant" == "$version_apres" ]]; then
    info "Déjà à jour ($version_apres) — les étapes suivantes restent jouées."
else
    succes "Passage de $version_avant à $version_apres"
fi

# ---------------------------------------------------------------- déploiement

titre "Déploiement"

export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
executer "Dépendances" env APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
executer "Migrations" php bin/console doctrine:migrations:migrate --no-interaction --env=prod
executer "Ressources" php bin/console asset-map:compile --env=prod
executer "Cache" php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod >>"$JOURNAL" 2>&1 || true

titre "Droits"

chown -R root:www-data "$RACINE"
chmod -R g-w "$RACINE"
chown -R www-data:www-data "$RACINE/var" "$RACINE/public/assets"
chown root:www-data "$RACINE/.env.local"
chmod 640 "$RACINE/.env.local"
succes "Arborescence refermée"

titre "Rechargement"

# Rechargement et non simple purge de cache : opcache.validate_timestamps est à
# zéro en production, et les options TLS du client LDAP sont globales au
# processus — un travailleur déjà démarré garderait les anciennes.
systemctl reload apache2 >>"$JOURNAL" 2>&1 || service apache2 reload >>"$JOURNAL" 2>&1 || true
succes "Apache rechargé"

# --------------------------------------------------------------- vérification

titre "Vérification"

php bin/console dbal:run-sql "SELECT 1" --env=prod >/dev/null 2>&1 \
    && succes "Connexion à la base établie" \
    || echouer_avec_journal "L'application ne parvient plus à joindre la base"

domaine="$(grep -m1 -oP 'ServerName\s+\K\S+' /etc/apache2/sites-available/mssub.conf 2>/dev/null || echo 'localhost')"
code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $domaine" http://127.0.0.1/connexion || echo '000')"

if [[ "$code" == "200" ]]; then
    succes "La page de connexion répond"
else
    alerte "Réponse inattendue sur /connexion : $code (attendu 200)"
    alerte "En cas de problème, restaurez : gunzip -c $SAUVEGARDE | mysql -u $base_utilisateur -p $base_nom"
fi

titre "Mise à jour terminée"

cat <<FIN
   Version        : $version_apres
   Sauvegarde     : $SAUVEGARDE
   Journal        : $JOURNAL

   En cas de régression, revenir en arrière :
     sudo $0 $version_avant
     gunzip -c $SAUVEGARDE | mysql -u $base_utilisateur -p $base_nom
FIN

printf '\n'
