#!/usr/bin/env bash
# Test autonome de scripts/lib/lftp-escape.sh (lftp_squote).
# Exécuter : bash tests/shell/lftp-escape.test.sh
# Sortie non-zéro si une assertion échoue.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=../../scripts/lib/lftp-escape.sh
source "${PROJECT_ROOT}/scripts/lib/lftp-escape.sh"

fail=0
assert_eq() {
  local label="$1" expected="$2" actual="$3"
  if [ "${expected}" != "${actual}" ]; then
    printf 'FAIL %s\n  attendu : %q\n  obtenu  : %q\n' "${label}" "${expected}" "${actual}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

# Caractères « difficiles » construits par leur code (robuste, sans heredoc ni quoting fragile).
bs=$'\x5c'   # backslash  \
sq=$'\x27'   # quote simple '

assert_eq "valeur simple inchangée"       "deploy_user"             "$(lftp_squote "deploy_user")"
assert_eq "quote simple échappée"         "pa${bs}${sq}s"           "$(lftp_squote "pa${sq}s")"
assert_eq "backslash doublé"              "pa${bs}${bs}s"           "$(lftp_squote "pa${bs}s")"
# Ordre critique : backslash traité AVANT la quote → pas de double-échappement.
assert_eq "backslash AVANT quote"         "a${bs}${bs}${bs}${sq}b"  "$(lftp_squote "a${bs}${sq}b")"
# Le $ n'est PAS échappé : lftp ne fait pas d'expansion shell — laissé littéral (cf. review #4).
assert_eq "dollar laissé littéral"        'pa$$word'                "$(lftp_squote 'pa$$word')"
assert_eq "chaîne vide"                   ""                        "$(lftp_squote "")"

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : au moins une assertion a échoué." >&2
  exit 1
fi
echo "TOUS LES TESTS lftp_squote OK"
