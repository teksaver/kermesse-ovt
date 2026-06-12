#!/usr/bin/env bash
# Guardrails for production .env generation in sync-production-env.yml.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${PROJECT_ROOT}/.github/workflows/sync-production-env.yml"

fail=0

assert_contains() {
  local label="$1" needle="$2"
  if ! grep -Fq "${needle}" "${WORKFLOW}"; then
    printf 'FAIL %s\n  attendu de trouver : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_not_contains() {
  local label="$1" needle="$2"
  if grep -Fq "${needle}" "${WORKFLOW}"; then
    printf 'FAIL %s\n  ne devait pas contenir : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_contains "session.savePath pointe vers shared/writable" 'session_save_path="${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/writable/session"'
assert_contains "opsActivateBasePath est explicite" 'write_quoted kermesse.opsActivateBasePath "${deploy_remote_path}"'
assert_not_contains "opsActivateBasePath ne reste pas vide" "write_raw kermesse.opsActivateBasePath ''"

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : configuration sync-production-env invalide." >&2
  exit 1
fi

echo "TOUS LES TESTS sync-production-env config OK"
