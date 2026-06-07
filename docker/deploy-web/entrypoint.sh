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

installed_dependencies=0
if [ ! -f vendor/autoload.php ] \
  || [ composer.json -nt vendor/autoload.php ] \
  || [ composer.lock -nt vendor/autoload.php ]; then
  echo "Installing Composer dependencies in the deploy-web container..."
  composer install --prefer-dist --no-interaction
  installed_dependencies=1
fi

chown -R www-data:www-data writable
if [ "${installed_dependencies}" -eq 1 ]; then
  chown -R www-data:www-data vendor
fi

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

chown -R www-data:www-data "${DEPLOY_DATA}/shared/writable"

# Bootstrap shim: seed httpdocs/index.php so that ops endpoints (/ops/activate,
# /ops/migrate, /ops/probe) are reachable before the first release is activated.
# The real deploy process overwrites this shim with one pointing to ../current/.
if [ ! -f "${DEPLOY_DATA}/httpdocs/index.php" ]; then
  cat > "${DEPLOY_DATA}/httpdocs/index.php" << 'PHPEOF'
<?php
// Rehearsal bootstrap shim — loads the app from the local source mount at /var/www/html.
// Replaced by the real shim (pointing to ../current/) after the first activation.
use CodeIgniter\Boot;
use Config\Paths;

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) { chdir(FCPATH); }
$appRoot = '/var/www/html';
require $appRoot . '/app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootWeb($paths));
PHPEOF
fi

exec "$@"
