#!/usr/bin/env bash
# Guardrails for Apache header propagation required by signed ops webhooks.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HTACCESS="${PROJECT_ROOT}/public/.htaccess"

fail=0

line_of() {
  local needle="$1"
  local line
  line="$(grep -nF "${needle}" "${HTACCESS}" | head -n 1 | cut -d: -f1 || true)"
  if [ -z "${line}" ]; then
    printf 'FAIL ligne absente : %s\n' "${needle}" >&2
    fail=1
    printf '0\n'
    return
  fi

  printf '%s\n' "${line}"
}

assert_before() {
  local label="$1" before="$2" after="$3"
  if [ "${before}" -eq 0 ] || [ "${after}" -eq 0 ]; then
    return
  fi

  if [ "${before}" -ge "${after}" ]; then
    printf 'FAIL %s\n  ligne %s doit précéder ligne %s\n' "${label}" "${before}" "${after}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

front_controller_line="$(line_of 'RewriteRule ^([\s\S]*)$ index.php/$1 [L,NC,QSA]')"
authorization_line="$(line_of 'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}')"
timestamp_line="$(line_of 'E=HTTP_X_KERMESSE_TIMESTAMP:%{HTTP:X-Kermesse-Timestamp}')"
nonce_line="$(line_of 'E=HTTP_X_KERMESSE_NONCE:%{HTTP:X-Kermesse-Nonce}')"
signature_line="$(line_of 'E=HTTP_X_KERMESSE_SIGNATURE:%{HTTP:X-Kermesse-Signature}')"

assert_before "Authorization propagé avant rewrite terminal" "${authorization_line}" "${front_controller_line}"
assert_before "Timestamp HMAC propagé avant rewrite terminal" "${timestamp_line}" "${front_controller_line}"
assert_before "Nonce HMAC propagé avant rewrite terminal" "${nonce_line}" "${front_controller_line}"
assert_before "Signature HMAC propagée avant rewrite terminal" "${signature_line}" "${front_controller_line}"

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : ordre public/.htaccess invalide." >&2
  exit 1
fi

echo "TOUS LES TESTS public/.htaccess OK"
