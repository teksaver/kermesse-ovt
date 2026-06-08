#!/usr/bin/env bash
# Script de packaging pour Kermesse (déploiement Ouvaton)
# Ce script prépare un artefact propre sans dépendances de développement ni fichiers secrets.

set -euo pipefail

# Détermination des répertoires de travail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Nom d'artefact : source de vérité unique partagée avec transfer-archive.sh / deploy-rehearsal.sh.
# shellcheck source=lib/artifact.sh
source "${SCRIPT_DIR}/lib/artifact.sh"

STAGING_DIR="${PROJECT_ROOT}/build/staging"
OUTPUT_TAR="${PROJECT_ROOT}/build/${KERMESSE_ARTIFACT_NAME}"
OUTPUT_CHECKSUM="${OUTPUT_TAR}.sha256"

echo "=== Début du packaging de l'artefact ==="
echo "Racine du projet : ${PROJECT_ROOT}"
echo "Dossier de staging : ${STAGING_DIR}"
echo "Fichier de sortie : ${OUTPUT_TAR}"

for command in composer tar awk; do
  if ! command -v "${command}" >/dev/null 2>&1; then
    echo "ERREUR : commande requise introuvable : ${command}"
    exit 1
  fi
done
if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
  echo "ERREUR : commande requise introuvable : sha256sum ou shasum"
  exit 1
fi

# Nettoyage précédent
rm -rf "${STAGING_DIR}"
rm -f "${OUTPUT_TAR}" "${OUTPUT_CHECKSUM}"
mkdir -p "${STAGING_DIR}"

# 1. Copie des fichiers et dossiers nécessaires
echo "Copie des fichiers applicatifs..."
cp -R "${PROJECT_ROOT}/app" "${STAGING_DIR}/"
cp -R "${PROJECT_ROOT}/public" "${STAGING_DIR}/"

# Création et copie propre de writable/ (structure et placeholders uniquement, pas les fichiers générés)
echo "Création de l'arborescence writable..."
mkdir -p "${STAGING_DIR}/writable"
cp "${PROJECT_ROOT}/writable/.htaccess" "${STAGING_DIR}/writable/"
cp "${PROJECT_ROOT}/writable/index.html" "${STAGING_DIR}/writable/"

for dir in cache debugbar logs session uploads; do
  mkdir -p "${STAGING_DIR}/writable/${dir}"
  if [ -f "${PROJECT_ROOT}/writable/${dir}/index.html" ]; then
    cp "${PROJECT_ROOT}/writable/${dir}/index.html" "${STAGING_DIR}/writable/${dir}/"
  fi
  if [ -f "${PROJECT_ROOT}/writable/${dir}/.htaccess" ]; then
    cp "${PROJECT_ROOT}/writable/${dir}/.htaccess" "${STAGING_DIR}/writable/${dir}/"
  fi
done

# Copie de la structure database
echo "Copie des fichiers de base de données..."
mkdir -p "${STAGING_DIR}/database/schema"
mkdir -p "${STAGING_DIR}/database/migrations_sql"

# S'il y a des fichiers dans schema ou migrations_sql, les copier
if [ -d "${PROJECT_ROOT}/database/schema" ] && [ "$(ls -A "${PROJECT_ROOT}/database/schema" 2>/dev/null)" ]; then
  find "${PROJECT_ROOT}/database/schema" -mindepth 1 -maxdepth 1 -exec cp -R {} "${STAGING_DIR}/database/schema/" \;
fi
if [ -d "${PROJECT_ROOT}/database/migrations_sql" ] && [ "$(ls -A "${PROJECT_ROOT}/database/migrations_sql" 2>/dev/null)" ]; then
  find "${PROJECT_ROOT}/database/migrations_sql" -mindepth 1 -maxdepth 1 -exec cp -R {} "${STAGING_DIR}/database/migrations_sql/" \;
fi

# Copie des fichiers de configuration et documentation
cp "${PROJECT_ROOT}/composer.json" "${STAGING_DIR}/"
cp "${PROJECT_ROOT}/composer.lock" "${STAGING_DIR}/"
cp "${PROJECT_ROOT}/.env.example" "${STAGING_DIR}/"

mkdir -p "${STAGING_DIR}/docs"
if [ -f "${PROJECT_ROOT}/docs/deployment-ouvaton.md" ]; then
  cp "${PROJECT_ROOT}/docs/deployment-ouvaton.md" "${STAGING_DIR}/docs/"
fi
if [ -f "${PROJECT_ROOT}/docs/migration-runner.md" ]; then
  cp "${PROJECT_ROOT}/docs/migration-runner.md" "${STAGING_DIR}/docs/"
fi

# 2. Installation des dépendances Composer de production
echo "Installation des dépendances Composer (sans dev)..."
cd "${STAGING_DIR}"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "Suppression des fichiers de test inutiles dans vendor..."
find "${STAGING_DIR}/vendor" -type d \( -name "test" -o -name "tests" -o -name "Test" -o -name "Tests" \) -prune -exec rm -rf {} +
find "${STAGING_DIR}/vendor" -type f -name "phpunit*" -delete

# 3. Validation des fichiers interdits avant archivage
echo "Vérification des fichiers interdits dans le dossier de staging (hors vendor)..."
FORBIDDEN_FOUND=0

# Vérification des dossiers interdits à la racine du staging
FORBIDDEN_ROOT_DIRS=(
  ".git"
  "node_modules"
  "tests"
  "_bmad-output"
  "_bmad"
  ".agents"
  ".agent"
  "deploy"
)

for dir in "${FORBIDDEN_ROOT_DIRS[@]}"; do
  if [ -d "${STAGING_DIR}/${dir}" ]; then
    echo "ERREUR : Répertoire interdit détecté à la racine du staging : ${dir}"
    FORBIDDEN_FOUND=1
  fi
