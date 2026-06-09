#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p \
  writable/cache \
  writable/debugbar \
  writable/logs \
  writable/session \
  writable/uploads

# app et deploy-web partagent le même volume vendor:/var/www/html/vendor.
# Sans verrou, les deux conteneurs lancent composer install simultanément
# sur le même répertoire et corrompent mutuellement les archives zip.
# flock sur le volume composer-cache (lui aussi partagé) sérialise l'install.
mkdir -p /tmp/composer
(
  flock -w 300 9
  if [ ! -f vendor/autoload.php ] \
    || [ composer.json -nt vendor/autoload.php ] \
    || [ composer.lock -nt vendor/autoload.php ]; then
    echo "Installing Composer dependencies in the container..."
    composer install --prefer-dist --no-interaction
  fi
) 9>/tmp/composer/.install.lock

chown -R www-data:www-data writable vendor

exec "$@"
