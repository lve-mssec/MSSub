#!/usr/bin/env bash
#
# Engendre une autorité de certification et un certificat serveur pour
# l'annuaire de développement.
#
# Sans TLS, le conteneur ne permet d'exercer que le bind en clair — or c'est
# précisément ce qu'un Active Directory durci refuse. Ces certificats servent
# donc à tester les deux chemins réellement utilisés en production : LDAPS avec
# une autorité interne, et StartTLS.
#
# Les fichiers ne sont pas versionnés : une clé privée, même de test, n'a rien
# à faire dans un dépôt.

set -Eeuo pipefail

destination="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/certs"
mkdir -p "$destination"
cd "$destination"

if [[ -f ca.crt && -f ldap.crt && -f ldap.key ]]; then
    echo "Certificats déjà présents dans $destination"
    exit 0
fi

# Autorité interne, à l'image d'une autorité d'entreprise.
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout ca.key -out ca.crt \
    -subj "/C=FR/O=MSSEC/CN=Autorite de test MSSub" 2>/dev/null

# Le nom commun doit être celui par lequel l'application joint l'annuaire,
# sinon la vérification du certificat échouera pour une raison sans rapport.
openssl req -newkey rsa:2048 -nodes \
    -keyout ldap.key -out ldap.csr \
    -subj "/C=FR/O=MSSEC/CN=annuaire" 2>/dev/null

openssl x509 -req -in ldap.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
    -out ldap.crt -days 3650 \
    -extfile <(printf 'subjectAltName=DNS:annuaire,DNS:localhost,IP:127.0.0.1') 2>/dev/null

rm -f ldap.csr ca.srl
chmod 644 ./*.crt
chmod 640 ./*.key

echo "Certificats engendrés dans $destination"
