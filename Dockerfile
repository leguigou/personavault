# PersonaVault — bibliothèque de personas (PHP 8.4 + Apache)
# L'app est un routeur PHP unique : public/index.php gère toutes les URL
# (FallbackResource ci-dessous), les assets sont servis directement.

FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libsqlite3-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_sqlite zip exif intl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-personavault.ini
COPY deploy/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/entrypoint.sh /usr/local/bin/pv-entrypoint
COPY deploy/make-config.php /usr/local/bin/pv-make-config
RUN chmod +x /usr/local/bin/pv-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html

# Les données (SQLite, uploads, sessions, sauvegardes) vivent dans un volume ;
# on ne garde dans l'image que l'arborescence vide.
RUN rm -rf storage config.local.php \
    && mkdir -p storage/uploads storage/sessions storage/tmp storage/backups \
    && chown -R www-data:www-data storage \
    && chmod 775 storage

ENTRYPOINT ["pv-entrypoint"]
CMD ["apache2-foreground"]
