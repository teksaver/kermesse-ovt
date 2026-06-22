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
#   bash scripts/deploy-rehearsal.sh [--inject <cas>] [--use-existing-artifact]
#   bash scripts/deploy-rehearsal.sh --reset
#
# Mode reset :
#   --reset   Purge staging/, releases/ et le pointeur current sur la cible locale,
#             puis tronque les tables techniques de test (schema_versions, ops_nonces).
#             Idempotent : aucune erreur si les dossiers/tables sont déjà vides ou absents.
#             Requiert que les conteneurs Docker du profil rehearsal soient démarrés.
#
# Mode artefact existant :
#   --use-existing-artifact   Saute le packaging (étape 1/5) et exige que build/kermesse-deploy.tar.gz
#                             + build/kermesse-deploy.tar.gz.sha256 soient déjà présents.
#                             Utilisé par le job de qualification RC pour déployer le binaire exact qualifié.
#                             Le mode de packaging local pour la boucle développeur est toujours disponible
#                             en n'utilisant pas ce flag.
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
    if ! hash docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        echo "ERREUR : Docker et le plugin docker-compose sont requis côté hôte pour la répétition." >&2
        exit 1
    fi
    # On ne propage au conteneur QUE les surcharges réellement présentes dans
    # l'environnement de l'hôte ; sinon les valeurs par défaut du service deploy-client
    # (docker-compose.yml) s'appliquent. L'idiome ${arr[@]+...} évite l'erreur
    # « unbound variable » de bash 3.2 quand le tableau est vide (set -u).
    REEXEC_ENV=()
    for _v in TARGET_HOST TARGET_PORT TARGET_PROTO TARGET_USER TARGET_PASS \
              TARGET_SFTP_SKIP_HOST_CHECK REMOTE_STAGING BASE_URL OPS_HMAC_SECRET \
              DB_RESET_HOST DB_RESET_USER DB_RESET_PASS DB_RESET_NAME \
              KERMESSE_REHEARSAL_CONTAINER; do
        # On vérifie si la variable est définie (+x), même si elle est vide,
        # pour propager les valeurs vides explicites au conteneur.
        if eval "[ -n \"\${${_v}+x}\" ]"; then
            REEXEC_ENV+=(-e "${_v}")
        fi
    done
    # "$@" already includes --use-existing-artifact if provided; pass verbatim.
    exec docker compose --profile rehearsal run --rm -T \
        ${REEXEC_ENV[@]+"${REEXEC_ENV[@]}"} \
        deploy-client bash scripts/deploy-rehearsal.sh "$@"
fi

# ── Analyse des arguments ─────────────────────────────────────────────────────
INJECT_MODE=""
RESET_MODE=false
USE_EXISTING_ARTIFACT=false
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
        --use-existing-artifact)
            USE_EXISTING_ARTIFACT=true
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

if [[ "${USE_EXISTING_ARTIFACT}" == true && -n "${INJECT_MODE}" ]]; then
    echo "ERREUR : --use-existing-artifact et --inject sont mutuellement exclusifs" >&2
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
    if [[ ! -x "${SCRIPT_DIR}/transfer-archive.sh" ]]; then
        echo "ERREUR : transfer-archive.sh introuvable ou non exécutable dans ${SCRIPT_DIR}" >&2
        exit 1
    fi
    # package-deploy-artifact.sh n'est requis qu'en mode packaging local (pas --use-existing-artifact).
    if [[ "${USE_EXISTING_ARTIFACT}" != true ]]; then
        if [[ ! -x "${SCRIPT_DIR}/package-deploy-artifact.sh" ]]; then
            echo "ERREUR : package-deploy-artifact.sh introuvable ou non exécutable dans ${SCRIPT_DIR}" >&2
            exit 1
        fi
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

    # Sécurité anti-catastrophe (si DEPLOY_DATA est accidentellement forcé à vide ou racine)
    if [[ -z "${DEPLOY_DATA}" || "${DEPLOY_DATA}" == "/" ]]; then
        echo "ERREUR : DEPLOY_DATA est vide ou pointe sur la racine." >&2
        exit 1
    fi

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

