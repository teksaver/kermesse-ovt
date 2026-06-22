#!/usr/bin/env bash
# Guardrails for deploy-ouvaton.yml bootstrap activation and diagnostics.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${PROJECT_ROOT}/.github/workflows/deploy-ouvaton.yml"

fail=0

assert_contains() {
  local label="$1" needle="$2"
  if ! grep -Fq -- "${needle}" "${WORKFLOW}"; then
    printf 'FAIL %s\n  attendu de trouver : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_not_contains() {
  local label="$1" needle="$2"
  if grep -Fq -- "${needle}" "${WORKFLOW}"; then
    printf 'FAIL %s\n  ne devait pas contenir : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_contains "bootstrap script généré" 'Generate bootstrap activation script'
assert_contains "bootstrap token aléatoire" 'BOOTSTRAP_ACTIVATE_TOKEN="$(openssl rand -hex 32)"'
assert_contains "bootstrap token masqué" 'echo "::add-mask::${BOOTSTRAP_ACTIVATE_TOKEN}"'
assert_contains "bootstrap token transmis entre steps" 'printf '"'"'BOOTSTRAP_ACTIVATE_TOKEN=%s\n'"'"' "${BOOTSTRAP_ACTIVATE_TOKEN}" >> "${GITHUB_ENV}"'
assert_contains "bootstrap script déployé" 'deploy/ops-bootstrap-activate.tpl.php > deploy-staging/public/ops-bootstrap-activate.php'
assert_contains "activation bootstrap directe" 'ACTIVATE_URL="${BASE_URL}/ops-bootstrap-activate.php"'
assert_contains "activation utilise POST" 'curl --max-time 120 --retry 3 -sS ${CURL_TLS_ARGS[@]:+"${CURL_TLS_ARGS[@]}"} -X POST'
assert_contains "activation token en header" '-H "X-Kermesse-Bootstrap-Token: ${BOOTSTRAP_ACTIVATE_TOKEN}"'
assert_not_contains "activation ne met pas le token en query string" 'token='
assert_contains "body activation capturé" 'activate_response_body="${RUNNER_TEMP:-/tmp}/ops-activate-response.json"'
assert_contains "body activation affiché" 'cat "${activate_response_body}" >&2'
assert_contains "cleanup bootstrap always" 'if: always()'
assert_contains "cleanup cible le script temporaire" 'remote_script="${OUVATON_HTTPDOCS_FOLDER}/ops-bootstrap-activate.php"'
assert_contains "cleanup ignoré avant génération bootstrap" 'Aucun script bootstrap généré dans ce run ; cleanup distant ignoré.'
assert_contains "cleanup reste conditionné au token bootstrap" 'if [ -z "${BOOTSTRAP_ACTIVATE_TOKEN:-}" ]; then'
assert_contains "premier déploiement amorce le env absent" 'Ensure production .env exists for first install'
assert_contains "deploy utilise le mode ensure-present" 'SYNC_ENV_MODE: ensure-present'
assert_contains "deploy appelle le sync env partagé" 'bash scripts/sync-production-env.sh'
assert_contains "deploy peut générer le env initial" 'KERMESSE_DATABASE_PASSWORD: ${{ secrets.KERMESSE_DATABASE_PASSWORD }}'
assert_contains "prévol compare le secret HMAC distant" 'Production ops HMAC secret verified against shared/.env.'
assert_contains "prévol échoue avant activation si secret divergent" 'Le secret HMAC de shared/.env ne correspond pas au secret GitHub OPS_MIGRATION_HMAC_SECRET.'
assert_contains "activation bootstrap valide https sans HMAC" 'ops_require_https "$BASE_URL"'
assert_contains "migrations gardent le pré-vol HMAC" 'ops_require_https_endpoint "$BASE_URL" 32'
assert_contains "migrations restent signées HMAC" 'ROUTE="ops/migrate"'
assert_contains "diagnostic récupère les logs après échec" 'Fetch production log tail on deploy failure'
assert_contains "diagnostic tente les logs CodeIgniter .log" 'for extension in log php; do'
assert_contains "diagnostic n'imprime que la fin du log" 'tail -n 120 "${fetched_log}"'

# ─── Story 6.9 : promotion immuable sans rebuild ────────────────────────────
assert_not_contains "plus de build-and-package job" 'build-and-package'
assert_contains "job download-and-verify présent" 'download-and-verify'
assert_contains "input ci_run_id pour dispatch manuel" 'ci_run_id'
assert_contains "input expected_sha pour dispatch manuel" 'expected_sha'
assert_contains "téléchargement cross-run par run-id" 'run-id:'
assert_contains "téléchargement utilise github-token" 'github-token:'
assert_contains "vérification manifeste après download" 'Verify manifest and archive identity'
assert_contains "validation run CI = success" 'Only successful runs may be promoted'
assert_contains "refus branche non-main" 'Only main branch runs may be promoted'
assert_contains "refus SHA discordant" 'Refusing to promote'
assert_contains "refus workflow non-CI" 'Only CI workflow runs may be promoted'
assert_contains "double vérification SHA avant deploy" 'Verify artifact identity before deploy'
assert_contains "checkout SHA candidat (job deploy)" 'candidate_sha'
assert_not_contains "plus de composer install dans deploy" 'Install dependencies (with dev for tests)'
assert_not_contains "plus de phpunit dans deploy" 'vendor/bin/phpunit'
assert_not_contains "plus de packaging dans deploy" 'package-deploy-artifact.sh'
assert_contains "outputs archive_sha256 entre jobs" 'archive_sha256'
assert_contains "outputs candidate_sha entre jobs" 'candidate_sha'
assert_contains "outputs ci_run_id entre jobs" 'ci_run_id'
assert_contains "env préservé sans écriture" 'shared/.env'

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : diagnostics webhook deploy-ouvaton invalides." >&2
  exit 1
fi

echo "TOUS LES TESTS deploy-ouvaton workflow OK"
