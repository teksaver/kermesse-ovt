#!/usr/bin/env bash
# Entrypoint for the rehearsal deploy-web container.
# Performs shared app setup (Composer, writable/) and deploy-target-data init,
# then hands off to the standard command (apache2-foreground).
set -euo pipefail

cd /var/www/html

# ----- Shared setup (mirrors kermesse-entrypoint) ---------------------------
mkdir -p \
  writable/cache \
  writable/debugbar \
  writable/logs \
  writable/session \
  writable/uploads

# Même verrou que app/entrypoint.sh : app et deploy-web partagent vendor:
# et composer-cache:, donc un seul des deux installe ; l'autre attend et saute.
mkdir -p /tmp/composer
(
  flock -w 300 9
  if [ ! -f vendor/autoload.php ] \
    || [ composer.json -nt vendor/autoload.php ] \
    || [ composer.lock -nt vendor/autoload.php ]; then
    echo "Installing Composer dependencies in the deploy-web container..."
    composer install --prefer-dist --no-interaction
  fi
) 9>/tmp/composer/.install.lock

chown -R www-data:www-data writable vendor

# ----- Deploy-target-data volume init ----------------------------------------
# Creates the rehearsal deployment layout on the shared volume so both
# deploy-target (SFTP) and deploy-web (Apache) see the correct structure.
DEPLOY_DATA=/srv/deploy-data
mkdir -p \
  "${DEPLOY_DATA}/staging" \
  "${DEPLOY_DATA}/releases" \
  "${DEPLOY_DATA}/shared/writable/cache" \
  "${DEPLOY_DATA}/shared/writable/logs" \
  "${DEPLOY_DATA}/shared/writable/session" \
  "${DEPLOY_DATA}/shared/writable/uploads" \
  "${DEPLOY_DATA}/httpdocs"

# Make the base deploy-data root writable so the activation service can write
# CURRENT_RELEASE and create the current symlink directly under it.
chown www-data:www-data "${DEPLOY_DATA}"
chown -R www-data:www-data "${DEPLOY_DATA}/shared/writable"

# Bootstrap shim: seed httpdocs/index.php and .htaccess so that ops endpoints
# (/ops/activate, /ops/migrate, /ops/probe) are reachable before the first release
# is activated. The real deploy process overwrites these with release-aware versions.
if [ ! -f "${DEPLOY_DATA}/httpdocs/index.php" ]; then
  cat > "${DEPLOY_DATA}/httpdocs/index.php" << 'PHPEOF'
<?php
// Rehearsal bootstrap shim — loads the app from the local source mount at /var/www/html.
// Replaced by the real shim (pointing to ../current/) after the first activation.
use CodeIgniter\Boot;
use Config\Paths;

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) { chdir(FCPATH); }
$appRoot = realpath(__DIR__ . '/../current');
if ($appRoot === false) { $appRoot = '/var/www/html'; }
require $appRoot . '/app/Config/Paths.php';
$paths = new Paths();
$paths->envDirectory = realpath(__DIR__ . '/../shared');
$paths->writableDirectory = realpath(__DIR__ . '/../shared/writable');
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootWeb($paths));
PHPEOF
fi

# .htaccess: route all requests through index.php (required for CodeIgniter clean URLs).
# Without this Apache returns 404 for any non-root path under httpdocs/.
if [ ! -f "${DEPLOY_DATA}/httpdocs/.htaccess" ]; then
  cp /var/www/html/public/.htaccess "${DEPLOY_DATA}/httpdocs/.htaccess"
fi

exec "$@"