done

# Vérification des fichiers interdits à la racine du staging
FORBIDDEN_ROOT_FILES=(
  ".env"
  ".env.next"
  ".env.local"
  "auth.json"
)

for file in "${FORBIDDEN_ROOT_FILES[@]}"; do
  if [ -f "${STAGING_DIR}/${file}" ]; then
    echo "ERREUR : Fichier interdit détecté à la racine du staging : ${file}"
    FORBIDDEN_FOUND=1
  fi
done

while IFS= read -r found; do
  echo "ERREUR : Fichier local d'environnement interdit détecté à la racine du staging : ${found}"
  FORBIDDEN_FOUND=1
done < <(find "${STAGING_DIR}" -maxdepth 1 -type f -name ".env.*.local" -print)

while IFS= read -r found; do
  echo "ERREUR : Fichier phpunit interdit détecté à la racine du staging : ${found}"
  FORBIDDEN_FOUND=1
done < <(find "${STAGING_DIR}" -maxdepth 1 -type f -name "phpunit*" -print)

while IFS= read -r found; do
  echo "ERREUR : Fichier d'environnement interdit détecté dans le staging : ${found}"
  FORBIDDEN_FOUND=1
done < <(find "${STAGING_DIR}" -type f \( -name ".env" -o -name ".env.next" \) -print)

# Recherche récursive de clés privées, secrets ou fichiers de configuration de test (hors vendor)
# On cherche *.key, *.pem, auth.json, phpunit*, et tout vrai .env ou candidat .env.next.
while IFS= read -r found; do
  echo "ERREUR : Fichier ou secret interdit détecté de manière récursive : ${found}"
  FORBIDDEN_FOUND=1
done < <(find "${STAGING_DIR}" -not -path "${STAGING_DIR}/vendor/*" \( -name "*.key" -o -name "*.pem" -o -name "auth.json" -o -name "phpunit*" -o -name ".env" -o -name ".env.next" -o -name ".env.local" -o -name ".env.*.local" \) -print)

if [ ${FORBIDDEN_FOUND} -eq 1 ]; then
  echo "ÉCHEC : Des fichiers interdits ont été trouvés dans le staging. Packaging interrompu."
  exit 1
fi

# 4. Création de l'archive tar.gz
echo "Création de l'archive tar.gz..."
cd "${STAGING_DIR}"
tar -czf "${OUTPUT_TAR}" . || exit 1

# 5. Génération du checksum SHA-256 (format compatible sha256sum -c)
echo "Génération du checksum SHA-256..."
if command -v sha256sum >/dev/null 2>&1; then
  # Génère "<hash>  <basename>" pour compatibilité sha256sum -c
  (cd "$(dirname "${OUTPUT_TAR}")" && sha256sum "$(basename "${OUTPUT_TAR}")") > "${OUTPUT_CHECKSUM}"
elif command -v shasum >/dev/null 2>&1; then
  (cd "$(dirname "${OUTPUT_TAR}")" && shasum -a 256 "$(basename "${OUTPUT_TAR}")") > "${OUTPUT_CHECKSUM}"
else
  echo "ERREUR : sha256sum ou shasum introuvable. Impossible de générer le checksum."
  exit 1
fi
echo "Checksum SHA-256 : $(awk '{print $1}' "${OUTPUT_CHECKSUM}")"

# 6. Validation du contenu de l'archive tar.gz générée (hors vendor/)
echo "Validation du contenu de l'archive tar.gz générée..."
IS_ARCHIVE_INVALID=0

echo "Contenu de l'archive tar.gz :"
tar -tzf "${OUTPUT_TAR}" || exit 1

while IFS= read -r line; do
  # Nettoyage des slashs de fin pour les dossiers
  clean_line="${line%/}"
  
  # Retrait du préfixe ./ pour simplifier les comparaisons
  clean_line="${clean_line#./}"

  in_vendor=0
  if [[ "${clean_line}" == "vendor" || "${clean_line}" == "vendor/"* ]]; then
    in_vendor=1
  fi

  # Vérification par rapport aux dossiers interdits au niveau racine
  for dir in "${FORBIDDEN_ROOT_DIRS[@]}"; do
    if [[ "${clean_line}" == "${dir}" || "${clean_line}" == "${dir}/"* ]]; then
      echo "ERREUR : Fichier ou répertoire interdit détecté dans l'archive tar.gz : ${line}"
      IS_ARCHIVE_INVALID=1
    fi
  done

  # Vérification récursive (hors vendor)
  if [ $in_vendor -eq 0 ]; then
    filename="${clean_line##*/}"
    if [[ "${filename}" == ".env" || "${filename}" == ".env.next" || "${filename}" == ".env.local" || "${filename}" == .env.*.local || "${filename}" == "auth.json" || "${filename}" == phpunit* || "${filename}" == *.key || "${filename}" == *.pem ]]; then
      echo "ERREUR : Fichier interdit détecté dans l'archive tar.gz : ${line}"
      IS_ARCHIVE_INVALID=1
    fi
  fi
done < <(tar -tzf "${OUTPUT_TAR}" || exit 1)

if [ ${IS_ARCHIVE_INVALID} -eq 1 ]; then
  echo "ÉCHEC : L'archive tar.gz contient des fichiers interdits."
  rm -f "${OUTPUT_TAR}" "${OUTPUT_CHECKSUM}"
  exit 1
fi

echo "=== Packaging réussi ! ==="
echo "Archive créée avec succès : ${OUTPUT_TAR}"
echo "Checksum SHA-256 : ${OUTPUT_CHECKSUM}"
ls -lh "${OUTPUT_TAR}" "${OUTPUT_CHECKSUM}"
