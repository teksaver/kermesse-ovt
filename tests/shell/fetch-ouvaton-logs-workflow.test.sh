#!/usr/bin/env bash
# Guardrails for the manual production log fetch workflow.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${PROJECT_ROOT}/.github/workflows/fetch-ouvaton-logs.yml"

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

assert_contains "workflow manuel uniquement" "workflow_dispatch:"
assert_contains "protégé par environnement production" "environment: production"
assert_contains "récupère uniquement les logs partagés" 'remote_log="${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/writable/logs/log-${requested_date}.php"'
assert_contains "known_hosts strict" "StrictHostKeyChecking=yes"
assert_contains "artefact court-retention" "retention-days: 1"
assert_contains "date input passée par env" 'LOG_DATE_INPUT: ${{ inputs.log_date }}'
assert_contains "tail input passé par env" 'TAIL_LINES_INPUT: ${{ inputs.tail_lines }}'
assert_contains "pas de log complet en console" 'tail -n "${tail_lines}" "${raw_log}" > "${artifact_log}"'
assert_not_contains "ne cible aucun fichier de configuration" ".env"
assert_not_contains "pas d'interpolation directe log_date dans bash" 'requested_date="${{ inputs.log_date }}"'
assert_not_contains "pas d'interpolation directe tail_lines dans bash" 'tail_lines="${{ inputs.tail_lines }}"'

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : workflow fetch-ouvaton-logs invalide." >&2
  exit 1
fi

echo "TOUS LES TESTS fetch-ouvaton-logs workflow OK"
