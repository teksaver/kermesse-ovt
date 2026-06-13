#!/usr/bin/env bash
# Test autonome de scripts/transfer-archive.sh sans connexion SFTP.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

build_dir="${PROJECT_ROOT}/build"
archive="${build_dir}/kermesse-deploy.tar.gz"
checksum="${archive}.sha256"

mkdir -p "${build_dir}"
cleanup() {
  rm -f "${archive}" "${checksum}"
}
trap cleanup EXIT

printf 'archive\n' > "${archive}"
printf 'checksum  kermesse-deploy.tar.gz\n' > "${checksum}"

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
  TARGET_HOST="ftp.example.invalid" \
  TARGET_PORT="115" \
  TARGET_PROTO="sftp" \
  TARGET_USER="deploy'user" \
  TARGET_PASS='pa$$word' \
  REMOTE_STAGING="kermesse/staging" \
  KERMESSE_TRANSFER_ARCHIVE_DRY_RUN=true \
    bash "${PROJECT_ROOT}/scripts/transfer-archive.sh"
}

output="$(run_dry)"

assert_contains "fail-fast lftp activé" "${output}" "set cmd:fail-exit true"
assert_not_contains "pas de fallback silencieux mkdir" "${output}" "set cmd:fail-exit false"
assert_contains "création staging idempotente" "${output}" 'mkdir -p -f "kermesse/staging"'
assert_contains "vérification staging par cd" "${output}" 'cd "kermesse/staging"'
assert_contains "upload archive depuis staging courant" "${output}" "put \"${archive}\" -o \"kermesse-deploy.tar.gz\""
assert_contains "upload checksum depuis staging courant" "${output}" "put \"${checksum}\" -o \"kermesse-deploy.tar.gz.sha256\""
assert_contains "quote simple échappée" "${output}" "open -u 'deploy\\'user','pa\$\$word'"

if TARGET_HOST="ftp.example.invalid" \
  TARGET_PORT="115" \
  TARGET_PROTO="sftp" \
  TARGET_USER="deploy" \
  TARGET_PASS="secret" \
  REMOTE_STAGING="../bad" \
  KERMESSE_TRANSFER_ARCHIVE_DRY_RUN=true \
    bash "${PROJECT_ROOT}/scripts/transfer-archive.sh" >/dev/null 2>&1; then
  printf 'FAIL chemin staging invalide rejeté\n' >&2
  fail=1
else
  printf 'ok   chemin staging invalide rejeté\n'
fi

if [ "${fail}" -ne 0 ]; then
  echo "ÉCHEC : au moins une assertion a échoué." >&2
  exit 1
fi

echo "TOUS LES TESTS transfer-archive OK"
