#!/usr/bin/env bash
# Orchestrateur de répétition de déploiement Kermesse.
# Enchaîne : packaging → transfert → activation → migration → vérification d'état.
# Paramétré exclusivement par variables d'env — aucun chemin de code local-only (FR-18).
#
# Usage :
#   bash scripts/deploy-rehearsal.sh
#
# Variables d'environnement (valeurs par défaut = profil rehearsal de docker-compose.yml) :
#   TARGET_HOST        Hôte SFTP de la cible de déploiement      (défaut : localhost)
#   TARGET_PORT        Port SFTP                                  (défaut : 2222)
#   TARGET_PROTO       Protocole de transfert (sftp|ftps|ftp)     (défaut : sftp)
#   TARGET_USER        Identifiant SFTP                           (défaut : deploy)
#   TARGET_PASS        Mot de passe SFTP                          (défaut : deploy_rehearsal)
#   BASE_URL           URL HTTP de l'application déployée         (défaut : http://localhost:8081)
#   OPS_HMAC_SECRET    Secret HMAC pour les webhooks ops          (défaut : local_dev_ops_secret_32_bytes_minimum)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Variables d'env avec valeurs par défaut pour la cible locale (profil rehearsal) ──
TARGET_HOST="${TARGET_HOST:-localhost}"
TARGET_PORT="${TARGET_PORT:-2222}"
TARGET_PROTO="${TARGET_PROTO:-sftp}"
TARGET_USER="${TARGET_USER:-deploy}"
TARGET_PASS="${TARGET_PASS:-deploy_rehearsal}"
BASE_URL="${BASE_URL:-http://localhost:8081}"
OPS_HMAC_SECRET="${OPS_HMAC_SECRET:-local_dev_ops_secret_32_bytes_minimum}"

# Étape en cours, utilisée par le trap ERR pour le message d'échec.
CURRENT_STEP="initialisation"

on_error() {
    echo "" >&2
    echo "REHEARSAL FAILED: ${CURRENT_STEP}" >&2
    exit 1
}
trap 'on_error' ERR

# ── Génération de signature HMAC-SHA256 ──────────────────────────────────────
# Payload (conforme à OpsAuthFilter) : timestamp\nnonce\nPOST\nroutePath\nsha256(body)
# Expose les variables globales SIGN_TS, SIGN_NONCE, SIGN_SIG.
hmac_sign() {
    local route="$1"
    local body="${2:-{}}"

    SIGN_TS="$(date +%s)"
    SIGN_NONCE="$(openssl rand -hex 16)"
    local body_hash
    body_hash="$(printf '%s' "${body}" | openssl dgst -sha256 | awk '{print $NF}')"
    local payload
    payload="$(printf '%s\n%s\nPOST\n%s\n%s' "${SIGN_TS}" "${SIGN_NONCE}" "${route}" "${body_hash}")"
    SIGN_SIG="$(printf '%s' "${payload}" | openssl dgst -sha256 -hmac "${OPS_HMAC_SECRET}" | awk '{print $NF}')"
}

echo "=== Répétition de déploiement Kermesse ==="
echo ""
echo "Cible   : ${TARGET_PROTO}://${TARGET_HOST}:${TARGET_PORT}"
echo "App     : ${BASE_URL}"
echo ""

# ── Étape 1/5 : Packaging ────────────────────────────────────────────────────
CURRENT_STEP="packaging"
echo "-- Étape 1/5 : Packaging de l'artefact"
bash "${SCRIPT_DIR}/package-deploy-artifact.sh"

# ── Étape 2/5 : Transfert ────────────────────────────────────────────────────
CURRENT_STEP="transfert"
echo ""
echo "-- Étape 2/5 : Transfert vers la cible"
TARGET_HOST="${TARGET_HOST}" \
TARGET_PORT="${TARGET_PORT}" \
TARGET_PROTO="${TARGET_PROTO}" \
TARGET_USER="${TARGET_USER}" \
TARGET_PASS="${TARGET_PASS}" \
    bash "${SCRIPT_DIR}/transfer-archive.sh"

# ── Étape 3/5 : Activation atomique ─────────────────────────────────────────
CURRENT_STEP="activation"
echo ""
echo "-- Étape 3/5 : Activation atomique"
hmac_sign "ops/activate"
curl -fsS -X POST "${BASE_URL%/}/ops/activate" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -d '{}'
echo ""

# ── Étape 4/5 : Migration base de données ────────────────────────────────────
CURRENT_STEP="migration"
echo ""
echo "-- Étape 4/5 : Migration de la base de données"
hmac_sign "ops/migrate"
curl -fsS -X POST "${BASE_URL%/}/ops/migrate" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -d '{}'
echo ""

# ── Étape 5/5 : Vérification de l'état des migrations ───────────────────────
CURRENT_STEP="verification-etat"
echo ""
echo "-- Étape 5/5 : Vérification de l'état des migrations"
hmac_sign "ops/migrate/status"
curl -fsS -X POST "${BASE_URL%/}/ops/migrate/status" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -d '{}'
echo ""

# ── Résumé ───────────────────────────────────────────────────────────────────
echo ""
echo "=========================================="
echo "REHEARSAL OK"
echo "=========================================="
echo "  Packaging            [OK]"
echo "  Transfert            [OK]"
echo "  Activation           [OK]"
echo "  Migration            [OK]"
echo "  Vérification état    [OK]"
echo ""
