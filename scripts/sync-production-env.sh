#!/usr/bin/env bash
# Generate and synchronize the production shared/.env on Ouvaton.
#
# Modes:
#   SYNC_ENV_MODE=always         Update shared/.env, backing up the previous file when present.
#   SYNC_ENV_MODE=ensure-present Create shared/.env only when it is missing; never overwrite it.
#
# This script intentionally depends only on bash + lftp. It does not load
# CodeIgniter, Composer, PHP, or any deployed release.
set -euo pipefail

MODE="${SYNC_ENV_MODE:-always}"
case "${MODE}" in
  always|ensure-present) ;;
  *)
    echo "::error::SYNC_ENV_MODE must be either 'always' or 'ensure-present'."
    exit 1
    ;;
esac

require_present() {
  local name="$1"
  local value="${!name:-}"
  local stripped="${value//[[:space:]]/}"
  if [ -z "${stripped}" ]; then
    echo "::error::Missing required production configuration: ${name}"
    return 1
  fi
}

require_group() {
  local missing=0
  local name
  for name in "$@"; do
    if ! require_present "${name}"; then
      missing=1
    fi
  done

  if [ "${missing}" -ne 0 ]; then
    exit 1
  fi
}

reject_multiline() {
  local name="$1"
  local value="${!name:-}"
  if [[ "${value}" == *$'\n'* || "${value}" == *$'\r'* ]]; then
    echo "::error::Production configuration must be single-line: ${name}"
    exit 1
  fi
}

reject_dotenv_nested_reference() {
  local name="$1"
  local value="${!name:-}"
  if [[ "${value}" =~ \$\{[A-Za-z0-9_.]+\} ]]; then
    echo "::error::Production configuration must not contain dotenv nested-variable syntax: ${name}"
    exit 1
  fi
}

lftp_quote() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  printf '"%s"' "${value}"
}

require_group \
  OUVATON_DEPLOY_HOST \
  OUVATON_DEPLOY_USERNAME \
  OUVATON_DEPLOY_REMOTE_FOLDER \
  OUVATON_DEPLOY_PASSWORD \
  OUVATON_SFTP_KNOWN_HOST

for var_name in \
  OUVATON_DEPLOY_HOST \
  OUVATON_DEPLOY_USERNAME \
  OUVATON_DEPLOY_REMOTE_FOLDER \
  OUVATON_DEPLOY_PASSWORD \
  OUVATON_SFTP_KNOWN_HOST
do
  reject_multiline "${var_name}"
done

