#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p \
  writable/cache \
  writable/debugbar \
  writable/logs \
  writable/session \
  writable/uploads

installed_dependencies=0

if [ ! -f vendor/autoload.php ] \
  || [ composer.json -nt vendor/autoload.php ] \
  || [ composer.lock -nt vendor/autoload.php ]; then
  echo "Installing Composer dependencies in the container..."
  composer install --prefer-dist --no-interaction
  installed_dependencies=1
fi

chown -R www-data:www-data writable

if [ "${installed_dependencies}" -eq 1 ]; then
  chown -R www-data:www-data vendor
fi

exec "$@"
