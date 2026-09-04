# MSSub — image applicative : Debian + Apache 2.4 + PHP 8.3, au plus près de la cible de production.
FROM php:8.3-apache-bookworm

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
