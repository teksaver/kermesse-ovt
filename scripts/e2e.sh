#!/usr/bin/env bash
# E2E test runner — Story 6.3
#
# Single command: requires only Docker on the host.
# Usage: bash scripts/e2e.sh [--keep] [--reporter=<name>] [--grep=<pattern>]
#
# What it does:
#   1. Starts app + db with the E2E override (app.baseURL=http://app/, debug bar off)
#   2. Applies SQL migrations to the E2E MariaDB (idempotent — safe to re-run)
#   3. Seeds E2E fixtures
#   4. Runs Playwright inside the official playwright Docker image
#   5. With --keep, preserves the post-test database and recreates app for host review
#   6. Propagates the Playwright exit code
#   7. Otherwise cleans up containers (preserves test artefacts for diagnosis)
#
# docker-compose.e2e.yml overrides app.baseURL to the Docker-internal service name so
# that all site_url() redirects are reachable from inside the Playwright container.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

COMPOSE_FILES=(-f "${PROJECT_ROOT}/docker-compose.yml" -f "${PROJECT_ROOT}/docker-compose.e2e.yml")

# The automated phase must never inherit a host-facing override from the caller.
# Only the post-Playwright --keep transition may replace this value.
export E2E_APP_BASE_URL="http://app/"

# --keep: after Playwright, preserve the database and recreate only app with a
# host-facing baseURL. Combine with KERMESSE_HTTP_PORT=8085 to avoid conflicts.
KEEP_ALIVE=false
REVIEW_READY=false
PASS_ARGS=()
for _arg in "$@"; do
  if [ "${_arg}" = "--keep" ]; then
    KEEP_ALIVE=true
  else
    PASS_ARGS+=("${_arg}")
  fi
done
set -- "${PASS_ARGS[@]+${PASS_ARGS[@]}}"

# ----------------------------------------------------------------
# Cleanup trap — stops containers after the run, preserving artefacts.
# Runs on EXIT (success or failure) so test-results/ and playwright-report/
# are always available for diagnosis before containers are torn down.
# ----------------------------------------------------------------
_cleanup() {
  if [ "${KEEP_ALIVE}" = "true" ]; then
    local port="${KERMESSE_HTTP_PORT:-8080}"
    if [ "${REVIEW_READY}" = "true" ]; then
      echo "=== --keep : environnement E2E maintenu sur http://localhost:${port}/ ==="
    else
      echo "=== --keep : conteneurs maintenus, mais la revue hôte n'a pas été préparée ===" >&2
    fi
    echo "=== Pour arrêter : cd \"${PROJECT_ROOT}\" && docker compose -f docker-compose.yml -f docker-compose.e2e.yml down ==="
    return
  fi
  # Sauvegarder les logs app avant teardown (diagnostic CI — AC4)
  docker compose "${COMPOSE_FILES[@]}" logs app > "${PROJECT_ROOT}/app-logs-e2e.txt" 2>&1 || true
  echo "=== Nettoyage des conteneurs E2E (artefacts préservés) ==="
  docker compose "${COMPOSE_FILES[@]}" --profile e2e down --remove-orphans 2>/dev/null || true
}
trap _cleanup EXIT

# ----------------------------------------------------------------
# _run_with_timeout <seconds> <command...>
# Wraps a command with a deadline using timeout/gtimeout when available,
# or a background-process + date-based fallback when neither is installed.
# ----------------------------------------------------------------
TIMEOUT_BIN=$(command -v timeout 2>/dev/null || command -v gtimeout 2>/dev/null || true)
_run_with_timeout() {
  local secs="$1"; shift
  if [ -n "${TIMEOUT_BIN}" ]; then
    "${TIMEOUT_BIN}" "${secs}" "$@"
    return
  fi
  # Fallback: run command in background and poll until the deadline.
  "$@" &
  local pid=$! deadline
  deadline=$(( $(date +%s) + secs ))
  while kill -0 "${pid}" 2>/dev/null; do
    if [ "$(date +%s)" -ge "${deadline}" ]; then
      # Kill the entire process group to avoid orphan descendants (e.g. docker compose exec).
      kill -- -"${pid}" 2>/dev/null || kill "${pid}" 2>/dev/null || true
      wait "${pid}" 2>/dev/null || true
      echo "Timeout (${secs}s) écoulé en attendant : $*" >&2
      return 1
    fi
    sleep 1
  done
  wait "${pid}"
}

# ----------------------------------------------------------------
# 1. Start core services (with E2E overrides)
# ----------------------------------------------------------------
echo "=== Démarrage des services (app, db) avec surcharge E2E ==="
docker compose "${COMPOSE_FILES[@]}" up -d app db

echo "=== Attente de la santé de db ==="
_run_with_timeout 120 bash -c '
  until docker compose "$@" exec -T db \
        mysql -ukermesse_user -pkermesse_password kermesse \
        -e "SELECT 1" >/dev/null 2>&1; do
    sleep 3
  done
' -- "${COMPOSE_FILES[@]}"

echo "=== Attente de la santé de app ==="
_run_with_timeout 120 bash -c '
  until docker compose "$@" exec -T app \
        curl -sf http://localhost/auth/login >/dev/null 2>&1; do
    sleep 3
  done
' -- "${COMPOSE_FILES[@]}"

