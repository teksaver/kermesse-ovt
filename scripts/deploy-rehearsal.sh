#!/usr/bin/env bash
# Orchestrateur de répétition de déploiement Kermesse.
# Enchaîne : packaging → transfert → activation → migration → vérification d'état.
# Paramétré exclusivement par variables d'env — aucun chemin de code local-only (FR-18).
#
# Usage :
#   bash scripts/deploy-rehearsal.sh [--inject <cas>]
#
# Modes d'injection (test uniquement) :
#   --inject truncated-transfer  Tronque l'archive sur la cible après transfert
#   --inject bad-checksum        Altère le .sha256 sur la cible après transfert
#   --inject failing-migration   Injecte un SQL invalide dans database/migrations_sql/
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
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ── Analyse des arguments ─────────────────────────────────────────────────────
INJECT_MODE=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --inject)
            if [[ $# -lt 2 ]]; then
                echo "ERREUR : --inject requiert un argument (truncated-transfer|bad-checksum|failing-migration)" >&2
                exit 1
            fi
            INJECT_MODE="$2"
            shift 2
            ;;
        *)
            echo "ERREUR : argument inconnu : $1" >&2
            exit 1
            ;;
    esac
done

if [[ -n "${INJECT_MODE}" ]]; then
    case "${INJECT_MODE}" in
        truncated-transfer|bad-checksum|failing-migration) ;;
        *)
            echo "ERREUR : mode d'injection inconnu : ${INJECT_MODE}" >&2
            echo "Modes disponibles : truncated-transfer, bad-checksum, failing-migration" >&2
            exit 1
            ;;
    esac
fi

# ── Vérifications de base (Fail-fast) ────────────────────────────────────────
hash curl awk openssl || { echo "ERREUR : dépendances système manquantes (curl, awk, openssl)" >&2; exit 1; }
if [[ ! -x "${SCRIPT_DIR}/package-deploy-artifact.sh" || ! -x "${SCRIPT_DIR}/transfer-archive.sh" ]]; then
    echo "ERREUR : sous-scripts introuvables ou non exécutables dans ${SCRIPT_DIR}" >&2
    exit 1
fi

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

# Fichier SQL d'injection à supprimer en fin d'exécution (failing-migration).
INJECT_SQL_FILE=""

# ── Gestion des traps ─────────────────────────────────────────────────────────
# cleanup s'exécute à chaque sortie (normale ou sur erreur) via EXIT.
# on_error s'exécute uniquement sur ERR et affiche l'étape courante.
cleanup() {
    if [[ -n "${INJECT_SQL_FILE}" && -f "${INJECT_SQL_FILE}" ]]; then
        rm -f "${INJECT_SQL_FILE}"
        echo "[INJECT] Fichier SQL d'injection supprimé : $(basename "${INJECT_SQL_FILE}")" >&2
    fi
}

on_error() {
    echo "" >&2
    echo "REHEARSAL FAILED: ${CURRENT_STEP}" >&2
    exit 1
}
trap 'on_error' ERR
trap 'cleanup' EXIT

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

# ── Transfert d'un fichier local vers kermesse/staging/ sur la cible ─────────
# Utilisé uniquement par les modes d'injection post-transfert.
inject_remote_file() {
    local local_file="$1"
    local remote_name="$2"
    local remote_staging="kermesse/staging"

    local escaped_pass="${TARGET_PASS//\'/\\\'}"
    local proto_settings=""
    case "${TARGET_PROTO}" in
        sftp)
            if [[ -n "${TARGET_KEY:-}" ]]; then
                proto_settings="set sftp:connect-program \"ssh -a -x -i '${TARGET_KEY}'\";"
            fi
            ;;
        ftps)
            proto_settings="set ftp:ssl-force true; set ftp:ssl-protect-data true;"
            ;;
    esac

    lftp -f <(
        echo "set cmd:fail-exit true;"
        [[ -n "${proto_settings}" ]] && echo "${proto_settings}"
        echo "open -u '${TARGET_USER}','${escaped_pass}' -p ${TARGET_PORT} ${TARGET_PROTO}://${TARGET_HOST};"
        echo "put \"${local_file}\" -o \"${remote_staging}/${remote_name}\";"
        echo "bye"
    )
}

echo "=== Répétition de déploiement Kermesse ==="
echo ""
if [[ -n "${INJECT_MODE}" ]]; then
    echo "[INJECT] Mode d'injection : ${INJECT_MODE}"
    echo ""
fi
echo "Cible   : ${TARGET_PROTO}://${TARGET_HOST}:${TARGET_PORT}"
echo "App     : ${BASE_URL}"
echo ""

# ── Injection failing-migration : fichier SQL invalide avant packaging ────────
# Le fichier est embarqué dans l'archive et supprimé proprement en fin d'exécution
# (trap EXIT → cleanup), qu'il y ait erreur ou non.
if [[ "${INJECT_MODE}" == "failing-migration" ]]; then
    CURRENT_STEP="injection-failing-migration"
    echo "[INJECT] Injection d'un fichier SQL invalide dans database/migrations_sql/"
    INJECT_SQL_FILE="${PROJECT_ROOT}/database/migrations_sql/99991231235959_inject_test_failure.sql"
    printf 'SELECT BOOM;\n' > "${INJECT_SQL_FILE}"
    echo "[INJECT] Fichier injecté : $(basename "${INJECT_SQL_FILE}")"
    echo ""
fi

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

# ── Injection post-transfert : corruption de l'archive ou du checksum ────────
if [[ "${INJECT_MODE}" == "truncated-transfer" ]]; then
    CURRENT_STEP="injection-truncated-transfer"
    echo ""
    echo "[INJECT] Troncature de l'archive sur la cible (1 024 octets)..."
    _tmp_truncated="$(mktemp)"
    dd if=/dev/zero of="${_tmp_truncated}" bs=1024 count=1 2>/dev/null
    inject_remote_file "${_tmp_truncated}" "kermesse-deploy.tar.gz"
    rm -f "${_tmp_truncated}"
    echo "[INJECT] Archive tronquée sur la cible."

elif [[ "${INJECT_MODE}" == "bad-checksum" ]]; then
    CURRENT_STEP="injection-bad-checksum"
    echo ""
    echo "[INJECT] Altération du checksum sur la cible..."
    _tmp_checksum="$(mktemp)"
    printf '0000000000000000000000000000000000000000000000000000000000000000  kermesse-deploy.tar.gz\n' \
        > "${_tmp_checksum}"
    inject_remote_file "${_tmp_checksum}" "kermesse-deploy.tar.gz.sha256"
    rm -f "${_tmp_checksum}"
    echo "[INJECT] Checksum altéré sur la cible."
fi

# ── Étape 3/5 : Activation atomique ─────────────────────────────────────────
CURRENT_STEP="activation"
echo ""
echo "-- Étape 3/5 : Activation atomique"
hmac_sign "ops/activate"
curl --max-time 30 --fail-with-body -fsS -X POST "${BASE_URL%/}/ops/activate" \
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
curl --max-time 30 --fail-with-body -fsS -X POST "${BASE_URL%/}/ops/migrate" \
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
curl --max-time 30 --fail-with-body -fsS -X POST "${BASE_URL%/}/ops/migrate/status" \
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
