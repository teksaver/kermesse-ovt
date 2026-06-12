#!/usr/bin/env bash
# Guardrails for deploy-ouvaton.yml webhook diagnostics.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${PROJECT_ROOT}/.github/workflows/deploy-ouvaton.yml"

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

assert_contains "body activation capturé" 'activate_response_body="${RUNNER_TEMP:-/tmp}/ops-activate-response.json"'
assert_contains "body activation affiché" 'cat "${activate_response_body}" >&2'
assert_not_contains "activation ne masque pas les 4xx avec curl -f" 'curl --max-time 60 --retry 3 -fsS'
assert_contains "activation utilise un helper curl partagé" 'call_activate()'
assert_contains "activation tente le routePath canonique" 'HTTP_CODE=$(call_activate "$ROUTE" "${activate_response_body}")'
assert_contains "activation fallback seulement sur ops_unauthorized" 'grep -Fq '"'"'"ops_unauthorized"'"'"' "${activate_response_body}"'
assert_contains "activation fallback routePath historique" 'HTTP_CODE=$(call_activate "ops/migrate" "${legacy_activate_response_body}")'

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : diagnostics webhook deploy-ouvaton invalides." >&2
  exit 1
fi

echo "TOUS LES TESTS deploy-ouvaton workflow OK"
