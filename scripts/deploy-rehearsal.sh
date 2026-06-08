#!/usr/bin/env bash
# Orchestrateur de répétition de déploiement Kermesse.
# Enchaîne : packaging → transfert → activation → migration → vérification d'état.
# Paramétré exclusivement par variables d'env — aucun chemin de code local-only (FR-18).
#
# Client dockerisé (FR-21) : côté HÔTE, seul Docker est requis. Ce script relance
# automatiquement la vraie orchestration DANS le conteneur deploy-client (bash ≥ 5 ;
# lftp, curl, openssl, openssh-client, mysql et Composer embarqués), sur le réseau
# Docker. Tout le code en aval du bloc d'indirection s'exécute exclusivement dans le
# conteneur ; les mêmes scripts/*.sh que la CI y sont montés sans fork.
#
# Usage :
#   bash scripts/deploy-rehearsal.sh [--inject <cas>]
#   bash scripts/deploy-rehearsal.sh --reset
#
# Mode reset :
#   --reset   Purge staging/, releases/ et le pointeur current sur la cible locale,
#             puis tronque les tables techniques de test (schema_versions, ops_nonces).
#             Idempotent : aucune erreur si les dossiers/tables sont déjà vides ou absents.
#             Requiert que les conteneurs Docker du profil rehearsal soient démarrés.
#
# Modes d'injection (test uniquement) :
#   --inject truncated-transfer  Tronque l'archive sur la cible après transfert
#   --inject bad-checksum        Altère le .sha256 sur la cible après transfert
#   --inject failing-migration   Injecte un SQL invalide dans database/migrations_sql/
#
# Variables d'environnement (valeurs par défaut = service deploy-client de docker-compose.yml,
# résolues par NOM DE SERVICE sur le réseau Docker) :
#   TARGET_HOST        Hôte SFTP de la cible de déploiement      (défaut : deploy-target)
#   TARGET_PORT        Port SFTP                                  (défaut : 22)
#   TARGET_PROTO       Protocole de transfert (sftp|ftps|ftp)     (défaut : sftp)
#   TARGET_USER        Identifiant SFTP                           (défaut : deploy)
#   TARGET_PASS        Mot de passe SFTP                          (défaut : deploy_rehearsal)
#   BASE_URL           URL HTTP de l'application déployée         (défaut : http://deploy-web)
#   OPS_HMAC_SECRET    Secret HMAC pour les webhooks ops          (défaut : local_dev_ops_secret_32_bytes_minimum)
#   DB_RESET_HOST      Hôte MariaDB pour --reset                  (défaut : db)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ── Indirection hôte → conteneur (FR-21) ─────────────────────────────────────
# Côté HÔTE, la répétition ne doit dépendre QUE de Docker. On relance donc
# immédiatement la vraie orchestration dans le conteneur deploy-client. Tout le code
# en aval s'exécute exclusivement DANS le conteneur (KERMESSE_REHEARSAL_CONTAINER=1).
# NB : ce bloc s'exécute sur l'hôte (macOS bash 3.2) — le garder compatible bash 3.2.
if [[ "${KERMESSE_REHEARSAL_CONTAINER:-}" != "1" ]]; then
    if ! hash docker >/dev/null 2>&1; then
        echo "ERREUR : Docker est requis côté hôte pour la répétition." >&2
        exit 1
    fi
    # On ne propage au conteneur QUE les surcharges réellement présentes dans
    # l'environnement de l'hôte ; sinon les valeurs par défaut du service deploy-client
    # (docker-compose.yml) s'appliquent. L'idiome ${arr[@]+...} évite l'erreur
    # « unbound variable » de bash 3.2 quand le tableau est vide (set -u).
    REEXEC_ENV=()
    for _v in TARGET_HOST TARGET_PORT TARGET_PROTO TARGET_USER TARGET_PASS \
              TARGET_SFTP_SKIP_HOST_CHECK REMOTE_STAGING BASE_URL OPS_HMAC_SECRET \
              DB_RESET_HOST DB_RESET_USER DB_RESET_PASS DB_RESET_NAME; do
        if [[ -n "${!_v:-}" ]]; then
            REEXEC_ENV+=(-e "${_v}")
        fi
    done
    exec docker compose --profile rehearsal run --rm -T \
        ${REEXEC_ENV[@]+"${REEXEC_ENV[@]}"} \
        deploy-client bash scripts/deploy-rehearsal.sh "$@"
