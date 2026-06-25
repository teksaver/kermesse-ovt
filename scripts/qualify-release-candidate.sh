#!/usr/bin/env bash
# Qualification d'une release candidate Kermesse.
#
# Ce script orchestre la qualification complète d'un candidat identifié par son manifeste.
# Il doit être exécuté sur l'hôte (macOS/Linux avec Docker), PAS dans un conteneur.
#
# Usage :
#   bash scripts/qualify-release-candidate.sh [--manifest <path>] [--skip-smoke]
#
# Le script enchaîne :
#   1. Vérification du manifeste RC (identité commit, SHA256, run_id)
#   2. Déploiement en répétition avec --use-existing-artifact (binaire non reconstruit)
#   3. Smoke tests Playwright sur la release activée
#   4. Génération du rapport de preuves RC
#
# Préconditions :
#   - L'artefact kermesse-deploy.tar.gz et son sidecar doivent être présents dans build/
#     (téléchargés depuis la CI via actions/download-artifact ou manuellement)
#   - Le profil Docker rehearsal doit être démarré (docker compose --profile rehearsal up -d)
#   - Node.js + Playwright doivent être installés localement (npm install)
#
# Variables d'environnement :
#   RC_EVIDENCE_DIR  Dossier de sortie des preuves (défaut : rc-evidence/)
#   RC_COMMIT_SHA    SHA attendu pour la vérification manifeste (optionnel)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ── Arguments ─────────────────────────────────────────────────────────────────
MANIFEST_PATH="${PROJECT_ROOT}/build/kermesse-deploy-manifest.json"
SKIP_SMOKE=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --manifest)
            if [[ $# -lt 2 ]]; then
                echo "ERREUR : --manifest requiert un chemin" >&2
                exit 1
            fi
            MANIFEST_PATH="$2"
            shift 2
            ;;
        --skip-smoke) SKIP_SMOKE=true; shift ;;
        *) echo "ERREUR : argument inconnu : $1" >&2; exit 1 ;;
    esac
done

# ── Variables ─────────────────────────────────────────────────────────────────
RC_EVIDENCE_DIR="${RC_EVIDENCE_DIR:-${PROJECT_ROOT}/build/rc-evidence}"
RC_COMMIT_SHA="${RC_COMMIT_SHA:-}"
QUALIFICATION_START="$(date +%s)"
QUALIFICATION_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

mkdir -p "${RC_EVIDENCE_DIR}"

echo "=== Qualification Release Candidate Kermesse ==="
echo ""
echo "Date : ${QUALIFICATION_DATE}"
echo "Dossier preuves : ${RC_EVIDENCE_DIR}"
echo ""

# ── Étape 1 : Vérification du manifeste ──────────────────────────────────────
echo "-- Étape 1/4 : Vérification du manifeste RC"

if [[ ! -f "${MANIFEST_PATH}" ]]; then
    echo "ERREUR : manifeste RC absent : ${MANIFEST_PATH}" >&2
    echo "Téléchargez l'artefact CI dans build/ avant de lancer la qualification." >&2
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "ERREUR : jq est requis pour la qualification RC." >&2
    exit 1
fi
MANIFEST_SHA="$(jq -r '.commit_sha' "${MANIFEST_PATH}")"
MANIFEST_RUN_ID="$(jq -r '.ci_run_id' "${MANIFEST_PATH}")"
MANIFEST_ARCHIVE_SHA256="$(jq -r '.archive_sha256' "${MANIFEST_PATH}")"
MANIFEST_TIMESTAMP="$(jq -r '.timestamp_utc' "${MANIFEST_PATH}")"
MANIFEST_ARTIFACT_NAME="$(jq -r '.artifact_name // "unknown"' "${MANIFEST_PATH}")"

echo "  Commit SHA     : ${MANIFEST_SHA}"
echo "  CI Run ID      : ${MANIFEST_RUN_ID}"
echo "  Archive SHA256 : ${MANIFEST_ARCHIVE_SHA256}"
echo "  Horodatage CI  : ${MANIFEST_TIMESTAMP}"

# Vérifier SHA attendu si fourni.
if [[ -n "${RC_COMMIT_SHA}" ]] && [[ "${MANIFEST_SHA}" != "${RC_COMMIT_SHA}" ]]; then
    echo "ERREUR : manifeste SHA (${MANIFEST_SHA}) ≠ RC_COMMIT_SHA (${RC_COMMIT_SHA})" >&2
    exit 1
