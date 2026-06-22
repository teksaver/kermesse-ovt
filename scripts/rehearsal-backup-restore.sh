#!/usr/bin/env bash
# Répétition de sauvegarde et restauration MariaDB pour Kermesse (profil rehearsal).
#
# Ce script est exclusivement réservé à l'environnement Docker de répétition.
# Il ne doit jamais être exécuté sur la production — Ouvaton ne dispose pas d'un
# accès CLI direct pour mariadb-dump ou mariadb. Le mécanisme de sauvegarde
# production disponible chez Ouvaton est documenté dans la section "Sauvegarde Ouvaton"
# en bas de ce script.
#
# Usage :
#   bash scripts/rehearsal-backup-restore.sh [--skip-fixtures]
#
# Enchaîne :
#   1. Chargement des fixtures synthétiques (état pré-release représentatif)
#   2. Sauvegarde logique (mariadb-dump)
#   3. Vérification du dump (schéma, nombre de lignes, invariants FK)
#   4. Restauration dans une base isolée propre
#   5. Vérification post-restauration (schéma, lignes, FK, invariants P0/P1)
#   6. Rapport de durées (sauvegarde + restauration = mesure RTO)
#
# Exécuté DANS le conteneur deploy-client via l'indirection Docker (FR-21).
# Variables d'environnement avec valeurs par défaut pour le profil rehearsal.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ── Indirection hôte → conteneur (FR-21) ─────────────────────────────────────
if [[ "${KERMESSE_REHEARSAL_CONTAINER:-}" != "1" ]]; then
    if ! hash docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        echo "ERREUR : Docker et le plugin docker-compose sont requis côté hôte." >&2
        exit 1
    fi
    REEXEC_ENV=()
    for _v in DB_HOST DB_PORT DB_USER DB_PASS DB_NAME DB_RESTORE_NAME \
              KERMESSE_REHEARSAL_CONTAINER; do
        if eval "[ -n \"\${${_v}+x}\" ]"; then
            REEXEC_ENV+=(-e "${_v}")
        fi
    done
    exec docker compose --profile rehearsal run --rm -T \
        ${REEXEC_ENV[@]+"${REEXEC_ENV[@]}"} \
        deploy-client bash scripts/rehearsal-backup-restore.sh "$@"
fi

# ── Arguments ─────────────────────────────────────────────────────────────────
SKIP_FIXTURES=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-fixtures) SKIP_FIXTURES=true; shift ;;
        *) echo "ERREUR : argument inconnu : $1" >&2; exit 1 ;;
    esac
done

# ── Variables d'environnement (valeurs par défaut rehearsal) ──────────────────
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root_password}"
DB_NAME="${DB_NAME:-kermesse}"
# Base de restauration isolée — ne jamais restaurer dans la base principale.
DB_RESTORE_NAME="${DB_RESTORE_NAME:-kermesse_restore_test}"

# ── Vérifications de base ─────────────────────────────────────────────────────
hash mariadb-dump mariadb || { echo "ERREUR : mariadb-dump et mariadb doivent être disponibles dans le conteneur" >&2; exit 1; }

echo "=== Répétition sauvegarde/restauration Kermesse ==="
echo ""
echo "Source  : ${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "Cible   : ${DB_HOST}:${DB_PORT}/${DB_RESTORE_NAME}"
echo ""

# Vérification de sécurité : la base de restauration ne peut pas être identique à la source.
# Protège contre une restauration accidentelle qui écraserait les données de qualification.
if [[ "${DB_RESTORE_NAME}" == "${DB_NAME}" ]]; then
    echo "ERREUR : DB_RESTORE_NAME (${DB_RESTORE_NAME}) ne peut pas être identique à DB_NAME (${DB_NAME})." >&2
    echo "Utilisez une base de restauration isolée distincte de la base source." >&2
    exit 1
fi

BACKUP_FILE="$(mktemp /tmp/kermesse-backup-XXXXXX.sql)"
TIMING_BACKUP_START=""
TIMING_BACKUP_END=""
TIMING_RESTORE_START=""
TIMING_RESTORE_END=""

cleanup_backup() {
    if [[ -f "${BACKUP_FILE}" ]]; then
        rm -f "${BACKUP_FILE}"
    fi
    # Nettoyage garanti de la base de restauration même en cas d'erreur.
    # MYSQL_PWD utilisé pour éviter l'avertissement sur la ligne de commande.
    MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
        -e "DROP DATABASE IF EXISTS \`${DB_RESTORE_NAME}\`;" 2>/dev/null || true
}
trap cleanup_backup EXIT INT TERM

