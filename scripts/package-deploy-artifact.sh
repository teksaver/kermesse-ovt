#!/usr/bin/env bash
# Script de packaging pour Kermesse (déploiement Ouvaton)
# Ce script prépare un artefact propre sans dépendances de développement ni fichiers secrets.

set -euo pipefail

# Détermination des répertoires de travail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

STAGING_DIR="${PROJECT_ROOT}/build/staging"
OUTPUT_ZIP="${PROJECT_ROOT}/build/kermesse-deploy.zip"

echo "=== Début du packaging de l'artefact ==="
echo "Racine du projet : ${PROJECT_ROOT}"
echo "Dossier de staging : ${STAGING_DIR}"
echo "Fichier de sortie : ${OUTPUT_ZIP}"

for command in composer zip unzip; do
  if ! command -v "${command}" >/dev/null 2>&1; then
    echo "ERREUR : commande requise introuvable : ${command}"
    exit 1
  fi
done

# Nettoyage précédent
rm -rf "${STAGING_DIR}"
rm -f "${OUTPUT_ZIP}"
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

# Recherche récursive de clés privées, secrets ou fichiers de configuration de test (hors vendor)
# On cherche *.key, *.pem, auth.json, phpunit*, et tout vrai .env ou override local.
while IFS= read -r found; do
  echo "ERREUR : Fichier ou secret interdit détecté de manière récursive : ${found}"
  FORBIDDEN_FOUND=1
done < <(find "${STAGING_DIR}" -not -path "${STAGING_DIR}/vendor/*" \( -name "*.key" -o -name "*.pem" -o -name "auth.json" -o -name "phpunit*" -o -name ".env" -o -name ".env.local" -o -name ".env.*.local" \) -print)

if [ ${FORBIDDEN_FOUND} -eq 1 ]; then
  echo "ÉCHEC : Des fichiers interdits ont été trouvés dans le staging. Packaging interrompu."
  exit 1
fi

# 4. Création de l'archive ZIP
echo "Création de l'archive ZIP..."
cd "${STAGING_DIR}"
zip -q -r "${OUTPUT_ZIP}" .

# 5. Vérification du contenu du ZIP généré (hors vendor/)
echo "Validation du contenu de l'archive ZIP générée..."
ZIP_FORBIDDEN_FOUND=0

echo "Contenu de l'archive ZIP :"
unzip -Z -1 "${OUTPUT_ZIP}"

while IFS= read -r line; do
  # Nettoyage des slashs de fin pour les dossiers
  clean_line="${line%/}"
  
  # Vérification par rapport aux dossiers interdits au niveau racine
  for dir in ".git" "node_modules" "tests" "_bmad-output" "_bmad" ".agents" ".agent"; do
    if [[ "${clean_line}" == "${dir}" || "${clean_line}" == "${dir}"/* ]]; then
      echo "ERREUR : Fichier ou répertoire interdit détecté dans l'archive ZIP : ${line}"
      ZIP_FORBIDDEN_FOUND=1
    fi
  done

  # Vérification par rapport aux fichiers interdits spécifiques
  for file in ".env" ".env.local" "auth.json"; do
    # On autorise .env.example, donc on vérifie exactement .env et les overrides locaux.
    if [[ "${clean_line}" == "${file}" || "${clean_line}" == */"${file}" ]]; then
      echo "ERREUR : Fichier interdit détecté dans l'archive ZIP : ${line}"
      ZIP_FORBIDDEN_FOUND=1
    fi
  done

  if [[ "${clean_line}" == .env.*.local || "${clean_line}" == */.env.*.local ]]; then
    echo "ERREUR : Fichier local d'environnement interdit détecté dans l'archive ZIP : ${line}"
    ZIP_FORBIDDEN_FOUND=1
  fi

  if [[ "${clean_line}" == phpunit* || "${clean_line}" == */phpunit* ]]; then
    echo "ERREUR : Fichier phpunit interdit détecté dans l'archive ZIP : ${line}"
    ZIP_FORBIDDEN_FOUND=1
  fi

  # Vérification récursive pour *.key et *.pem
  if [[ "${clean_line}" == *.key || "${clean_line}" == *.pem ]]; then
    echo "ERREUR : Fichier de clé secrète détecté dans l'archive ZIP : ${line}"
    ZIP_FORBIDDEN_FOUND=1
  fi
done < <(unzip -Z -1 "${OUTPUT_ZIP}" | grep -v "^vendor/" || true)

if [ ${ZIP_FORBIDDEN_FOUND} -eq 1 ]; then
  echo "ÉCHEC : L'archive ZIP contient des fichiers interdits."
  exit 1
fi

echo "=== Packaging réussi ! ==="
echo "Archive créée avec succès : ${OUTPUT_ZIP}"
ls -lh "${OUTPUT_ZIP}"