fi

# Vérifier le checksum de l'archive.
ARTIFACT_PATH="${PROJECT_ROOT}/build/kermesse-deploy.tar.gz"
SIDECAR_PATH="${PROJECT_ROOT}/build/kermesse-deploy.tar.gz.sha256"

if [[ ! -f "${ARTIFACT_PATH}" || ! -f "${SIDECAR_PATH}" ]]; then
    echo "ERREUR : artefact ou sidecar absent dans build/" >&2
    exit 1
fi

( cd "${PROJECT_ROOT}/build" && sha256sum -c "$(basename "${SIDECAR_PATH}")" ) || {
    echo "ERREUR : checksum de l'artefact invalide." >&2
    exit 1
}

ACTUAL_SHA256="$(awk '{print $1}' "${SIDECAR_PATH}")"
if [[ "${ACTUAL_SHA256}" != "${MANIFEST_ARCHIVE_SHA256}" ]]; then
    echo "ERREUR : SHA256 sidecar (${ACTUAL_SHA256}) ≠ manifeste (${MANIFEST_ARCHIVE_SHA256})" >&2
    exit 1
fi

echo "  ✓ Identité RC vérifiée — archive = manifeste"

# Copier le manifeste dans les preuves.
cp "${MANIFEST_PATH}" "${RC_EVIDENCE_DIR}/manifest.json"
echo ""

# ── Étape 2 : Déploiement en répétition ──────────────────────────────────────
echo "-- Étape 2/4 : Déploiement en répétition (binaire qualifié)"
REHEARSAL_LOG="${RC_EVIDENCE_DIR}/rehearsal.log"
set +e
bash "${SCRIPT_DIR}/deploy-rehearsal.sh" --use-existing-artifact 2>&1 | tee "${REHEARSAL_LOG}"
REHEARSAL_EXIT=$?
set -e

if [[ "${REHEARSAL_EXIT}" -ne 0 ]]; then
    echo "ERREUR : répétition échouée (code ${REHEARSAL_EXIT}) — voir ${REHEARSAL_LOG}" >&2
    echo "QUALIFICATION ÉCHOUÉE" >&2
    exit 1
fi
echo "  ✓ Répétition réussie"
echo ""

# ── Étape 2bis : Répétition sauvegarde/restauration ─────────────────────────
echo "-- Étape 2bis/4 : Répétition sauvegarde/restauration"
BACKUP_LOG="${RC_EVIDENCE_DIR}/backup-restore.log"
set +e
bash "${SCRIPT_DIR}/rehearsal-backup-restore.sh" 2>&1 | tee "${BACKUP_LOG}"
BACKUP_EXIT=$?
set -e
if [[ "${BACKUP_EXIT}" -ne 0 ]]; then
    echo "ERREUR : répétition sauvegarde/restauration échouée (code ${BACKUP_EXIT}) — voir ${BACKUP_LOG}" >&2
    echo "QUALIFICATION ÉCHOUÉE — sauvegarde/restauration" >&2
    exit 1
fi
echo "  ✓ Sauvegarde/restauration réussie"
echo ""

# ── Étape 3 : Smoke tests Playwright ─────────────────────────────────────────
SMOKE_STATUS="skipped"
if [[ "${SKIP_SMOKE}" != true ]]; then
    echo "-- Étape 3/4 : Smoke tests Playwright (qualification RC)"
    SMOKE_LOG="${RC_EVIDENCE_DIR}/smoke-tests.log"
    PLAYWRIGHT_REPORT_DIR="${RC_EVIDENCE_DIR}/playwright-report"

    # Les smoke tests s'exécutent sur l'environnement rehearsal déjà actif.
    # On passe BASE_URL qui pointe vers deploy-web (accessible depuis l'hôte via le port publié).
    if [[ ! -x "${PROJECT_ROOT}/node_modules/.bin/playwright" ]]; then
        echo "ERREUR : Playwright non installé. Lancez 'npm install' avant la qualification." >&2
        exit 1
    fi
    set +e
    PLAYWRIGHT_HTML_REPORT=0 \
    BASE_URL="http://localhost:8081" \
        "${PROJECT_ROOT}/node_modules/.bin/playwright" test \
        --reporter=list,json \
        --output="${RC_EVIDENCE_DIR}/test-results" \
        2>&1 | tee "${SMOKE_LOG}"
    SMOKE_EXIT=$?
    set -e

    # Archiver les traces toujours (pas seulement en cas d'échec) — preuves RC complètes.
    if [[ -d "test-results" ]]; then
        cp -r "test-results" "${RC_EVIDENCE_DIR}/test-results" 2>/dev/null || true
    fi
    if [[ -d "playwright-report" ]]; then
        cp -r "playwright-report" "${RC_EVIDENCE_DIR}/playwright-report" 2>/dev/null || true
    fi

    if [[ "${SMOKE_EXIT}" -ne 0 ]]; then
        echo "ERREUR : smoke tests échoués (code ${SMOKE_EXIT}) — voir ${SMOKE_LOG}" >&2
        echo "QUALIFICATION ÉCHOUÉE — smoke tests" >&2
        exit 1
    fi
    SMOKE_STATUS="passed"
    echo "  ✓ Smoke tests passés"
