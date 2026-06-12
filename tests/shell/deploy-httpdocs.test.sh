#!/usr/bin/env bash
# Test autonome de scripts/deploy-httpdocs.sh.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

tmpdir="$(mktemp -d)"
trap 'rm -rf "${tmpdir}"' EXIT

public_dir="${tmpdir}/public"
shim_file="${tmpdir}/index.php"
mkdir -p "${public_dir}/assets"
printf 'RewriteEngine On\n' > "${public_dir}/.htaccess"
printf 'User-agent: *\n' > "${public_dir}/robots.txt"
printf 'body{}\n' > "${public_dir}/assets/app.css"

fail=0

assert_contains() {
  local label="$1" haystack="$2" needle="$3"
  if [[ "${haystack}" != *"${needle}"* ]]; then
    printf 'FAIL %s\n  attendu de trouver : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_not_contains() {
  local label="$1" haystack="$2" needle="$3"
  if [[ "${haystack}" == *"${needle}"* ]]; then
    printf 'FAIL %s\n  ne devait pas contenir : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

run_dry() {
  OUVATON_DEPLOY_HOST="ftp.example.invalid" \
  OUVATON_DEPLOY_USERNAME="deploy'user" \
  OUVATON_DEPLOY_PASSWORD='pa$$word' \
  OUVATON_DEPLOY_REMOTE_FOLDER="kermesse" \
  OUVATON_HTTPDOCS_FOLDER="httpdocs" \
  PUBLIC_DIR="${public_dir}" \
  HTTPDOCS_SHIM_FILE="${shim_file}" \
  KERMESSE_DEPLOY_HTTPDOCS_DRY_RUN=true \
    bash "${PROJECT_ROOT}/scripts/deploy-httpdocs.sh"
}

output="$(run_dry)"
shim="$(cat "${shim_file}")"

assert_contains "fail-fast lftp activé" "${output}" "set cmd:fail-exit yes"
assert_not_contains "pas de fallback silencieux lftp" "${output}" "set cmd:fail-exit no"
assert_contains "création cache idempotente" "${output}" "mkdir -p -f 'kermesse/shared/writable/cache'"
assert_contains "vérification cache par cd" "${output}" "cd 'kermesse/shared/writable/cache'"
assert_contains "upload shim" "${output}" "put '${shim_file}' -o index.php"
assert_contains "upload htaccess" "${output}" "put '${public_dir}/.htaccess' -o .htaccess"
assert_contains "upload robots" "${output}" "put '${public_dir}/robots.txt' -o robots.txt"
assert_contains "assets seuls synchronisés en mirror" "${output}" "mirror --reverse --delete assets/ assets/"
assert_contains "quote simple échappée" "${output}" "open -u 'deploy\\'user','pa\$\$word'"

assert_contains "shim pointe hors httpdocs" "${shim}" "../kermesse"
assert_contains "shim release courante" "${shim}" "\$deployRoot . '/current'"
assert_contains "shim shared env" "${shim}" "\$deployRoot . '/shared'"
assert_contains "shim shared writable" "${shim}" "\$deployRoot . '/shared/writable'"

if OUVATON_DEPLOY_HOST="ftp.example.invalid" \
  OUVATON_DEPLOY_USERNAME="deploy" \
  OUVATON_DEPLOY_PASSWORD="secret" \
  OUVATON_DEPLOY_REMOTE_FOLDER="../bad" \
  OUVATON_HTTPDOCS_FOLDER="httpdocs" \
  PUBLIC_DIR="${public_dir}" \
  KERMESSE_DEPLOY_HTTPDOCS_DRY_RUN=true \
    bash "${PROJECT_ROOT}/scripts/deploy-httpdocs.sh" >/dev/null 2>&1; then
  printf 'FAIL chemin applicatif invalide rejeté\n' >&2
  fail=1
else
  printf 'ok   chemin applicatif invalide rejeté\n'
fi

if OUVATON_DEPLOY_HOST="ftp.example.invalid" \
  OUVATON_DEPLOY_USERNAME="deploy" \
  OUVATON_DEPLOY_PASSWORD="secret" \
  OUVATON_DEPLOY_REMOTE_FOLDER="kermesse" \
  OUVATON_HTTPDOCS_FOLDER="httpdocs" \
  PUBLIC_DIR="${tmpdir}/missing" \
  KERMESSE_DEPLOY_HTTPDOCS_DRY_RUN=true \
    bash "${PROJECT_ROOT}/scripts/deploy-httpdocs.sh" >/dev/null 2>&1; then
  printf 'FAIL répertoire public manquant rejeté\n' >&2
  fail=1
else
  printf 'ok   répertoire public manquant rejeté\n'
fi

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : au moins une assertion a échoué." >&2
  exit 1
fi

echo "TOUS LES TESTS deploy-httpdocs OK"