fi

# ── Analyse des arguments ─────────────────────────────────────────────────────
INJECT_MODE=""
RESET_MODE=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --inject)
            if [[ $# -lt 2 ]]; then
                echo "ERREUR : --inject requiert un argument (truncated-transfer|bad-checksum|failing-migration)" >&2
                exit 1
            fi
            if [[ -n "${INJECT_MODE}" ]]; then
                echo "ERREUR : argument --inject passé plusieurs fois" >&2
                exit 1
            fi
            INJECT_MODE="$2"
            shift 2
            ;;
        --reset)
            RESET_MODE=true
            shift
            ;;
        *)
            echo "ERREUR : argument inconnu : $1" >&2
            exit 1
            ;;
    esac
done

if [[ "${RESET_MODE}" == true && -n "${INJECT_MODE}" ]]; then
    echo "ERREUR : --reset et --inject sont mutuellement exclusifs" >&2
    exit 1
fi

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
# Exécuté DANS le conteneur deploy-client : tous ces outils y sont embarqués.
# Pour --reset : seul le client mysql/mariadb est nécessaire (purge fichiers = volume monté).
if [[ "${RESET_MODE}" == true ]]; then
    hash mysql || { echo "ERREUR : dépendance système manquante (client mysql/mariadb)" >&2; exit 1; }
else
    hash curl awk openssl lftp || { echo "ERREUR : dépendances système manquantes (curl, awk, openssl, lftp)" >&2; exit 1; }
    if [[ ! -x "${SCRIPT_DIR}/package-deploy-artifact.sh" || ! -x "${SCRIPT_DIR}/transfer-archive.sh" ]]; then
        echo "ERREUR : sous-scripts introuvables ou non exécutables dans ${SCRIPT_DIR}" >&2
        exit 1
    fi
fi

# ── Variables d'env avec valeurs par défaut pour la cible locale (profil rehearsal) ──
# Résolues par NOM DE SERVICE sur le réseau Docker : depuis deploy-client, la cible SFTP
# est deploy-target:22 et l'app deploy-web:80 — plus de 127.0.0.1/::1 ni de ports publiés.
TARGET_HOST="${TARGET_HOST:-deploy-target}"
TARGET_PORT="${TARGET_PORT:-22}"
TARGET_PROTO="${TARGET_PROTO:-sftp}"
TARGET_USER="${TARGET_USER:-deploy}"
TARGET_PASS="${TARGET_PASS:-deploy_rehearsal}"
# Dossier de staging relatif à la racine SFTP. En rehearsal, la base de déploiement est
# montée directement à la racine SFTP → staging/ (et non kermesse/staging/ comme sur Ouvaton).
# Le transfert ET les injections post-transfert visent ce même dossier (cohérence).
REMOTE_STAGING="${REMOTE_STAGING:-staging}"
BASE_URL="${BASE_URL:-http://deploy-web}"
OPS_HMAC_SECRET="${OPS_HMAC_SECRET:-local_dev_ops_secret_32_bytes_minimum}"
# Identifiants root MariaDB de la cible locale (profil rehearsal uniquement, service db).
# Surchargeables par l'environnement ; ne jamais coder en dur des identifiants de production.
DB_RESET_HOST="${DB_RESET_HOST:-db}"
DB_RESET_USER="${DB_RESET_USER:-root}"
DB_RESET_PASS="${DB_RESET_PASS:-root_password}"
DB_RESET_NAME="${DB_RESET_NAME:-kermesse}"
# Contournement de la vérification de la clé SSH hôte pour la cible locale (rehearsal).
# Le conteneur SFTP regénère sa clé hôte à chaque démarrage : ce bypass est réservé
# au profil rehearsal et NE DOIT PAS être activé sur une cible de production.
TARGET_SFTP_SKIP_HOST_CHECK="${TARGET_SFTP_SKIP_HOST_CHECK:-true}"

