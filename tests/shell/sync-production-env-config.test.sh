#!/usr/bin/env bash
# Guardrails for production .env generation in sync-production-env.yml.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${PROJECT_ROOT}/.github/workflows/sync-production-env.yml"
SCRIPT="${PROJECT_ROOT}/scripts/sync-production-env.sh"

fail=0

assert_contains() {
  local label="$1" needle="$2"
  local file="${3:-${SCRIPT}}"
  if ! grep -Fq "${needle}" "${file}"; then
    printf 'FAIL %s\n  attendu de trouver : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_not_contains() {
  local label="$1" needle="$2"
  local file="${3:-${SCRIPT}}"
  if grep -Fq "${needle}" "${file}"; then
    printf 'FAIL %s\n  ne devait pas contenir : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_contains "workflow checkout le script versionné" 'uses: actions/checkout@v4' "${WORKFLOW}"
assert_contains "workflow appelle le sync partagé" 'bash scripts/sync-production-env.sh' "${WORKFLOW}"
assert_contains "workflow manuel force la mise à jour" 'SYNC_ENV_MODE: always' "${WORKFLOW}"
assert_contains "script ne dépend pas de CodeIgniter" 'It does not load' "${SCRIPT}"
assert_contains "mode first install sans overwrite" 'SYNC_ENV_MODE=ensure-present' "${SCRIPT}"
assert_contains "ensure-present sort si .env existe" 'Production shared/.env already exists on Ouvaton; deploy will not modify it.' "${SCRIPT}"
assert_contains "ensure-present refuse la course à l overwrite" 'shared/.env appeared during first-install bootstrap; refusing to overwrite it.' "${SCRIPT}"
assert_contains "création du layout shared" 'mkdir -p -f %s' "${SCRIPT}"
assert_contains "session.savePath pointe vers shared/writable" 'session_save_path="${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/writable/session"'
assert_contains "opsActivateBasePath est explicite" 'write_quoted kermesse.opsActivateBasePath "${deploy_remote_path}"'
assert_not_contains "opsActivateBasePath ne reste pas vide" "write_raw kermesse.opsActivateBasePath ''"
assert_not_contains "script n'appelle pas spark" "spark" "${SCRIPT}"
assert_not_contains "script n'appelle pas composer" "composer" "${SCRIPT}"
assert_not_contains "script n'exécute pas php" "php " "${SCRIPT}"

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : configuration sync-production-env invalide." >&2
  exit 1
fi

echo "TOUS LES TESTS sync-production-env config OK"
