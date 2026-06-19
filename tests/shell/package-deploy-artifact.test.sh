#!/usr/bin/env bash
# Guardrails for the Ouvaton deploy artifact packaging script.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${PROJECT_ROOT}/scripts/package-deploy-artifact.sh"

fail=0

assert_contains() {
  local label="$1" needle="$2"
  if ! grep -Fq -- "${needle}" "${SCRIPT}"; then
    printf 'FAIL %s\n  attendu de trouver : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_contains "packaging interdit les migrations CI4 natives" 'app/Database/Migrations'
assert_contains "packaging cible les fichiers PHP de migration CI4" '-type f -name "*.php"'
assert_contains "message oriente vers le runner SQL" 'database/migrations_sql/*.sql'

# Story 6.3 — E2E / Node artefacts exclus de l'archive Ouvaton
assert_contains "packaging exclut le dossier e2e" '"e2e"'
assert_contains "packaging exclut playwright-report" '"playwright-report"'
assert_contains "packaging exclut test-results" '"test-results"'
assert_contains "packaging exclut package.json" '"package.json"'
assert_contains "packaging exclut package-lock.json" '"package-lock.json"'
assert_contains "packaging exclut playwright.config.ts" '"playwright.config.ts"'
assert_contains "packaging exclut les specs .spec.ts" '*.spec.ts'

if [ "${fail}" -ne 0 ]; then
  echo "ECHEC : garde-fous package-deploy-artifact invalides." >&2
  exit 1
fi

echo "TOUS LES TESTS package-deploy-artifact OK"