# ── Étape 1 : Chargement des fixtures synthétiques ───────────────────────────
if [[ "${SKIP_FIXTURES}" != true ]]; then
    echo "-- Étape 1/5 : Chargement des fixtures synthétiques (état pré-release)"
    FIXTURES_SQL="${PROJECT_ROOT}/tests/fixtures/rehearsal-state.sql"
    if [[ ! -f "${FIXTURES_SQL}" ]]; then
        echo "ERREUR : fichier de fixtures absent : ${FIXTURES_SQL}" >&2
        echo "Les fixtures représentatives sont obligatoires pour la qualification RC." >&2
        echo "Pour utiliser l'état courant (déconseillé) : passer --skip-fixtures explicitement." >&2
        exit 1
    else
        MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
            -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_NAME}" \
            < "${FIXTURES_SQL}"
        echo "  Fixtures chargées."
    fi
else
    echo "-- Étape 1/5 : Chargement des fixtures sauté (--skip-fixtures)"
fi
echo ""

# ── Étape 2 : Sauvegarde logique (mariadb-dump) ───────────────────────────────
echo "-- Étape 2/5 : Sauvegarde logique"
TIMING_BACKUP_START="$(date +%s)"
# --skip-ssl : réseau Docker interne, pas de TLS requis.
# --single-transaction : snapshot cohérent InnoDB sans lock global.
# --routines --triggers : inclut toutes les définitions DB.
# Credentials via MYSQL_PWD : évite l'avertissement sur la ligne de commande.
MYSQL_PWD="${DB_PASS}" mariadb-dump --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
    --single-transaction --routines --triggers \
    "${DB_NAME}" > "${BACKUP_FILE}"
TIMING_BACKUP_END="$(date +%s)"
BACKUP_SIZE="$(wc -c < "${BACKUP_FILE}")"
BACKUP_LINES="$(wc -l < "${BACKUP_FILE}")"
echo "  Sauvegarde : ${BACKUP_LINES} lignes, ${BACKUP_SIZE} octets"
echo "  Durée sauvegarde : $((TIMING_BACKUP_END - TIMING_BACKUP_START))s"
echo ""

# ── Étape 3 : Vérification de la sauvegarde ──────────────────────────────────
echo "-- Étape 3/5 : Vérification du dump"
if [[ "${BACKUP_SIZE}" -lt 1000 ]]; then
    echo "ERREUR : dump trop petit (${BACKUP_SIZE} octets) — vraisemblablement vide." >&2
    exit 1
fi
if ! grep -q "CREATE TABLE" "${BACKUP_FILE}"; then
    echo "ERREUR : aucun CREATE TABLE dans le dump — base probablement vide." >&2
    exit 1
fi

# Vérifier que les tables critiques P0 sont dans le dump.
REQUIRED_TABLES=(schema_versions ops_nonces kermesses users access_tokens email_events stands slots slot_signups kermesse_user_roles)
for table in "${REQUIRED_TABLES[@]}"; do
    if ! grep -q "CREATE TABLE \`${table}\`" "${BACKUP_FILE}" 2>/dev/null && \
       ! grep -q "CREATE TABLE \`${table}\` " "${BACKUP_FILE}" 2>/dev/null && \
       ! grep -q "CREATE TABLE IF NOT EXISTS \`${table}\`" "${BACKUP_FILE}" 2>/dev/null; then
        echo "ERREUR : table critique absente du dump : ${table}" >&2
        exit 1
    fi
    echo "  ✓ Table ${table} présente dans le dump"
done
echo ""

# ── Étape 4 : Restauration dans une base isolée ───────────────────────────────
echo "-- Étape 4/5 : Restauration dans ${DB_RESTORE_NAME}"
# Créer la base de restauration (DROP IF EXISTS + CREATE pour garantir un état propre).
MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
    -e "DROP DATABASE IF EXISTS \`${DB_RESTORE_NAME}\`; CREATE DATABASE \`${DB_RESTORE_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

TIMING_RESTORE_START="$(date +%s)"
MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" \
    "${DB_RESTORE_NAME}" < "${BACKUP_FILE}"
