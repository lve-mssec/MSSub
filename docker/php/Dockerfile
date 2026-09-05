# MSSub — image applicative : Debian + Apache 2.4 + PHP, au plus près de la cible
# de production.
#
# La version de PHP est un paramètre de construction : elle permet de rejouer la
# suite de tests sur la version d'une distribution cible avant de l'annoncer
# comme supportée. Debian 13 fournit PHP 8.4, Debian 12 fournit PHP 8.2.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache-bookworm

# libldap2-dev : l'authentification annuaire fait partie du périmètre v1.
# libzip/gd/icu : import XLSX (PhpSpreadsheet) et formatage localisé.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libldap2-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -q DEB_HOST_MULTIARCH)" \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip ldap gd opcache \
    && a2enmod rewrite headers \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini  /usr/local/etc/php/conf.d/99-mssub.ini
COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