# ----------------------------------------------------------------
# 2. Apply SQL migrations on a clean dedicated E2E schema
#
# The app is redirected to `kermesse_e2e` (via docker-compose.e2e.yml) so the
# local dev/staging `kermesse` database is never touched.
# Drop + recreate guarantees a deterministic state across repeated runs: the DB
# volume persists between docker-compose up calls, so re-applying migrations on
# an already-migrated schema would fail (e.g. a backfill referencing a column
# dropped by a later migration). Mirrors the approach used in CI.
# ----------------------------------------------------------------
E2E_DB="kermesse_e2e"

echo "=== Réinitialisation du schéma E2E (${E2E_DB}) ==="
docker compose "${COMPOSE_FILES[@]}" exec -T db \
  mysql -uroot -proot_password \
  -e "DROP DATABASE IF EXISTS \`${E2E_DB}\`; CREATE DATABASE \`${E2E_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL PRIVILEGES ON \`${E2E_DB}\`.* TO 'kermesse_user'@'%'; FLUSH PRIVILEGES;"

echo "=== Application des migrations SQL MariaDB sur ${E2E_DB} ==="
for sql_file in "${PROJECT_ROOT}/database/migrations_sql/"*.sql; do
  if [ -f "${sql_file}" ]; then
    echo "  -> $(basename "${sql_file}")"
    docker compose "${COMPOSE_FILES[@]}" exec -T db \
      mysql -ukermesse_user -pkermesse_password "${E2E_DB}" \
      < "${sql_file}"
  fi
done
echo "=== Migrations appliquées. ==="

# ----------------------------------------------------------------
# 3. Seed E2E fixtures
# ----------------------------------------------------------------
echo "=== Initialisation des fixtures E2E ==="
docker compose "${COMPOSE_FILES[@]}" exec -T db \
  mysql -ukermesse_user -pkermesse_password "${E2E_DB}" \
  < "${PROJECT_ROOT}/e2e/fixtures/e2e-setup.sql"

echo "=== Fixtures chargées. ==="

# ----------------------------------------------------------------
# 4. Run Playwright inside the official image
#
# Pass extra args safely via "$@" inside the bash -c script so that
# arguments with spaces or special characters are preserved verbatim.
# Use `set +e` to capture the Playwright exit code without set -e
# exiting the script before the report and cleanup steps can run.
# ----------------------------------------------------------------
echo "=== Lancement des tests Playwright ==="
set +e
docker compose \
  "${COMPOSE_FILES[@]}" \
  --profile e2e \
  run --rm --no-deps \
  e2e-runner \
  bash -c 'npm ci --include=dev --silent && npx playwright test "$@"' -- "$@"
EXIT_CODE=$?
set -e

# ----------------------------------------------------------------
# 5. With --keep, recreate only app with a host-facing baseURL.
#
# The database and all post-test mutations remain untouched. Dedicated review
# tokens were seeded before Playwright and are not consumed by global-setup.ts.
# ----------------------------------------------------------------
_prepare_host_review() {
  local review_port="${KERMESSE_HTTP_PORT:-8080}"
  if [[ ! "${review_port}" =~ ^[0-9]+$ ]] \
    || ((10#${review_port} < 1 || 10#${review_port} > 65535)); then
    echo "KERMESSE_HTTP_PORT doit être un entier compris entre 1 et 65535." >&2
    return 2
  fi

  local review_base_url="http://localhost:${review_port}/"
  echo "=== Préparation de la revue hôte sur ${review_base_url} ==="
  E2E_APP_BASE_URL="${review_base_url}" docker compose \
    "${COMPOSE_FILES[@]}" \
    up -d --force-recreate --no-deps app || return $?

  # Keep the "Docker only" host prerequisite: readiness is checked from app.
  _run_with_timeout 120 bash -c '
    until docker compose "$@" exec -T app \
          curl -sf http://localhost/auth/login >/dev/null 2>&1; do
      sleep 2
    done
  ' -- "${COMPOSE_FILES[@]}" || return $?

  local configured_base_url
  configured_base_url=$(docker compose "${COMPOSE_FILES[@]}" exec -T app \
    printenv 'app.baseURL' | tr -d '\r') || return $?
  if [ "${configured_base_url}" != "${review_base_url}" ]; then
    echo "Base URL de revue inattendue : ${configured_base_url}" >&2
    return 1
  fi

  REVIEW_READY=true
  echo "=== Revue Owner        : ${review_base_url}auth/magic-link/e2e-review-owner-01 ==="
  echo "=== Revue Admin        : ${review_base_url}auth/magic-link/e2e-review-admin-01 ==="
  echo "=== Revue Gestionnaire : ${review_base_url}auth/magic-link/e2e-review-gestion-01 ==="
}

if [ "${KEEP_ALIVE}" = "true" ]; then
  if _prepare_host_review; then
    :
  else
    REVIEW_EXIT_CODE=$?
    echo "=== Préparation de la revue hôte : ÉCHEC (code ${REVIEW_EXIT_CODE}) ===" >&2
    if [ "${EXIT_CODE}" -eq 0 ]; then
      EXIT_CODE="${REVIEW_EXIT_CODE}"
    fi
  fi
fi

# ----------------------------------------------------------------
# 6. Report
# ----------------------------------------------------------------
if [ "${EXIT_CODE}" -eq 0 ]; then
  echo "=== Tests E2E : SUCCÈS ==="
else
  echo "=== Tests E2E : ÉCHEC (code ${EXIT_CODE}) ===" >&2
  echo "Artefacts disponibles dans test-results/ et playwright-report/" >&2
fi

exit "${EXIT_CODE}"