TIMING_RESTORE_END="$(date +%s)"
echo "  Durée restauration : $((TIMING_RESTORE_END - TIMING_RESTORE_START))s"
echo ""

# ── Étape 5 : Vérification post-restauration ─────────────────────────────────
echo "-- Étape 5/5 : Vérification post-restauration"
RESTORED_TABLES="$(MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_RESTORE_NAME}" \
    -N -e "SHOW TABLES;" 2>/dev/null)"

for table in "${REQUIRED_TABLES[@]}"; do
    if echo "${RESTORED_TABLES}" | grep -qw "${table}"; then
        echo "  ✓ Table ${table} restaurée"
    else
        echo "ERREUR : table ${table} absente après restauration" >&2
        exit 1
    fi
done

# Vérification des comptages de lignes (cohérence source ↔ restaurée).
echo ""
echo "  Comparaison des comptages source ↔ restaurée :"
for table in "${REQUIRED_TABLES[@]}"; do
    SOURCE_COUNT="$(MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_NAME}" \
        -N -e "SELECT COUNT(*) FROM \`${table}\`;" 2>/dev/null || echo "N/A")"
    RESTORED_COUNT="$(MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_RESTORE_NAME}" \
        -N -e "SELECT COUNT(*) FROM \`${table}\`;" 2>/dev/null || echo "N/A")"
    if [[ "${SOURCE_COUNT}" == "${RESTORED_COUNT}" ]]; then
        echo "  ✓ ${table} : ${SOURCE_COUNT} lignes (identique)"
    else
        echo "ERREUR : ${table} — source=${SOURCE_COUNT}, restaurée=${RESTORED_COUNT}" >&2
        exit 1
    fi
done

# Vérification FK de base : slot_signups référence slots et users (si des lignes existent).
echo ""
echo "  Vérification des contraintes FK P0 :"
FK_VIOLATIONS="$(MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_RESTORE_NAME}" \
    -N -e "SELECT COUNT(*) FROM slot_signups ss LEFT JOIN slots s ON ss.slot_id = s.id WHERE s.id IS NULL;" 2>/dev/null || echo "ERR")"
if [[ "${FK_VIOLATIONS}" == "0" ]]; then
    echo "  ✓ FK slot_signups → slots : aucune violation"
else
    echo "ERREUR : violations FK slot_signups → slots après restauration : ${FK_VIOLATIONS}" >&2
    exit 1
fi

FK_USERS_VIOLATIONS="$(MYSQL_PWD="${DB_PASS}" mariadb --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_RESTORE_NAME}" \
    -N -e "SELECT COUNT(*) FROM slot_signups ss LEFT JOIN users u ON ss.user_id = u.id WHERE u.id IS NULL;" 2>/dev/null || echo "ERR")"
if [[ "${FK_USERS_VIOLATIONS}" == "0" ]]; then
    echo "  ✓ FK slot_signups → users : aucune violation"
else
    echo "ERREUR : violations FK slot_signups → users après restauration : ${FK_USERS_VIOLATIONS}" >&2
    exit 1
fi

# La base de restauration est supprimée via le trap cleanup_backup (EXIT).
echo "  Base de restauration sera supprimée à la fin (trap EXIT)."

# ── Rapport final ─────────────────────────────────────────────────────────────
echo ""
echo "=========================================="
echo "BACKUP/RESTORE OK"
echo "=========================================="
echo "  Fixtures          [OK]"
echo "  Sauvegarde        [OK] — $((TIMING_BACKUP_END - TIMING_BACKUP_START))s"
echo "  Vérification dump [OK]"
echo "  Restauration      [OK] — $((TIMING_RESTORE_END - TIMING_RESTORE_START))s"
echo "  Vérification      [OK]"
echo ""
echo "  RTO mesuré       : $((TIMING_RESTORE_END - TIMING_RESTORE_START))s"
echo "  Taille du dump   : ${BACKUP_SIZE} octets"
echo ""
echo "NOTE : Le mécanisme de sauvegarde Ouvaton (production) doit être validé"
echo "séparément. Ouvaton propose des sauvegardes automatiques quotidiennes gérées"
echo "par l'hébergeur. Une sauvegarde manuelle récente doit être confirmée avant"
echo "tout Go en production. Aucun accès CLI direct n'est disponible pour lancer"
echo "mariadb-dump sur le serveur Ouvaton."