# ── Mode reset : réinitialisation de la cible locale ─────────────────────────
# Idempotent : pas d'erreur si les dossiers ou tables sont déjà vides/absents.
# Exécuté DANS deploy-client : la purge fichiers opère sur le volume partagé monté
# (/srv/deploy-data) et le DROP des tables passe par le client mysql vers le service db.
if [[ "${RESET_MODE}" == true ]]; then
    echo "=== Remise à zéro de la cible locale (rehearsal) ==="
    echo ""

    # Le volume deploy-target-data est monté ici ; son absence = stack rehearsal non démarrée.
    DEPLOY_DATA="${DEPLOY_DATA:-/srv/deploy-data}"
    if [[ ! -d "${DEPLOY_DATA}" ]]; then
        echo "ERREUR : volume de déploiement absent (${DEPLOY_DATA})." >&2
        echo "Lancez d'abord : docker compose --profile rehearsal up -d" >&2
        exit 1
    fi

    echo "-- Étape 1/2 : Purge de staging/, releases/ et des pointeurs current"
    # deploy-client tourne en root, donc les chown ci-dessous sont autorisés. rm -rf + mkdir -p
    # garantit l'idempotence (pas d'erreur si déjà vide/absent). On pose le minimum de droits
    # nécessaire plutôt qu'un chmod 777 trop permissif (www-data = uid 33, identique à deploy-web
    # car même image de base) :
    #   - staging/  : déposé par le user SFTP (uid 1000) ET nettoyé par www-data (uid 33,
    #                 qui supprime l'archive après extraction, cf. ReleaseActivationService).
    #                 → propriétaire 1000, groupe www-data, droit d'écriture du groupe (775).
    #   - releases/ : écrit uniquement par www-data à l'activation → propriétaire www-data (755).
    rm -rf "${DEPLOY_DATA}/staging"  && mkdir -p "${DEPLOY_DATA}/staging"  && chown 1000:www-data "${DEPLOY_DATA}/staging" && chmod 775 "${DEPLOY_DATA}/staging"
    rm -rf "${DEPLOY_DATA}/releases" && mkdir -p "${DEPLOY_DATA}/releases" && chown www-data:www-data "${DEPLOY_DATA}/releases"
    rm -f  "${DEPLOY_DATA}/current"
    rm -f  "${DEPLOY_DATA}/CURRENT_RELEASE"
    echo "   staging/     → purgé"
    echo "   releases/    → purgé"
    echo "   current      → supprimé"
    echo "   CURRENT_RELEASE → supprimé"
    echo ""

    echo "-- Étape 2/2 : Réinitialisation des tables techniques de test"
    # DROP TABLE IF EXISTS est idempotent : aucune erreur si les tables n'existent pas.
    # MigrationRunnerService recrée schema_versions et ops_nonces au prochain appel /ops/migrate.
    # MYSQL_PWD évite l'avertissement « mot de passe sur la ligne de commande » sans masquer
    # les erreurs réelles (stderr reste visible → fail-fast préservé).
    # --skip-ssl : le client MariaDB (≥ 11) exige TLS par défaut en TCP, que la MariaDB locale
    # n'expose pas. Connexion non chiffrée acceptable ici : réseau Docker interne, rehearsal only.
    MYSQL_PWD="${DB_RESET_PASS}" mysql --skip-ssl \
        -h "${DB_RESET_HOST}" -u "${DB_RESET_USER}" "${DB_RESET_NAME}" \
        -e "DROP TABLE IF EXISTS \`schema_versions\`, \`ops_nonces\`;"
    echo "   schema_versions → supprimé"
    echo "   ops_nonces      → supprimé"
    echo ""

    echo "=========================================="
    echo "RESET OK"
    echo "=========================================="
    echo "  Staging/releases purgés [OK]"
    echo "  Tables techniques       [OK]"
    echo ""
    echo "La cible est prête pour une nouvelle répétition."
    exit 0
fi

# Étape en cours, utilisée par le trap ERR pour le message d'échec.
CURRENT_STEP="initialisation"

