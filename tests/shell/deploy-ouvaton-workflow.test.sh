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
assert_contains "activation helper accepte une route cible" 'local target_route="${3:-${ROUTE}}"'
assert_contains "activation utilise un helper query string" 'call_activate_query()'
assert_contains "activation query string contient timestamp nonce signature" '?kts=${SIGN_TS}&kn=${SIGN_NONCE}&ks=${SIGN_SIG}'
assert_contains "activation tente le routePath canonique" 'HTTP_CODE=$(call_activate "$ROUTE" "${activate_response_body}")'
assert_contains "activation fallback seulement sur ops_unauthorized" 'is_ops_unauthorized()'
assert_contains "activation helper détecte ops_unauthorized" 'grep -Fq '"'"'"ops_unauthorized"'"'"' "${response_body}"'
assert_contains "activation fallback routePath historique" 'HTTP_CODE=$(call_activate "ops/migrate" "${legacy_activate_response_body}")'
assert_contains "activation retry front-controller direct" 'HTTP_CODE=$(call_activate "$ROUTE" "${direct_activate_response_body}" "index.php/${ROUTE}")'
assert_contains "activation retry front-controller historique" 'HTTP_CODE=$(call_activate "ops/migrate" "${direct_legacy_activate_response_body}" "index.php/${ROUTE}")'
assert_contains "activation retry query routePath canonique" 'HTTP_CODE=$(call_activate_query "$ROUTE" "${query_activate_response_body}")'
assert_contains "activation retry query routePath historique" 'HTTP_CODE=$(call_activate_query "ops/migrate" "${query_legacy_activate_response_body}")'
assert_contains "prévol compare le secret HMAC distant" 'Production ops HMAC secret verified against shared/.env.'
assert_contains "prévol échoue avant activation si secret divergent" 'Le secret HMAC de shared/.env ne correspond pas au secret GitHub OPS_MIGRATION_HMAC_SECRET.'
assert_contains "bootstrap legacy présent avant activation" 'Bootstrap legacy activation service'
assert_contains "bootstrap legacy conditionné à CURRENT_RELEASE" 'remote_current_marker="${OUVATON_DEPLOY_REMOTE_FOLDER}/CURRENT_RELEASE"'
assert_contains "bootstrap legacy patch uniquement le service activation" 'remote_legacy_service="${OUVATON_DEPLOY_REMOTE_FOLDER}/app/Services/ReleaseActivationService.php"'
assert_contains "bootstrap legacy saute si marker atomique présent" 'Atomic release marker exists; legacy activation bootstrap skipped.'
assert_contains "bootstrap legacy documente le contournement exec" 'the first atomic activation can run without exec().'
assert_contains "diagnostic récupère les logs après échec" 'Fetch production log tail on deploy failure'
assert_contains "diagnostic tente les logs CodeIgniter .log" 'for extension in log php; do'
assert_contains "diagnostic n'imprime que la fin du log" 'tail -n 120 "${fetched_log}"'

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : diagnostics webhook deploy-ouvaton invalides." >&2
  exit 1
fi

echo "TOUS LES TESTS deploy-ouvaton workflow OK"
