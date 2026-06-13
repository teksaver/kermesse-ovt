#!/usr/bin/env bash
# Test autonome du bootstrap d'activation hors CodeIgniter.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

tmpdir="$(mktemp -d)"
trap 'rm -rf "${tmpdir}"' EXIT

home_dir="${tmpdir}/home"
httpdocs_dir="${home_dir}/httpdocs"
kerm_dir="${home_dir}/kermesse"
archive_src="${tmpdir}/archive-src"
script_file="${httpdocs_dir}/ops-bootstrap-activate.php"

mkdir -p "${httpdocs_dir}" \
  "${kerm_dir}/staging" \
  "${kerm_dir}/shared/writable/cache" \
  "${archive_src}/app" \
  "${archive_src}/vendor" \
  "${archive_src}/public" \
  "${archive_src}/database/migrations_sql"

printf 'placeholder\n' > "${archive_src}/app/.keep"
printf 'placeholder\n' > "${archive_src}/vendor/.keep"
printf 'placeholder\n' > "${archive_src}/public/index.php"
printf 'placeholder\n' > "${archive_src}/database/migrations_sql/.keep"
printf 'kermesse.releasesRetention = 2\n' > "${kerm_dir}/shared/.env"

sed -e 's|__BOOTSTRAP_TOKEN__|test-bootstrap-token|g' \
    -e 's|__REMOTE_FOLDER__|kermesse|g' \
    "${PROJECT_ROOT}/deploy/ops-bootstrap-activate.tpl.php" > "${script_file}"

(cd "${archive_src}" && tar -czf "${kerm_dir}/staging/kermesse-deploy.tar.gz" app vendor public database)

if command -v sha256sum >/dev/null 2>&1; then
  (cd "${kerm_dir}/staging" && sha256sum kermesse-deploy.tar.gz) > "${kerm_dir}/staging/kermesse-deploy.tar.gz.sha256"
else
  (cd "${kerm_dir}/staging" && shasum -a 256 kermesse-deploy.tar.gz) > "${kerm_dir}/staging/kermesse-deploy.tar.gz.sha256"
fi

response="$(php -r '
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["HTTP_X_KERMESSE_BOOTSTRAP_TOKEN"] = "test-bootstrap-token";
require $argv[1];
' "${script_file}")"

if [[ "${response}" != *'"ok":true'* ]]; then
  printf 'FAIL activation bootstrap\n%s\n' "${response}" >&2
  exit 1
fi

if [ -f "${kerm_dir}/staging/kermesse-deploy.tar.gz" ]; then
  printf 'FAIL archive non supprimée après activation\n' >&2
  exit 1
fi

if [ ! -f "${kerm_dir}/CURRENT_RELEASE" ]; then
  printf 'FAIL pointeur CURRENT_RELEASE absent\n' >&2
  exit 1
fi

release_name="$(tr -d '[:space:]' < "${kerm_dir}/CURRENT_RELEASE")"
release_dir="${kerm_dir}/releases/${release_name}"

for required in app vendor public database/migrations_sql; do
  if [ ! -d "${release_dir}/${required}" ]; then
    printf 'FAIL dossier requis absent après activation: %s\n' "${required}" >&2
    exit 1
  fi
done

echo "TOUS LES TESTS ops-bootstrap-activate OK"
