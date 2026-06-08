#!/usr/bin/env bash
# Transfert de l'archive de déploiement vers le staging Ouvaton.
# Dépose kermesse-deploy.tar.gz et son .sha256 dans le dossier de staging distant
# (${REMOTE_STAGING}) via lftp put.
# Conforme NFR-2 : ni .env ni writable/ ne transitent (ils ne figurent pas dans l'archive opaque).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

ARCHIVE="${PROJECT_ROOT}/build/kermesse-deploy.tar.gz"
CHECKSUM="${ARCHIVE}.sha256"
# Relative path from the SFTP root — must match the staging dir the activation service
# reads (opsActivateBasePath + /staging/).
# Production (Ouvaton): the application folder is a SUBDIR of the SFTP home, so the archive
# must land in kermesse/staging/ (see docs/deployment-ouvaton.md §"Écarts profil rehearsal").
# Local rehearsal: the deploy base is mounted directly at the SFTP root, so deploy-rehearsal.sh
# overrides this to REMOTE_STAGING=staging. Keep the production-correct default here.
REMOTE_STAGING="${REMOTE_STAGING:-kermesse/staging}"

echo "=== Transfert de l'archive vers le staging ==="

# 1. Vérification des variables d'environnement obligatoires
required_vars=(TARGET_HOST TARGET_PORT TARGET_PROTO TARGET_USER TARGET_PASS)
missing=0
for var in "${required_vars[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    echo "ERREUR : variable d'environnement manquante : ${var}" >&2
    missing=1
  fi
done
if [[ ${missing} -eq 1 ]]; then
  echo "Variables requises : TARGET_HOST TARGET_PORT TARGET_PROTO TARGET_USER TARGET_PASS" >&2
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
ESCAPED_PASS="${TARGET_PASS//\'/\\\'}"

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
      _escaped_key="${TARGET_KEY//\'/\\\'}"
      PROTO_SETTINGS="set sftp:connect-program \"ssh -a -x -i '${_escaped_key}'\";"
    fi
    ;;
  ftps)
    PROTO_SETTINGS="set ftp:ssl-force true; set ftp:ssl-protect-data true;"
    ;;
esac

lftp -f <(
  echo "set cmd:fail-exit true;"
  [[ -n "${PROTO_SETTINGS}" ]] && echo "${PROTO_SETTINGS}"
  echo "open -u '${TARGET_USER}','${ESCAPED_PASS}' -p ${TARGET_PORT} ${TARGET_PROTO}://${TARGET_HOST};"
  # Disable fail-exit for mkdir: the directory may already exist (idempotent).
  # The "mkdir: Failure" message is harmless when the directory already exists.
  echo "set cmd:fail-exit false; mkdir -p ${REMOTE_STAGING}; set cmd:fail-exit true;"
  echo "put \"${ARCHIVE}\" -o \"${REMOTE_STAGING}/$(basename "${ARCHIVE}")\";"
  echo "put \"${CHECKSUM}\" -o \"${REMOTE_STAGING}/$(basename "${CHECKSUM}")\";"
  echo "bye"
)

echo "=== Transfert réussi ! ==="
echo "Archive déposée : ${TARGET_PROTO}://${TARGET_HOST}:${TARGET_PORT}/${REMOTE_STAGING}/"