if [[ "${OUVATON_DEPLOY_REMOTE_FOLDER}" = /* || "${OUVATON_DEPLOY_REMOTE_FOLDER}" = *..* ]]; then
  echo "::error::OUVATON_DEPLOY_REMOTE_FOLDER must be a relative folder path without '..'."
  exit 1
fi

if [[ ! "${OUVATON_DEPLOY_REMOTE_FOLDER}" =~ ^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$ ]]; then
  echo "::error::OUVATON_DEPLOY_REMOTE_FOLDER contains invalid characters."
  exit 1
fi

runner_temp="${RUNNER_TEMP:-/tmp}"
known_hosts_file="${runner_temp}/ouvaton_known_hosts"
env_next="${runner_temp}/.env.next"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
local_backup="${runner_temp}/.env.backup-${timestamp}"
remote_backup=".env.backup-${timestamp}"
remote_previous=".env.previous-${timestamp}"
remote_candidate_uploaded=0

umask 077
printf '%s\n' "${OUVATON_SFTP_KNOWN_HOST}" > "${known_hosts_file}"

lftp_settings() {
  printf 'set cmd:fail-exit yes\n'
  printf 'set net:max-retries 2\n'
  printf 'set net:timeout 30\n'
  printf 'set ssl:verify-certificate yes\n'
  printf 'set sftp:connect-program %s\n' \
    "$(lftp_quote "ssh -a -x -o BatchMode=no -o StrictHostKeyChecking=yes -o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile=${known_hosts_file}")"
}

run_lftp_root() {
  local commands="$1"
  {
    lftp_settings
    printf 'open %s\n' "$(lftp_quote "sftp://${OUVATON_DEPLOY_HOST}:115")"
    printf 'user %s %s\n' \
      "$(lftp_quote "${OUVATON_DEPLOY_USERNAME}")" \
      "$(lftp_quote "${OUVATON_DEPLOY_PASSWORD}")"
    printf '%s\n' "${commands}"
    printf 'bye\n'
  } | lftp
}

run_lftp_shared() {
  local commands="$1"
  run_lftp_root "$(printf 'cd %s\n%s' "$(lftp_quote "${OUVATON_DEPLOY_REMOTE_FOLDER}/shared")" "${commands}")"
}

cleanup() {
  if [ "${remote_candidate_uploaded}" -eq 1 ]; then
    run_lftp_shared "rm .env.next" >/dev/null 2>&1 || true
  fi
  rm -f "${env_next}" "${local_backup}" "${known_hosts_file}"
}
trap cleanup EXIT

ensure_shared_layout() {
  local commands
  commands="$(
    printf 'mkdir -p -f %s\n' "$(lftp_quote "${OUVATON_DEPLOY_REMOTE_FOLDER}/shared")"
    for writable_dir in cache logs session uploads; do
      printf 'mkdir -p -f %s\n' "$(lftp_quote "${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/writable/${writable_dir}")"
    done
  )"
  run_lftp_root "${commands}"
}

remote_env_exists() {
  local shared_ls
  if ! shared_ls="$(run_lftp_shared "cls -1")"; then
    echo "::error::Unable to inspect Ouvaton shared/.env; refusing to assume first install."
    return 2
  fi
  echo "${shared_ls}" | grep -qE "^\.env\r?$"
}

remote_env_exists_or_die() {
  if remote_env_exists; then
    return 0
  fi

  local status=$?
  if [ "${status}" -eq 2 ]; then
    exit 1
  fi

  return 1
}

ensure_shared_layout

if [ "${MODE}" = "ensure-present" ] && remote_env_exists_or_die; then
  echo "Production shared/.env already exists on Ouvaton; deploy will not modify it."
  exit 0
fi

required_config_vars=(
  KERMESSE_OUVATON_ROOT
  KERMESSE_PUBLIC_BASE_URL
  KERMESSE_DATABASE_HOSTNAME
  KERMESSE_DATABASE_DATABASE
  KERMESSE_DATABASE_USERNAME
  KERMESSE_EMAIL_SMTP_HOST
  KERMESSE_EMAIL_SMTP_USER
  KERMESSE_EMAIL_FROM_EMAIL
  KERMESSE_DATABASE_PASSWORD
  KERMESSE_EMAIL_SMTP_PASS
  KERMESSE_TOKEN_SECRET
  OPS_MIGRATION_HMAC_SECRET
)

optional_config_vars=(
  KERMESSE_APP_TIMEZONE
  KERMESSE_EMAIL_FROM_NAME
  KERMESSE_EMAIL_SMTP_PORT
  KERMESSE_EMAIL_SMTP_CRYPTO
  KERMESSE_OPS_PROBE_ENABLED
)

require_group "${required_config_vars[@]}"

for var_name in "${required_config_vars[@]}" "${optional_config_vars[@]}"; do
  reject_multiline "${var_name}"
done

for var_name in \
  KERMESSE_OUVATON_ROOT \
  KERMESSE_PUBLIC_BASE_URL \
  KERMESSE_DATABASE_HOSTNAME \
  KERMESSE_DATABASE_DATABASE \
  KERMESSE_DATABASE_USERNAME \
  KERMESSE_EMAIL_SMTP_HOST \
  KERMESSE_EMAIL_SMTP_USER \
  KERMESSE_EMAIL_FROM_EMAIL \
  KERMESSE_APP_TIMEZONE \
  KERMESSE_EMAIL_FROM_NAME
do
  reject_dotenv_nested_reference "${var_name}"
done

smtp_port="${KERMESSE_EMAIL_SMTP_PORT:-587}"
if [[ -n "${KERMESSE_EMAIL_SMTP_PORT:-}" ]]; then
  if [[ ! "${KERMESSE_EMAIL_SMTP_PORT}" =~ ^[0-9]+$ ]] \
     || [ "${KERMESSE_EMAIL_SMTP_PORT}" -lt 1 ] \
     || [ "${KERMESSE_EMAIL_SMTP_PORT}" -gt 65535 ]; then
    echo "::error::KERMESSE_EMAIL_SMTP_PORT must be an integer between 1 and 65535"
    exit 1
  fi
fi

if [ "${#KERMESSE_TOKEN_SECRET}" -lt 32 ]; then
  echo "::error::KERMESSE_TOKEN_SECRET must be at least 32 characters"
  exit 1
fi

if [ "${#OPS_MIGRATION_HMAC_SECRET}" -lt 32 ]; then
  echo "::error::OPS_MIGRATION_HMAC_SECRET must be at least 32 characters"
  exit 1
fi

session_save_path="${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/writable/session"
deploy_remote_path="${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}"
app_timezone="${KERMESSE_APP_TIMEZONE:-Europe/Paris}"
email_from_name="${KERMESSE_EMAIL_FROM_NAME:-Kermesse}"
smtp_crypto="${KERMESSE_EMAIL_SMTP_CRYPTO:-tls}"

escape_dotenv_value() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  printf '%s' "${value}"
}

write_raw() {
  printf '%s = %s\n' "$1" "$2"
}

write_quoted() {
  printf '%s = "%s"\n' "$1" "$(escape_dotenv_value "$2")"
}

{
  printf '# Generated by GitHub Actions production env sync.\n'
  printf '# Source of truth : environment GitHub "production".\n'
  printf '# Do not commit, print, upload as artifact, or store this file.\n\n'

  write_raw CI_ENVIRONMENT production
  write_quoted app.baseURL "${KERMESSE_PUBLIC_BASE_URL}"
  write_quoted app.appTimezone "${app_timezone}"
  write_raw app.forceGlobalSecureRequests true
  write_raw app.CSPEnabled false
  write_raw logger.threshold 4
  write_quoted kermesse.publicBaseURL "${KERMESSE_PUBLIC_BASE_URL}"

  printf '\n'
  write_quoted database.default.hostname "${KERMESSE_DATABASE_HOSTNAME}"
  write_quoted database.default.database "${KERMESSE_DATABASE_DATABASE}"
  write_quoted database.default.username "${KERMESSE_DATABASE_USERNAME}"
  write_quoted database.default.password "${KERMESSE_DATABASE_PASSWORD}"
  write_raw database.default.DBDriver MySQLi
  write_raw database.default.DBPrefix ''
  write_raw database.default.port 3306
  write_raw database.default.charset utf8mb4
  write_raw database.default.DBCollat utf8mb4_general_ci

  printf '\n'
  write_quoted session.driver 'CodeIgniter\Session\Handlers\FileHandler'
  write_quoted session.savePath "${session_save_path}"
  write_quoted session.cookieName kermesse_session
  write_raw session.expiration 7200
  write_raw session.matchIP false
  write_raw session.timeToUpdate 300
  write_raw session.regenerateDestroy false
  write_raw cookie.prefix ''
  write_raw cookie.expires 0
  write_quoted cookie.path /
  write_raw cookie.domain ''
  write_raw cookie.secure true
  write_raw cookie.httponly true
  write_quoted cookie.samesite Lax

  printf '\n'
  write_raw email.protocol smtp
  write_quoted email.SMTPHost "${KERMESSE_EMAIL_SMTP_HOST}"
  write_quoted email.SMTPUser "${KERMESSE_EMAIL_SMTP_USER}"
  write_quoted email.SMTPPass "${KERMESSE_EMAIL_SMTP_PASS}"
  write_raw email.SMTPPort "${smtp_port}"
  write_quoted email.SMTPCrypto "${smtp_crypto}"
  write_quoted email.fromEmail "${KERMESSE_EMAIL_FROM_EMAIL}"
  write_quoted email.fromName "${email_from_name}"

  printf '\n'
  write_quoted kermesse.tokenSecret "${KERMESSE_TOKEN_SECRET}"
  write_raw kermesse.ownerValidationTokenTTL 86400
  write_raw kermesse.ownerLoginTokenTTL 900
  write_raw kermesse.volunteerManagementTokenTTL 1209600

  printf '\n'
  write_quoted kermesse.opsMigrationHmacSecret "${OPS_MIGRATION_HMAC_SECRET}"
  write_raw kermesse.opsMigrationAllowedTimestampSkew 300
  write_raw kermesse.opsMigrationNonceTTL 600
  write_raw kermesse.opsMigrationProductionOnly true
  write_quoted kermesse.opsMigrationLockName kermesse_ops_migration_lock

  printf '\n'
  write_quoted kermesse.opsActivateBasePath "${deploy_remote_path}"
  write_quoted kermesse.opsActivateLockName kermesse_ops_activate_lock
  write_raw kermesse.releasesRetention 3

  if [[ "${KERMESSE_OPS_PROBE_ENABLED:-}" == "true" ]]; then
    write_raw kermesse.opsProbeEnabled true
  else
    write_raw kermesse.opsProbeEnabled false
  fi

  printf '\n'
  write_quoted kermesse.deployTarget ouvaton
  write_raw kermesse.deployProtocol sftp
  write_quoted kermesse.deployHost "${OUVATON_DEPLOY_HOST}"
  write_quoted kermesse.deployRemotePath "${deploy_remote_path}"
  write_raw kermesse.deployPreserveEnv true
} > "${env_next}"

if [ ! -s "${env_next}" ]; then
  echo "::error::.env.next was not generated or is empty"
  exit 1
fi

run_lftp_shared "put $(lftp_quote "${env_next}") -o .env.next"
remote_candidate_uploaded=1
echo "Uploaded .env.next to Ouvaton shared/."

backup_exists=0
if remote_env_exists_or_die; then
  if [ "${MODE}" = "ensure-present" ]; then
    echo "::error::shared/.env appeared during first-install bootstrap; refusing to overwrite it."
    exit 1
  fi

  run_lftp_shared "get .env -o $(lftp_quote "${local_backup}")"
  run_lftp_shared "put $(lftp_quote "${local_backup}") -o ${remote_backup}"
  echo "Backed up current shared/.env as shared/${remote_backup}."
  backup_exists=1
else
  echo "No previous shared/.env found; treating as first installation — no backup created."
fi

old_env_moved=0
if [ "${backup_exists}" -eq 1 ]; then
  run_lftp_shared "mv .env ${remote_previous}"
  old_env_moved=1
fi

if run_lftp_shared "mv .env.next .env"; then
  echo "Activated shared/.env.next as shared/.env on Ouvaton."
else
  echo "::error::Failed to activate .env.next as shared/.env."

  if [ "${old_env_moved}" -eq 1 ]; then
    echo "Attempting rollback of shared/.env."
    if ! run_lftp_shared "mv ${remote_previous} .env"; then
      echo "Remote rollback failed — restoring from local backup."
      if ! run_lftp_shared "put $(lftp_quote "${local_backup}") -o .env"; then
        echo "::error::CRITIQUE : restauration automatique de shared/.env impossible en production."
        echo "::error::Récupération manuelle requise sur Ouvaton ${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/ : renommer ${remote_previous} (ou ${remote_backup}) en .env."
      fi
    fi
  fi

  exit 1
fi

remote_candidate_uploaded=0

if [ "${old_env_moved}" -eq 1 ]; then
  run_lftp_shared "rm ${remote_previous}"
fi

if [ "${backup_exists}" -eq 1 ]; then
  echo "Production shared/.env sync completed. Backup available: shared/${remote_backup}"
else
  echo "Production shared/.env sync completed (first installation — no backup)."
fi
