# Image cliente de répétition de déploiement (FR-21).
#
# Embarque TOUTE la chaîne packaging → transfert → ops pour que l'HÔTE n'ait besoin
# que de Docker (plus de lftp / openssl / ssh-keyscan / composer côté hôte) :
#   - PHP + Composer + extensions  → packaging (scripts/package-deploy-artifact.sh)
#   - lftp + openssh-client        → transfert SFTP (scripts/transfer-archive.sh)
#   - curl + openssl               → appels ops signés HMAC (activate / migrate / status)
#   - default-mysql-client         → remise à zéro DB (--reset)
#
# Base alignée sur l'image app/deploy-web (php:8.3-apache, parité Ouvaton NFR-4) afin que
# bash soit ≥ 5 (fin des contournements bash 3.2 macOS) et que `www-data` y soit le même
# uid (33) que dans deploy-web — important pour les chown du --reset sur le volume partagé.
ARG PHP_VERSION_OUVATON=8.3
FROM php:${PHP_VERSION_OUVATON}-apache

ARG COMPOSER_VERSION=2.8.12

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# Mêmes extensions que le Dockerfile applicatif (intl, mysqli, pdo_mysql, zip) pour que
# `composer install` du packaging ne bute pas sur les platform-reqs, plus l'outillage de
# transfert/ops/reset. bash est déjà présent (base Debian) mais explicité pour l'intention.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        default-mysql-client \
        git \
        gzip \
        libicu-dev \
        libzip-dev \
        lftp \
        openssh-client \
        openssl \
        tar \
        unzip \
        zip \
    && docker-php-ext-install intl mysqli pdo_mysql zip \
    && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --version="${COMPOSER_VERSION}" --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Le dépôt est monté ici en lecture/écriture (bind) : les scripts/*.sh exécutés sont CEUX
# du dépôt (aucun fork local-only — FR-18), et build/ atterrit sur le bind de l'hôte.
WORKDIR /workspace

# Pas de serveur : ce conteneur est lancé en one-shot via `docker compose run --rm`.
ENTRYPOINT []
CMD ["bash"]
