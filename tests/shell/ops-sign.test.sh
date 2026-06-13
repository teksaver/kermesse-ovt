#!/usr/bin/env bash
# Test autonome de scripts/lib/ops-sign.sh — pré-vols HTTPS / HMAC.
# Exécuter : bash tests/shell/ops-sign.test.sh
# Sortie non-zéro si une assertion échoue.
#
# Régression couverte : l'étape « Activate release via bootstrap script » de
# deploy-ouvaton.yml tourne sous `set -u` SANS exporter OPS_HMAC_SECRET (elle
# s'authentifie au jeton bootstrap, pas en HMAC). Le pré-vol HTTPS ne doit donc
# JAMAIS lire OPS_HMAC_SECRET sans garde, sous peine de `unbound variable` fatal.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LIB="${PROJECT_ROOT}/scripts/lib/ops-sign.sh"

failures=0
report_pass() { printf 'ok   %s\n' "$1"; }
report_fail() { printf 'FAIL %s\n  %s\n' "$1" "$2" >&2; failures=1; }

# Exécute, dans un bash hermétique sous `set -u`, le pré-vol donné en sourçant la
# lib. `env -u OPS_HMAC_SECRET` garantit la variable non définie quand demandé.
# Renseigne les globales OUT (stdout+stderr) et RC (code retour).
run_prevol() { # <env-prefix...> -- <appel>
  local body="$1"
  OUT="$(env "${@:2}" bash -c "source '${LIB}'; set -u; ${body}" 2>&1)" && RC=0 || RC=$?
}

# --- ops_require_https : valide le schéma uniquement, sans dépendance HMAC ---

run_prevol "ops_require_https 'https://padlapin.fr'" -u OPS_HMAC_SECRET
if [ "${RC}" -eq 0 ] && ! printf '%s' "${OUT}" | grep -qi 'unbound'; then
  report_pass "https accepté sans OPS_HMAC_SECRET"
else
  report_fail "https accepté sans OPS_HMAC_SECRET" "code ${RC}, sortie: ${OUT}"
fi

run_prevol "ops_require_https 'http://padlapin.fr'" -u OPS_HMAC_SECRET
if [ "${RC}" -ne 0 ] && printf '%s' "${OUT}" | grep -q 'https://'; then
  report_pass "http rejeté avec message clair"
else
  report_fail "http rejeté avec message clair" "code ${RC}, sortie: ${OUT}"
fi

# --- ops_require_https_endpoint : sûr sous set -u (secret manquant/court/valide) ---

# RÉGRESSION : secret non défini + set -u → erreur métier claire, JAMAIS `unbound variable`.
run_prevol "ops_require_https_endpoint 'https://padlapin.fr' 32" -u OPS_HMAC_SECRET
if printf '%s' "${OUT}" | grep -qi 'unbound'; then
  report_fail "secret absent ne déclenche pas unbound variable" "sortie: ${OUT}"
elif [ "${RC}" -ne 0 ] && printf '%s' "${OUT}" | grep -q 'OPS_HMAC_SECRET'; then
  report_pass "secret absent → erreur métier claire (pas de unbound)"
else
  report_fail "secret absent → erreur métier claire (pas de unbound)" "code ${RC}, sortie: ${OUT}"
fi

run_prevol "ops_require_https_endpoint 'https://padlapin.fr' 32" OPS_HMAC_SECRET=court
if [ "${RC}" -ne 0 ] && printf '%s' "${OUT}" | grep -q '32'; then
  report_pass "secret trop court rejeté"
else
  report_fail "secret trop court rejeté" "code ${RC}, sortie: ${OUT}"
fi

long_secret="$(printf 'a%.0s' {1..40})"
run_prevol "ops_require_https_endpoint 'https://padlapin.fr' 32" "OPS_HMAC_SECRET=${long_secret}"
if [ "${RC}" -eq 0 ]; then
  report_pass "secret valide accepté"
else
  report_fail "secret valide accepté" "code ${RC}, sortie: ${OUT}"
fi

if [ "${failures}" -ne 0 ]; then
  echo "ÉCHEC : au moins une assertion ops-sign a échoué." >&2
  exit 1
fi
echo "TOUS LES TESTS ops-sign OK"
