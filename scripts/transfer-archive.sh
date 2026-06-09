#!/usr/bin/env bash
# Transfert de l'archive de déploiement vers le staging Ouvaton.
# Dépose kermesse-deploy.tar.gz et son .sha256 dans le dossier de staging distant
# (${REMOTE_STAGING}) via lftp put.
# Conforme NFR-2 : ni .env ni writable/ ne transitent (ils ne figurent pas dans l'archive opaque).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Nom d'artefact : source de vérité unique partagée avec package-deploy-artifact.sh.
# shellcheck source=lib/artifact.sh
source "${SCRIPT_DIR}/lib/artifact.sh"
# Échappement lftp single-quote : source de vérité unique partagée avec deploy-rehearsal.sh.
# shellcheck source=lib/lftp-escape.sh
source "${SCRIPT_DIR}/lib/lftp-escape.sh"

ARCHIVE="${PROJECT_ROOT}/build/${KERMESSE_ARTIFACT_NAME}"
CHECKSUM="${ARCHIVE}.sha256"
# Relative path from the SFTP root — must be set explicitly by the caller.
# No hardcoded default: the correct value depends on the deployment context.
#   Production (Ouvaton) :  REMOTE_STAGING=kermesse/staging
#     (kermesse = OUVATON_DEPLOY_REMOTE_FOLDER, app folder is a sub-dir of the SFTP home)
#   Local rehearsal        :  REMOTE_STAGING=staging
#     (deploy base mounted directly at the SFTP root, set by deploy-rehearsal.sh)
# See docs/deployment-ouvaton.md §"Écarts profil rehearsal".
REMOTE_STAGING="${REMOTE_STAGING:-}"

echo "=== Transfert de l'archive vers le staging ==="

# 1. Vérification des variables d'environnement obligatoires
required_vars=(TARGET_HOST TARGET_PORT TARGET_PROTO TARGET_USER TARGET_PASS REMOTE_STAGING)
missing=0
for var in "${required_vars[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    if [[ "${var}" == "TARGET_PASS" && -n "${TARGET_KEY:-}" ]]; then
      continue
    fi
    echo "ERREUR : variable d'environnement manquante : ${var}" >&2
    missing=1
  fi
done
if [[ ${missing} -eq 1 ]]; then
  echo "Variables requises : TARGET_HOST TARGET_PORT TARGET_PROTO TARGET_USER TARGET_PASS REMOTE_STAGING" >&2
  echo "  REMOTE_STAGING exemples : 'staging' (rehearsal local) ou 'kermesse/staging' (Ouvaton prod)" >&2
  echo "Variable optionnelle : TARGET_KEY (chemin vers la clé SSH, pour SFTP sans mot de passe)" >&2
  exit 1
fi

# 2. Vérification de la présence des artefacts locaux
if [[ ! -f "${ARCHIVE}" ]]; then
  echo "ERREUR : archive introuvable : ${ARCHIVE}" >&2
  echo "Lancez d'abord scripts/package-deploy-artifact.sh" >&2
  exit 1
fi
if [[ ! -f "${CHECKSUM}" ]]; then
  echo "ERREUR : checksum introuvable : ${CHECKSUM}" >&2
  exit 1
fi

# 3. Vérification de lftp
if ! command -v lftp >/dev/null 2>&1; then
  echo "ERREUR : commande requise introuvable : lftp" >&2
  exit 1
fi

# 4. Validation du protocole avant toute connexion
case "${TARGET_PROTO}" in
  ftp|ftps|sftp) ;;
  *)
    echo "ERREUR : protocole non supporté : ${TARGET_PROTO} (valeurs acceptées : ftp, ftps, sftp)" >&2
    exit 1
    ;;
esac

echo "Protocole    : ${TARGET_PROTO}"
echo "Hôte         : ${TARGET_HOST}:${TARGET_PORT}"
echo "Dossier cible: ${REMOTE_STAGING}/"
echo "Archive      : $(basename "${ARCHIVE}")"

# 5. Transfert via lftp — put individuel, jamais mirror
# Les commandes sont passées via substitution de processus pour éviter d'exposer
# le mot de passe sur le disque ou dans la liste des processus. Le bloc case est
# résolu AVANT le <(...), qui ne fait alors qu'écho.
ESCAPED_PASS="$(lftp_squote "${TARGET_PASS:-}")"

PROTO_SETTINGS=""
case "${TARGET_PROTO}" in
  sftp)
    if [[ "${TARGET_SFTP_SKIP_HOST_CHECK:-false}" == "true" ]]; then
      # Rehearsal uniquement : le conteneur SFTP regénère sa clé hôte à chaque démarrage,
      # donc toute entrée known_hosts épinglée devient aussitôt périmée. On demande à lftp
      # d'accepter automatiquement la clé pour cette connexion. Exécuté DANS deploy-client,
      # le known_hosts éventuellement écrit est celui, ÉPHÉMÈRE, du conteneur (jeté à la
      # sortie via --rm) — jamais le ~/.ssh/known_hosts de l'hôte. Aucune entrée périmée à
      # purger : le conteneur démarre vierge à chaque exécution.
      # Ce bypass ne doit JAMAIS être activé contre une cible de production.
      PROTO_SETTINGS="set sftp:auto-confirm yes;"
    elif [[ -n "${TARGET_KEY:-}" ]]; then
      # Production with key-based auth: delegate to external SSH.
      _escaped_key="${TARGET_KEY//\'/\'\\\'\'}"
      PROTO_SETTINGS="set sftp:connect-program \"ssh -a -x -i '${_escaped_key}'\";"
    fi
    ;;
  ftps)
    PROTO_SETTINGS="set ftp:ssl-force true; set ftp:ssl-protect-data true;"
    ;;
esac

lftp -f <(
  echo "set cmd:fail-exit true;"
  # Robustesse réseau (reprise sur coupure transitoire) — alignée sur l'ancien
  # bloc inline du workflow de production, désormais factorisé ici.
  echo "set net:max-retries 3;"
  echo "set net:timeout 60;"
  [[ -n "${PROTO_SETTINGS}" ]] && echo "${PROTO_SETTINGS}"
  echo "open -u '$(lftp_squote "${TARGET_USER}")','${ESCAPED_PASS}' -p ${TARGET_PORT} ${TARGET_PROTO}://${TARGET_HOST};"
  # Disable fail-exit for mkdir: the directory may already exist (idempotent).
  # The "mkdir: Failure" message is harmless when the directory already exists.
  echo "set cmd:fail-exit false; mkdir -p \"${REMOTE_STAGING}\"; set cmd:fail-exit true;"
  echo "put \"${ARCHIVE}\" -o \"${REMOTE_STAGING}/$(basename "${ARCHIVE}")\";"
  echo "put \"${CHECKSUM}\" -o \"${REMOTE_STAGING}/$(basename "${CHECKSUM}")\";"
  echo "bye"
)

echo "=== Transfert réussi ! ==="
echo "Archive déposée : ${TARGET_PROTO}://${TARGET_HOST}:${TARGET_PORT}/${REMOTE_STAGING}/"
