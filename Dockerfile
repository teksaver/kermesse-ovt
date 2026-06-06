# Version PHP cible Ouvaton, surchargée depuis docker-compose (build.args) pour
# garder la parité local ⇄ production (NFR-4). Mesure exacte à reconfirmer via /ops/probe.
ARG PHP_VERSION_OUVATON=8.3
FROM php:${PHP_VERSION_OUVATON}-apache

ARG COMPOSER_VERSION=2.8.12

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        gzip \
        libicu-dev \
        libzip-dev \
        tar \
        unzip \
        zip \
    && docker-php-ext-install intl mysqli pdo_mysql zip \
    && a2enmod rewrite \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/../!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --version="${COMPOSER_VERSION}" --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/app/entrypoint.sh /usr/local/bin/kermesse-entrypoint

ENTRYPOINT ["kermesse-entrypoint"]
CMD ["apache2-foreground"]