# Fichier SQL d'injection à supprimer en fin d'exécution (failing-migration).
INJECT_SQL_FILE=""
INJECT_TMP_TRUNCATED=""
INJECT_TMP_CHECKSUM=""

# ── Gestion des traps ─────────────────────────────────────────────────────────
# cleanup s'exécute à chaque sortie (normale ou sur erreur) via EXIT.
# on_error s'exécute uniquement sur ERR et affiche l'étape courante.
cleanup() {
    if [[ -n "${INJECT_SQL_FILE}" && -f "${INJECT_SQL_FILE}" ]]; then
        rm -f "${INJECT_SQL_FILE}"
        echo "[INJECT] Fichier SQL d'injection supprimé : $(basename "${INJECT_SQL_FILE}")" >&2
    fi
    # if/fi (et non `[[ ... ]] && cmd`) pour ne pas renvoyer un code non-zéro depuis cleanup
    # quand la condition est fausse — ce qui déclencherait le trap ERR.
    if [[ -n "${INJECT_TMP_TRUNCATED}" && -f "${INJECT_TMP_TRUNCATED}" ]]; then
        rm -f "${INJECT_TMP_TRUNCATED}"
    fi
    if [[ -n "${INJECT_TMP_CHECKSUM}" && -f "${INJECT_TMP_CHECKSUM}" ]]; then
        rm -f "${INJECT_TMP_CHECKSUM}"
    fi
    # Restaure l'ownership hôte des artefacts créés en root dans le bind mount /workspace.
    # Le conteneur tourne en root (requis pour les chown de --reset et les volumes nommés) ;
    # sans ça, sur Linux natif, build/ appartient à root et exige sudo pour le nettoyer.
    # --reference se calque sur le propriétaire du dépôt monté (= l'utilisateur hôte) ;
    # sur macOS/OrbStack l'ownership est déjà mappé → no-op. fail-soft : ne jamais masquer
    # le code de sortie réel de la répétition (|| true, comme un nettoyage best-effort).
    if [[ -d "${PROJECT_ROOT}/build" ]]; then
        chown -R --reference="${PROJECT_ROOT}" "${PROJECT_ROOT}/build" 2>/dev/null || true
    fi
}

on_error() {
    echo "" >&2
    echo "REHEARSAL FAILED: ${CURRENT_STEP}" >&2
    exit 1
}
trap 'on_error' ERR
trap 'cleanup' EXIT INT TERM

# ── Génération de signature HMAC-SHA256 ──────────────────────────────────────
# Payload (conforme à OpsAuthFilter) : timestamp\nnonce\nPOST\nroutePath\nsha256(body)
# Expose les variables globales SIGN_TS, SIGN_NONCE, SIGN_SIG.
hmac_sign() {
    local route="$1"
    # NE PAS écrire `${2:-{}}` : bash (3.2 ET 5.x) referme l'expansion sur le 1er `}` de
    # la valeur par défaut, puis ajoute un `}` littéral. Quand $2 est défini (cas activation),
    # un `}` parasite est concaténé au body → le sha256 signé diffère du body réellement
    # envoyé par curl → signature rejetée (ops_unauthorized). Variable intermédiaire = sûr.
    local _body_default='{}'
    local body="${2:-${_body_default}}"

    SIGN_TS="$(date +%s)"
    SIGN_NONCE="$(openssl rand -hex 16)"
    local body_hash
    body_hash="$(printf '%s' "${body}" | openssl dgst -sha256 | awk '{print $NF}')"
    local payload
    payload="$(printf '%s\n%s\nPOST\n%s\n%s' "${SIGN_TS}" "${SIGN_NONCE}" "${route}" "${body_hash}")"
    SIGN_SIG="$(printf '%s' "${payload}" | openssl dgst -sha256 -hmac "${OPS_HMAC_SECRET}" | awk '{print $NF}')"
}

