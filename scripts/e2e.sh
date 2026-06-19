#!/usr/bin/env bash
# E2E test runner — Story 6.3
#
# Single command: requires only Docker on the host.
# Usage: bash scripts/e2e.sh [--reporter=<name>] [--grep=<pattern>]
#
# What it does:
#   1. Starts app + db with the E2E override (app.baseURL=http://app/, debug bar off)
#   2. Seeds E2E fixtures into MariaDB
#   3. Runs Playwright inside the official playwright Docker image
#   4. Propagates the Playwright exit code
#
# docker-compose.e2e.yml overrides app.baseURL to the Docker-internal service name so
# that all site_url() redirects are reachable from inside the Playwright container.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

COMPOSE_FILES=(-f "${PROJECT_ROOT}/docker-compose.yml" -f "${PROJECT_ROOT}/docker-compose.e2e.yml")

# Pass extra args to playwright (e.g. --reporter=list --grep=@smoke)
PLAYWRIGHT_EXTRA_ARGS=("$@")

# ----------------------------------------------------------------
# 1. Ensure core services are running and healthy (with E2E overrides)
# ----------------------------------------------------------------
echo "=== Démarrage des services (app, db) avec surcharge E2E ==="
docker compose "${COMPOSE_FILES[@]}" up -d app db

echo "=== Attente de la santé de db ==="
# timeout is GNU coreutils (Linux CI); gtimeout is the brew alias on macOS.
TIMEOUT_BIN=$(command -v timeout 2>/dev/null || command -v gtimeout 2>/dev/null || true)
_run_with_timeout() {
  local secs="$1"; shift
  if [ -n "${TIMEOUT_BIN}" ]; then
    "${TIMEOUT_BIN}" "${secs}" "$@"
  else
    "$@"
  fi
}

_run_with_timeout 120 bash -c "
  until docker compose ${COMPOSE_FILES[*]} exec -T db \
        mysql -ukermesse_user -pkermesse_password kermesse \
        -e 'SELECT 1' >/dev/null 2>&1; do
    sleep 3
  done
"

echo "=== Attente de la santé de app ==="
_run_with_timeout 120 bash -c "
  until docker compose ${COMPOSE_FILES[*]} exec -T app \
        curl -sf http://localhost/auth/login >/dev/null 2>&1; do
    sleep 3
  done
"

# ----------------------------------------------------------------
# 2. Seed E2E fixtures
# ----------------------------------------------------------------
echo "=== Initialisation des fixtures E2E ==="
docker compose "${COMPOSE_FILES[@]}" exec -T db \
  mysql -ukermesse_user -pkermesse_password kermesse \
  < "${PROJECT_ROOT}/e2e/fixtures/e2e-setup.sql"

echo "=== Fixtures chargées. ==="

# ----------------------------------------------------------------
# 3. Run Playwright inside the official image
# ----------------------------------------------------------------
echo "=== Lancement des tests Playwright ==="
docker compose \
  "${COMPOSE_FILES[@]}" \
  --profile e2e \
  run --rm \
  e2e-runner \
  bash -c "npm ci --include=dev --silent 2>&1 && npx playwright test ${PLAYWRIGHT_EXTRA_ARGS[*]:-}"

EXIT_CODE=$?

# ----------------------------------------------------------------
# 4. Report
# ----------------------------------------------------------------
if [ "${EXIT_CODE}" -eq 0 ]; then
  echo "=== Tests E2E : SUCCÈS ==="
else
  echo "=== Tests E2E : ÉCHEC (code ${EXIT_CODE}) ===" >&2
  echo "Artefacts disponibles dans test-results/ et playwright-report/" >&2
fi

exit "${EXIT_CODE}"
