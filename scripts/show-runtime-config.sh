#!/usr/bin/env bash
# Display the effective PHP/MariaDB runtime configuration of the local container
# by calling the /ops/probe endpoint, which reads Apache SAPI values (not CLI).
#
# Usage:
#   scripts/show-runtime-config.sh [--port 8080]
#
# The HMAC secret is taken from OPS_HMAC_SECRET env var when set, otherwise
# the default local dev value hard-coded in docker-compose.yml is used.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ---- configuration ----
PORT="${KERMESSE_HTTP_PORT:-8080}"
BASE_URL="http://127.0.0.1:${PORT}"
ROUTE="ops/probe"

# Default matches docker-compose.yml kermesse.opsMigrationHmacSecret
SECRET="${OPS_HMAC_SECRET:-local_dev_ops_secret_32_bytes_minimum}"

# ---- parse args ----
while [[ $# -gt 0 ]]; do
    case "$1" in
        --port) PORT="$2"; BASE_URL="http://127.0.0.1:${PORT}"; shift 2 ;;
        *) echo "Usage: $0 [--port PORT]" >&2; exit 1 ;;
    esac
done

# ---- check dependencies ----
for cmd in curl openssl date; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERREUR : commande requise introuvable : ${cmd}" >&2
        exit 1
    fi
done

# ---- forge HMAC signature ----
TIMESTAMP="$(date +%s)"
NONCE="$(openssl rand -hex 16)"
BODY=""
BODY_HASH="$(printf '%s' "${BODY}" | openssl dgst -sha256 -hex | awk '{print $NF}')"

PAYLOAD="${TIMESTAMP}
${NONCE}
POST
${ROUTE}
${BODY_HASH}"

SIGNATURE="$(printf '%s' "${PAYLOAD}" | openssl dgst -sha256 -hmac "${SECRET}" | awk '{print $NF}')"

# ---- call /ops/probe ----
echo "=== Configuration runtime du conteneur local (${BASE_URL}/${ROUTE}) ==="
echo ""

BODY_FILE="$(mktemp)"
trap 'rm -f "${BODY_FILE}"' EXIT

HTTP_STATUS="$(curl -s -o "${BODY_FILE}" -w "%{http_code}" \
    -X POST \
    -H "X-Kermesse-Timestamp: ${TIMESTAMP}" \
    -H "X-Kermesse-Nonce: ${NONCE}" \
    -H "X-Kermesse-Signature: ${SIGNATURE}" \
    -H "Content-Type: application/json" \
    --data "" \
    "${BASE_URL}/${ROUTE}")"

RESPONSE_BODY="$(cat "${BODY_FILE}")"

if [[ "${HTTP_STATUS}" != "200" ]]; then
    echo "ERREUR : La sonde a répondu HTTP ${HTTP_STATUS}" >&2
    echo "Réponse : ${RESPONSE_BODY}" >&2
    echo "" >&2
    echo "Vérifications :" >&2
    echo "  1. Les conteneurs sont-ils démarrés ? (docker compose up -d)" >&2
    echo "  2. kermesse.opsProbeEnabled=true dans docker-compose.yml ?" >&2
    echo "  3. kermesse.opsMigrationProductionOnly=false dans docker-compose.yml ?" >&2
    exit 1
fi

# ---- format output ----
# Pretty-print JSON if jq is available, otherwise raw output
if command -v jq >/dev/null 2>&1; then
    printf '%s' "${RESPONSE_BODY}" | jq .
else
    printf '%s\n' "${RESPONSE_BODY}"
fi