# ── Transfert d'un fichier local vers le staging de la cible ─────────────────
# Utilisé uniquement par les modes d'injection post-transfert : doit viser le MÊME
# dossier que le transfert réel (${REMOTE_STAGING}) pour corrompre l'archive en place.
inject_remote_file() {
    local local_file="$1"
    local remote_name="$2"
    local remote_staging="${REMOTE_STAGING}"

    local escaped_user="${TARGET_USER//\'/\\\'}"
    local escaped_pass="${TARGET_PASS//\'/\\\'}"
    local proto_settings=""
    case "${TARGET_PROTO}" in
        sftp)
            if [[ -n "${TARGET_KEY:-}" ]]; then
                local escaped_key="${TARGET_KEY//\'/\\\'}"
                proto_settings="set sftp:connect-program \"ssh -a -x -i '${escaped_key}'\";"
            fi
            ;;
        ftps)
            proto_settings="set ftp:ssl-force true; set ftp:ssl-protect-data true;"
            ;;
    esac

    lftp <<EOF
set cmd:fail-exit true;
${proto_settings}
open -u '${escaped_user}','${escaped_pass}' -p ${TARGET_PORT} ${TARGET_PROTO}://${TARGET_HOST};
put "${local_file}" -o "${remote_staging}/${remote_name}";
bye
EOF
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
    mkdir -p "$(dirname "${INJECT_SQL_FILE}")"
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
# REMOTE_STAGING (résolu plus haut à « staging » pour le profil rehearsal) écrase le
# défaut production « kermesse/staging » de transfer-archive.sh.
TARGET_HOST="${TARGET_HOST}" \
TARGET_PORT="${TARGET_PORT}" \
TARGET_PROTO="${TARGET_PROTO}" \
TARGET_USER="${TARGET_USER}" \
TARGET_PASS="${TARGET_PASS}" \
TARGET_SFTP_SKIP_HOST_CHECK="${TARGET_SFTP_SKIP_HOST_CHECK}" \
REMOTE_STAGING="${REMOTE_STAGING}" \
    bash "${SCRIPT_DIR}/transfer-archive.sh"

# ── Injection post-transfert : corruption de l'archive ou du checksum ────────
if [[ "${INJECT_MODE}" == "truncated-transfer" ]]; then
    CURRENT_STEP="injection-truncated-transfer"
    echo ""
    echo "[INJECT] Troncature de l'archive sur la cible (1 024 octets)..."
    INJECT_TMP_TRUNCATED="$(mktemp)"
    dd if=/dev/zero of="${INJECT_TMP_TRUNCATED}" bs=1024 count=1 2>/dev/null
    inject_remote_file "${INJECT_TMP_TRUNCATED}" "kermesse-deploy.tar.gz"
    echo "[INJECT] Archive tronquée sur la cible."

elif [[ "${INJECT_MODE}" == "bad-checksum" ]]; then
    CURRENT_STEP="injection-bad-checksum"
    echo ""
    echo "[INJECT] Altération du checksum sur la cible..."
    INJECT_TMP_CHECKSUM="$(mktemp)"
    printf '0000000000000000000000000000000000000000000000000000000000000000  kermesse-deploy.tar.gz\n' \
        > "${INJECT_TMP_CHECKSUM}"
    inject_remote_file "${INJECT_TMP_CHECKSUM}" "kermesse-deploy.tar.gz.sha256"
    echo "[INJECT] Checksum altéré sur la cible."
fi

# ── Étape 3/5 : Activation atomique ─────────────────────────────────────────
CURRENT_STEP="activation"
echo ""
echo "-- Étape 3/5 : Activation atomique"
ACTIVATE_BODY='{"archive":"kermesse-deploy.tar.gz"}'
hmac_sign "ops/activate" "${ACTIVATE_BODY}"
curl --max-time 30 --fail-with-body -sS -X POST "${BASE_URL%/}/ops/activate" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -d "${ACTIVATE_BODY}"
echo ""

# ── Étape 4/5 : Migration base de données ────────────────────────────────────
CURRENT_STEP="migration"
echo ""
echo "-- Étape 4/5 : Migration de la base de données"
hmac_sign "ops/migrate"
curl --max-time 30 --fail-with-body -sS -X POST "${BASE_URL%/}/ops/migrate" \
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
curl --max-time 30 --fail-with-body -sS -X POST "${BASE_URL%/}/ops/migrate/status" \
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