else
    echo "-- Étape 3/4 : Smoke tests sautés (--skip-smoke)"
fi
echo ""

# ── Étape 4 : Rapport de preuves RC ──────────────────────────────────────────
echo "-- Étape 4/4 : Génération du rapport de preuves RC"
QUALIFICATION_END="$(date +%s)"
QUALIFICATION_DURATION="$((QUALIFICATION_END - QUALIFICATION_START))"

RC_DECISION="PENDING"
if [[ "${SMOKE_STATUS}" == "skipped" ]]; then
    RC_DECISION="INCOMPLETE"
fi

cat > "${RC_EVIDENCE_DIR}/rc-qualification-report.json" <<EOF
{
  "qualification_date": "${QUALIFICATION_DATE}",
  "duration_seconds": ${QUALIFICATION_DURATION},
  "candidate": {
    "commit_sha": "${MANIFEST_SHA}",
    "ci_run_id": "${MANIFEST_RUN_ID}",
    "archive_sha256": "${MANIFEST_ARCHIVE_SHA256}",
    "artifact_timestamp": "${MANIFEST_TIMESTAMP}"
  },
  "gates": {
    "manifest_verified": true,
    "rehearsal_passed": true,
    "smoke_tests": "${SMOKE_STATUS}"
  },
  "decision": "${RC_DECISION}",
  "notes": "Remplir avant le Go : responsable déploiement, décideur rollback, seuils Stop/Rollback, RTO max."
}
EOF

echo "  Rapport RC : ${RC_EVIDENCE_DIR}/rc-qualification-report.json"
echo ""

if [[ "${SMOKE_STATUS}" == "passed" ]]; then
    echo "=========================================="
    echo "QUALIFICATION OK"
    echo "=========================================="
    echo "  Manifeste vérifié    [OK]"
    echo "  Répétition           [OK]"
    echo "  Sauvegarde/restore   [OK]"
    echo "  Smoke tests          [OK]"
    echo ""
    echo "  Durée totale         : ${QUALIFICATION_DURATION}s"
    echo "  Commit candidat      : ${MANIFEST_SHA}"
    echo "  CI Run               : ${MANIFEST_RUN_ID}"
    echo ""
    echo "PROCHAINE ÉTAPE : compléter la revue Go/No-Go"
    echo "  docs/release-candidate-runbook.md"
    echo ""
elif [[ "${SMOKE_STATUS}" == "skipped" ]]; then
    echo "=========================================="
    echo "QUALIFICATION PARTIELLE"
    echo "=========================================="
    echo "  Manifeste vérifié    [OK]"
    echo "  Répétition           [OK]"
    echo "  Sauvegarde/restore   [OK]"
    echo "  Smoke tests          [SAUTÉ — relancer sans --skip-smoke pour une qualification complète]"
    echo ""
    echo "  AVERTISSEMENT : les smoke tests sont obligatoires pour le Go en production."
    echo "  Relancer sans --skip-smoke avant toute décision de déploiement."
    echo "  decision: INCOMPLETE — le Go ne peut pas être accordé sans smoke tests verts."
    echo ""
    echo "  Durée totale         : ${QUALIFICATION_DURATION}s"
    echo "  Commit candidat      : ${MANIFEST_SHA}"
    echo "  CI Run               : ${MANIFEST_RUN_ID}"
    echo ""
fi