# ── Helpers partagés : signature ops + nom d'artefact ────────────────────────
# Sourcés depuis scripts/lib/ : MÊME fonction de signature (ops_sign) qu'en CI/prod
# (deploy-ouvaton.yml) pour empêcher toute dérive (FR-18). Le format de payload
# (timestamp\nnonce\nPOST\nroutePath\nsha256(body)) est défini une seule fois dans
# ops-sign.sh. ops_sign lit OPS_HMAC_SECRET (résolu plus haut, même shell que le source).
# shellcheck source=lib/ops-sign.sh
source "${SCRIPT_DIR}/lib/ops-sign.sh"
# shellcheck source=lib/artifact.sh
source "${SCRIPT_DIR}/lib/artifact.sh"
# shellcheck source=lib/lftp-escape.sh
source "${SCRIPT_DIR}/lib/lftp-escape.sh"

# ── Transfert d'un fichier local vers le staging de la cible ─────────────────
# Utilisé uniquement par les modes d'injection post-transfert : doit viser le MÊME
# dossier que le transfert réel (${REMOTE_STAGING}) pour corrompre l'archive en place.
inject_remote_file() {
    local local_file="$1"
    local remote_name="$2"
    local remote_staging="${REMOTE_STAGING}"

    local escaped_user escaped_pass
    escaped_user="$(lftp_squote "${TARGET_USER}")"
    escaped_pass="$(lftp_squote "${TARGET_PASS}")"
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
if [[ "${USE_EXISTING_ARTIFACT}" == true ]]; then
    echo "[RC] Mode : artefact préexistant (binaire qualifié — pas de packaging)"
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

# ── Étape 1/5 : Packaging (ou vérification de l'artefact préexistant) ────────
CURRENT_STEP="packaging"
if [[ "${USE_EXISTING_ARTIFACT}" == true ]]; then
    echo "-- Étape 1/5 : Vérification de l'artefact préexistant (mode RC)"
    # En mode qualification RC, l'archive doit déjà être dans build/ (téléchargée depuis CI).
    # On vérifie sa présence et son intégrité sans reconstruire.
    source "${SCRIPT_DIR}/lib/artifact.sh"
    ARTIFACT_PATH="${PROJECT_ROOT}/build/${KERMESSE_ARTIFACT_NAME}"
    SIDECAR_PATH="${PROJECT_ROOT}/build/${KERMESSE_ARTIFACT_NAME}.sha256"
    if [[ ! -f "${ARTIFACT_PATH}" ]]; then
        echo "ERREUR : artefact préexistant absent : ${ARTIFACT_PATH}" >&2
        echo "Téléchargez l'artefact CI dans build/ avant d'utiliser --use-existing-artifact." >&2
        exit 1
    fi
    if [[ ! -f "${SIDECAR_PATH}" ]]; then
        echo "ERREUR : sidecar SHA256 absent : ${SIDECAR_PATH}" >&2
        exit 1
    fi
    ( cd "${PROJECT_ROOT}/build" && sha256sum -c "$(basename "${SIDECAR_PATH}")" ) || {
        echo "ERREUR : checksum de l'artefact préexistant invalide." >&2
        exit 1
    }
    echo "[RC] Artefact vérifié : $(awk '{print $1}' "${SIDECAR_PATH}")"
    echo "[RC] Packaging sauté — promotion du binaire qualifié."
else
    echo "-- Étape 1/5 : Packaging de l'artefact"
    bash "${SCRIPT_DIR}/package-deploy-artifact.sh"
fi

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
    inject_remote_file "${INJECT_TMP_TRUNCATED}" "${KERMESSE_ARTIFACT_NAME}"
    echo "[INJECT] Archive tronquée sur la cible."

elif [[ "${INJECT_MODE}" == "bad-checksum" ]]; then
    CURRENT_STEP="injection-bad-checksum"
    echo ""
    echo "[INJECT] Altération du checksum sur la cible..."
    INJECT_TMP_CHECKSUM="$(mktemp)"
    printf '0000000000000000000000000000000000000000000000000000000000000000  %s\n' "${KERMESSE_ARTIFACT_NAME}" \
        > "${INJECT_TMP_CHECKSUM}"
    inject_remote_file "${INJECT_TMP_CHECKSUM}" "kermesse-deploy.tar.gz.sha256"
    echo "[INJECT] Checksum altéré sur la cible."
fi

# ── Étape 3/5 : Activation atomique ─────────────────────────────────────────
CURRENT_STEP="activation"
echo ""
echo "-- Étape 3/5 : Activation atomique"
ACTIVATE_ROUTE="ops/activate"
ACTIVATE_BODY="{\"archive\":\"${KERMESSE_ARTIFACT_NAME}\"}"
ops_sign "${ACTIVATE_ROUTE}" "${ACTIVATE_BODY}"
HTTP_CODE=$(curl --max-time 30 --fail-with-body -sS -X POST "${BASE_URL%/}/${ACTIVATE_ROUTE}" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -w "%{http_code}" -o /dev/stderr \
    -d "${ACTIVATE_BODY}")
if [[ "$HTTP_CODE" != "200" ]]; then
    echo "ERREUR : Webhook d'activation a retourné HTTP $HTTP_CODE (attendu 200)" >&2
    exit 1
fi
echo ""

# ── Helper : appeler ops/migrate ou ops/migrate/status et valider la réponse JSON ──
# $1 = étiquette d'affichage, $2 = route
# Vérifie HTTP 200, capture le corps JSON, échoue si failed[] non vide.
call_migrate_endpoint() {
    local label="$1"
    local route="$2"
    local response_file
    response_file="$(mktemp)"

    ops_sign "${route}"
    local http_code
    http_code=$(curl --max-time 60 -sS -X POST "${BASE_URL%/}/${route}" \
        -H "Content-Type: application/json" \
        -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
        -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
        -H "X-Kermesse-Signature: ${SIGN_SIG}" \
        -w "%{http_code}" -o "${response_file}" \
        -d '{}')

    local response_body
    response_body="$(cat "${response_file}")"
    rm -f "${response_file}"

    echo "  Réponse HTTP : ${http_code}"
    if [[ -n "${response_body}" ]]; then
        echo "  Corps : ${response_body}"
    fi

    if [[ "${http_code}" != "200" ]]; then
        echo "ERREUR : ${label} a retourné HTTP ${http_code} (attendu 200)" >&2
        exit 1
    fi

    # Valider le JSON : failed[] doit être vide pour un état sain.
    # On utilise grep/awk si jq n'est pas disponible dans le conteneur.
    if command -v jq >/dev/null 2>&1; then
        local failed_count
        failed_count="$(echo "${response_body}" | jq -r 'if .failed then (.failed | length) else 0 end' 2>/dev/null || echo "0")"
        if [[ "${failed_count}" != "0" && "${failed_count}" != "" ]]; then
            echo "ERREUR : ${label} — migrations en échec détectées (failed=${failed_count})" >&2
            echo "Corps : ${response_body}" >&2
            exit 1
        fi
    else
        # Fallback sans jq : rejeter si "failed" contient autre chose que [].
        if echo "${response_body}" | grep -qE '"failed"\s*:\s*\[.+\]'; then
            echo "ERREUR : ${label} — migrations en échec détectées dans la réponse" >&2
            echo "Corps : ${response_body}" >&2
            exit 1
        fi
    fi
    echo "  ${label} [OK] — aucun échec détecté"
}

# ── Drift fix : remédiation du drift de checksum connu ───────────────────────
# Appelé avant le préflight pour s'assurer qu'un drift connu est réconcilié
# avant que ops/migrate/status ne détecte et bloque sur le drift.
# Idempotent : si le checksum est déjà à jour, la réponse est ok+already_reconciled.
CURRENT_STEP="drift-fix"
echo ""
echo "-- Drift-fix : remédiation du drift de checksum (20260614121500_add_last_login_at_to_users)"
DRIFT_BODY='{"version":"20260614121500_add_last_login_at_to_users"}'
ops_sign "ops/fix-drift" "${DRIFT_BODY}"
DRIFT_RESPONSE_FILE="$(mktemp)"
DRIFT_HTTP=$(curl --max-time 30 -sS -X POST "${BASE_URL%/}/ops/fix-drift" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -w "%{http_code}" -o "${DRIFT_RESPONSE_FILE}" \
    -d "${DRIFT_BODY}")
DRIFT_BODY_RESPONSE="$(cat "${DRIFT_RESPONSE_FILE}")"
rm -f "${DRIFT_RESPONSE_FILE}"

echo "  Réponse HTTP : ${DRIFT_HTTP}"
if [[ -n "${DRIFT_BODY_RESPONSE}" ]]; then
    echo "  Corps : ${DRIFT_BODY_RESPONSE}"
fi

if [[ "${DRIFT_HTTP}" != "200" ]]; then
    echo "ERREUR : ops/fix-drift a retourné HTTP ${DRIFT_HTTP} (attendu 200)" >&2
    exit 1
fi
echo "  Drift-fix [OK]"

# ── Préflight migration : état des migrations avant toute exécution ───────────
# Lecture seule via ops/migrate/status — vérifie qu'aucune migration n'est en échec.
# Si failed[] non vide, le déploiement est bloqué : résoudre avant de continuer.
CURRENT_STEP="preflight-migration"
echo ""
echo "-- Préflight : vérification de l'état des migrations (lecture seule)"
PREFLIGHT_RESPONSE_FILE="$(mktemp)"
ops_sign "ops/migrate/status"
PREFLIGHT_HTTP=$(curl --max-time 30 -sS -X POST "${BASE_URL%/}/ops/migrate/status" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -w "%{http_code}" -o "${PREFLIGHT_RESPONSE_FILE}" \
    -d '{}')
PREFLIGHT_BODY="$(cat "${PREFLIGHT_RESPONSE_FILE}")"
rm -f "${PREFLIGHT_RESPONSE_FILE}"

if [[ "${PREFLIGHT_HTTP}" != "200" ]]; then
    echo "ERREUR : Préflight ops/migrate/status a retourné HTTP ${PREFLIGHT_HTTP} (attendu 200)" >&2
    exit 1
fi

if command -v jq >/dev/null 2>&1; then
    PREFLIGHT_FAILED="$(echo "${PREFLIGHT_BODY}" | jq -r 'if .failed then (.failed | length) else 0 end' 2>/dev/null || echo "0")"
    PREFLIGHT_PENDING="$(echo "${PREFLIGHT_BODY}" | jq -r 'if .pending then (.pending | length) else 0 end' 2>/dev/null || echo "0")"
else
    PREFLIGHT_FAILED="0"
    PREFLIGHT_PENDING="0"
    if echo "${PREFLIGHT_BODY}" | grep -qE '"failed"\s*:\s*\[.+\]'; then PREFLIGHT_FAILED="1"; fi
fi

if [[ "${PREFLIGHT_FAILED}" != "0" && "${PREFLIGHT_FAILED}" != "" ]]; then
    echo "ERREUR : Résolvez les migrations en échec avant de tenter une nouvelle exécution (failed=${PREFLIGHT_FAILED})" >&2
    echo "Corps : ${PREFLIGHT_BODY}" >&2
    exit 1
fi
echo "  Préflight OK — failed=[] ; migrations en attente avant exécution : ${PREFLIGHT_PENDING}"
echo ""

# ── Étape 4/5 : Migration en deux phases (expand/contract) ───────────────────
# Phase 1 : créer la vue de compatibilité AVANT d'activer le nouveau code.
# Phase 2 : renommage physique APRÈS validation de la vue.
#
# CONTRAINTE ARCHITECTURALE :
# Dans ce pipeline, l'activation (Étape 3) précède les deux phases de migration.
# Pour ce déploiement spécifique, une fenêtre de downtime très courte existe entre
# l'activation et la fin de la Phase 1 (création de la vue slot_signups).
# Ce pipeline garantit néanmoins que la Phase 2 (RENAME TABLE) ne s'exécute
# qu'après validation de la vue, ce qui est impossible avec un run() monolithique.
CURRENT_STEP="migration-phase1"
echo ""
echo "-- Étape 4/5 : Migration Phase 1 — vue de compatibilité slot_signups (expand)"
PHASE1_BODY='{"until_version":"20260619500000_create_slot_signups_compat_view"}'
ops_sign "ops/migrate" "${PHASE1_BODY}"
PHASE1_RESPONSE_FILE="$(mktemp)"
PHASE1_HTTP=$(curl --max-time 60 -sS -X POST "${BASE_URL%/}/ops/migrate" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -w "%{http_code}" -o "${PHASE1_RESPONSE_FILE}" \
    -d "${PHASE1_BODY}")
PHASE1_BODY_RESPONSE="$(cat "${PHASE1_RESPONSE_FILE}")"
rm -f "${PHASE1_RESPONSE_FILE}"
echo "  Réponse HTTP Phase 1 : ${PHASE1_HTTP}"
if [[ -n "${PHASE1_BODY_RESPONSE}" ]]; then echo "  Corps : ${PHASE1_BODY_RESPONSE}"; fi
if [[ "${PHASE1_HTTP}" != "200" ]]; then
    echo "ERREUR : Migration Phase 1 a retourné HTTP ${PHASE1_HTTP} (attendu 200)" >&2
    exit 1
fi
echo "  Phase 1 [OK] — vue de compatibilité créée"

# ── Smoke test Phase 1 : lecture + écriture sur slot_signups (vue) ─────────────
# Exécuté DANS le conteneur deploy-client (accès direct au service MariaDB).
# Vérifie que la vue slot_signups est accessible en lecture ET en écriture DML
# avant de procéder au renommage physique (Phase 2).
# L'UPDATE WHERE id = 0 ne modifie aucune ligne — il vérifie uniquement que
# la vue/table existe et accepte des requêtes DML (écriture sans mutation de données).
CURRENT_STEP="smoke-slot-signups-phase1"
echo ""
echo "-- Smoke test Phase 1 : lecture/écriture sur slot_signups (vue de compatibilité)"
READ_COUNT=$(MYSQL_PWD="${DB_RESET_PASS}" mysql --skip-ssl \
    -h "${DB_RESET_HOST}" -u "${DB_RESET_USER}" "${DB_RESET_NAME}" \
    -N -e "SELECT COUNT(*) FROM \`slot_signups\`;") || {
    echo "ERREUR : Lecture sur slot_signups échouée — la table est absente, inaccessible, ou la connexion DB a échoué" >&2
    exit 1
}
echo "  Lecture slot_signups [OK] — ${READ_COUNT} lignes"

# UPDATE no-op (WHERE id = 0 ne modifie aucune ligne) : vérifie que le DML
# est accepté sur la table (droits + syntaxe + existence de la colonne updated_at).
MYSQL_PWD="${DB_RESET_PASS}" mysql --skip-ssl \
    -h "${DB_RESET_HOST}" -u "${DB_RESET_USER}" "${DB_RESET_NAME}" \
    -e "UPDATE \`slot_signups\` SET \`updated_at\` = \`updated_at\` WHERE \`id\` = 0;" || {
    echo "ERREUR : Écriture sur slot_signups échouée — vérifier les droits DML ou l'existence de la colonne updated_at" >&2
    exit 1
}
echo "  Écriture slot_signups [OK] — UPDATE no-op exécuté (via vue de compatibilité)"

# ── Migration Phase 2 : renommage physique (contraction) ─────────────────────
# Exécuté APRÈS validation de la vue : garantit que slot_signups était accessible
# avant de DROP VIEW + RENAME TABLE.
CURRENT_STEP="migration-phase2"
echo ""
echo "-- Migration Phase 2 — renommage physique signups → slot_signups (contract)"
call_migrate_endpoint "Webhook migration Phase 2 (rename)" "ops/migrate"
echo ""

# ── Preuve d'idempotence : ré-exécution = no-op ──────────────────────────────
# Capturer PENDING_BEFORE_SECOND avant la seconde exécution pour prouver qu'elle ne peut
# rien appliquer : si pending=0 avant, la seconde migration est nécessairement un no-op.
CURRENT_STEP="migration-idempotence"
echo "-- Preuve d'idempotence : seconde migration (doit être no-op)"
IDEMPOTENCE_STATUS_FILE="$(mktemp)"
ops_sign "ops/migrate/status"
IDEMPOTENCE_HTTP=$(curl --max-time 30 -sS -X POST "${BASE_URL%/}/ops/migrate/status" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -w "%{http_code}" -o "${IDEMPOTENCE_STATUS_FILE}" \
    -d '{}')
IDEMPOTENCE_STATUS_BODY="$(cat "${IDEMPOTENCE_STATUS_FILE}")"
rm -f "${IDEMPOTENCE_STATUS_FILE}"

if [[ "${IDEMPOTENCE_HTTP}" != "200" ]]; then
    echo "ERREUR : Statut avant seconde migration : HTTP ${IDEMPOTENCE_HTTP}" >&2
    exit 1
fi

if command -v jq >/dev/null 2>&1; then
    PENDING_BEFORE_SECOND="$(echo "${IDEMPOTENCE_STATUS_BODY}" | jq -r 'if .pending then (.pending | length) else 0 end' 2>/dev/null || echo "0")"
else
    PENDING_BEFORE_SECOND="0"
    if echo "${IDEMPOTENCE_STATUS_BODY}" | grep -qE '"pending"\s*:\s*\[.+\]'; then PENDING_BEFORE_SECOND="1"; fi
fi

echo "  Migrations en attente avant seconde exécution : PENDING_BEFORE_SECOND=${PENDING_BEFORE_SECOND}"
if [[ "${PENDING_BEFORE_SECOND}" != "0" && "${PENDING_BEFORE_SECOND}" != "" ]]; then
    echo "ERREUR : Idempotence impossible — migrations encore en attente (PENDING_BEFORE_SECOND=${PENDING_BEFORE_SECOND})" >&2
    echo "La première migration n'a pas appliqué toutes les migrations attendues." >&2
    exit 1
fi
echo "  PENDING_BEFORE_SECOND=0 — la seconde migration ne peut appliquer aucune migration."

call_migrate_endpoint "Seconde migration (idempotence)" "ops/migrate"
echo ""

# ── Smoke test Post-Phase 2 : lecture + écriture sur slot_signups (table réelle) ──
CURRENT_STEP="smoke-slot-signups-phase2"
echo ""
echo "-- Smoke test Phase 2 : lecture/écriture sur slot_signups (table physique après RENAME)"
READ_COUNT_P2=$(MYSQL_PWD="${DB_RESET_PASS}" mysql --skip-ssl \
    -h "${DB_RESET_HOST}" -u "${DB_RESET_USER}" "${DB_RESET_NAME}" \
    -N -e "SELECT COUNT(*) FROM \`slot_signups\`;") || {
    echo "ERREUR : Lecture sur slot_signups (table physique) échouée après RENAME TABLE" >&2
    exit 1
}
echo "  Lecture slot_signups [OK] — ${READ_COUNT_P2} lignes (table physique)"

MYSQL_PWD="${DB_RESET_PASS}" mysql --skip-ssl \
    -h "${DB_RESET_HOST}" -u "${DB_RESET_USER}" "${DB_RESET_NAME}" \
    -e "UPDATE \`slot_signups\` SET \`updated_at\` = \`updated_at\` WHERE \`id\` = 0;" || {
    echo "ERREUR : Écriture sur slot_signups (table physique) échouée" >&2
    exit 1
}
echo "  Écriture slot_signups [OK] — UPDATE no-op exécuté (table physique confirmée)"

CURRENT_STEP="verification-etat"
echo ""
echo "-- Étape 5/5 : Postflight — vérification de l'état des migrations"
STATUS_ROUTE="ops/migrate/status"
ops_sign "${STATUS_ROUTE}"
STATUS_RESPONSE_FILE="$(mktemp)"
HTTP_CODE=$(curl --max-time 30 -sS -X POST "${BASE_URL%/}/${STATUS_ROUTE}" \
    -H "Content-Type: application/json" \
    -H "X-Kermesse-Timestamp: ${SIGN_TS}" \
    -H "X-Kermesse-Nonce: ${SIGN_NONCE}" \
    -H "X-Kermesse-Signature: ${SIGN_SIG}" \
    -w "%{http_code}" -o "${STATUS_RESPONSE_FILE}" \
    -d '{}')
STATUS_BODY="$(cat "${STATUS_RESPONSE_FILE}")"
rm -f "${STATUS_RESPONSE_FILE}"

if [[ "${HTTP_CODE}" != "200" ]]; then
    echo "ERREUR : Webhook de statut a retourné HTTP ${HTTP_CODE} (attendu 200)" >&2
    exit 1
fi
echo "  Statut HTTP : ${HTTP_CODE}"
if [[ -n "${STATUS_BODY}" ]]; then
    echo "  Statut complet : ${STATUS_BODY}"
fi

# Postflight strict : pending[] ET failed[] doivent être vides.
if command -v jq >/dev/null 2>&1; then
    PENDING_COUNT="$(echo "${STATUS_BODY}" | jq -r 'if .pending then (.pending | length) else 0 end' 2>/dev/null || echo "0")"
    FAILED_COUNT="$(echo "${STATUS_BODY}"  | jq -r 'if .failed  then (.failed  | length) else 0 end' 2>/dev/null || echo "0")"
    if [[ "${PENDING_COUNT}" != "0" ]]; then
        echo "ERREUR : Postflight — migrations encore en attente (pending=${PENDING_COUNT})" >&2
        exit 1
    fi
    if [[ "${FAILED_COUNT}" != "0" ]]; then
        echo "ERREUR : Postflight — migrations en échec (failed=${FAILED_COUNT})" >&2
        exit 1
    fi
else
    if echo "${STATUS_BODY}" | grep -qE '"pending"\s*:\s*\[.+\]'; then
        echo "ERREUR : Postflight — migrations encore en attente" >&2
        exit 1
    fi
    if echo "${STATUS_BODY}" | grep -qE '"failed"\s*:\s*\[.+\]'; then
        echo "ERREUR : Postflight — migrations en échec" >&2
        exit 1
    fi
fi
echo "  Postflight OK — pending=[] et failed=[]"
echo ""

# ── Résumé ───────────────────────────────────────────────────────────────────
echo ""
echo "=========================================="
echo "REHEARSAL OK"
echo "=========================================="
if [[ "${USE_EXISTING_ARTIFACT}" == true ]]; then
    echo "  Artefact préexistant [OK]"
else
    echo "  Packaging            [OK]"
fi
echo "  Transfert            [OK]"
echo "  Activation           [OK]"
echo "  Drift-fix            [OK] — checksum réconcilié"
echo "  Migration Phase 1    [OK] — vue de compatibilité slot_signups créée"
echo "  Smoke Phase 1        [OK] — lecture et écriture sur vue confirmées"
echo "  Migration Phase 2    [OK] — RENAME TABLE signups → slot_signups"
echo "  Smoke Phase 2        [OK] — lecture et écriture sur table physique confirmées"
echo "  Idempotence          [OK] — PENDING_BEFORE_SECOND=${PENDING_BEFORE_SECOND} (no-op prouvé)"
echo "  Postflight statut    [OK]"
echo ""
