#!/usr/bin/env bash
# Tests statiques pour scripts/qualify-release-candidate.sh et le pipeline RC.
# Vérifie les contrats : présence des modes, rejets des candidats invalides, etc.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
QUALIFY_SCRIPT="${PROJECT_ROOT}/scripts/qualify-release-candidate.sh"
REHEARSAL_SCRIPT="${PROJECT_ROOT}/scripts/deploy-rehearsal.sh"
BACKUP_SCRIPT="${PROJECT_ROOT}/scripts/rehearsal-backup-restore.sh"
CI_WORKFLOW="${PROJECT_ROOT}/.github/workflows/ci.yml"
DEPLOY_WORKFLOW="${PROJECT_ROOT}/.github/workflows/deploy-ouvaton.yml"

fail=0

assert_contains() {
    local label="$1" needle="$2" file="$3"
    if ! grep -Fq -- "${needle}" "${file}"; then
        printf 'FAIL %s\n  attendu dans %s : %s\n' "${label}" "$(basename "${file}")" "${needle}" >&2
        fail=1
    else
        printf 'ok   %s\n' "${label}"
    fi
}

assert_not_contains() {
    local label="$1" needle="$2" file="$3"
    if grep -Fq -- "${needle}" "${file}"; then
        printf 'FAIL %s\n  ne devait pas contenir dans %s : %s\n' "${label}" "$(basename "${file}")" "${needle}" >&2
        fail=1
    else
        printf 'ok   %s\n' "${label}"
    fi
}

assert_file_exists() {
    local label="$1" path="$2"
    if [[ ! -f "${path}" ]]; then
        printf 'FAIL %s\n  fichier absent : %s\n' "${label}" "${path}" >&2
        fail=1
    else
        printf 'ok   %s\n' "${label}"
    fi
}

assert_executable() {
    local label="$1" path="$2"
    if [[ ! -x "${path}" ]]; then
        printf 'FAIL %s\n  non exécutable : %s\n' "${label}" "${path}" >&2
        fail=1
    else
        printf 'ok   %s\n' "${label}"
    fi
}

# ─── Fichiers présents et exécutables ───────────────────────────────────────
assert_file_exists "qualify-release-candidate.sh existe" "${QUALIFY_SCRIPT}"
assert_executable  "qualify-release-candidate.sh exécutable" "${QUALIFY_SCRIPT}"
assert_file_exists "rehearsal-backup-restore.sh existe" "${BACKUP_SCRIPT}"
assert_executable  "rehearsal-backup-restore.sh exécutable" "${BACKUP_SCRIPT}"

# ─── qualify-release-candidate.sh : contrats fonctionnels ───────────────────
assert_contains "qualify: vérifie le manifeste" 'kermesse-deploy-manifest.json' "${QUALIFY_SCRIPT}"
assert_contains "qualify: vérifie SHA256 avant tout" 'sha256sum -c' "${QUALIFY_SCRIPT}"
assert_contains "qualify: appelle --use-existing-artifact" '--use-existing-artifact' "${QUALIFY_SCRIPT}"
assert_contains "qualify: compare SHA sidecar vs manifeste" 'MANIFEST_ARCHIVE_SHA256' "${QUALIFY_SCRIPT}"
assert_contains "qualify: supporte --skip-smoke" '--skip-smoke' "${QUALIFY_SCRIPT}"
assert_contains "qualify: génère rapport JSON" 'rc-qualification-report.json' "${QUALIFY_SCRIPT}"
assert_contains "qualify: archivage dans RC_EVIDENCE_DIR" 'RC_EVIDENCE_DIR' "${QUALIFY_SCRIPT}"
assert_contains "qualify: fail-fast si manifeste absent" 'ERREUR : manifeste RC absent' "${QUALIFY_SCRIPT}"
assert_contains "qualify: fail-fast si SHA diverge" 'SHA256 sidecar' "${QUALIFY_SCRIPT}"
assert_contains "qualify: fail-fast si rehearsal échoue" 'QUALIFICATION ÉCHOUÉE' "${QUALIFY_SCRIPT}"
assert_contains "qualify: suivi du temps de qualification" 'QUALIFICATION_DURATION' "${QUALIFY_SCRIPT}"
assert_contains "qualify: smoke tests sur BASE_URL rehearsal" 'localhost:8081' "${QUALIFY_SCRIPT}"

# ─── rehearsal-backup-restore.sh : contrats fonctionnels ────────────────────
assert_contains "backup: utilise mariadb-dump" 'mariadb-dump' "${BACKUP_SCRIPT}"
assert_contains "backup: utilise mariadb client" 'mariadb --skip-ssl' "${BACKUP_SCRIPT}"
assert_contains "backup: --single-transaction pour InnoDB" '--single-transaction' "${BACKUP_SCRIPT}"
assert_contains "backup: MYSQL_PWD masque le mot de passe" 'MYSQL_PWD=' "${BACKUP_SCRIPT}"
assert_contains "backup: restauration dans base isolée" 'DB_RESTORE_NAME' "${BACKUP_SCRIPT}"
assert_contains "backup: DROP DATABASE IF EXISTS" 'DROP DATABASE IF EXISTS' "${BACKUP_SCRIPT}"
assert_contains "backup: vérifie tables critiques dans dump" 'schema_versions' "${BACKUP_SCRIPT}"
assert_contains "backup: vérifie comptages source ↔ restaurée" 'SOURCE_COUNT' "${BACKUP_SCRIPT}"
assert_contains "backup: vérifie FK P0" 'FK_VIOLATIONS' "${BACKUP_SCRIPT}"
assert_contains "backup: mesure durée sauvegarde" 'TIMING_BACKUP' "${BACKUP_SCRIPT}"
assert_contains "backup: mesure durée restauration (RTO)" 'TIMING_RESTORE' "${BACKUP_SCRIPT}"
assert_contains "backup: nettoie la base de restauration" 'DROP DATABASE IF EXISTS' "${BACKUP_SCRIPT}"
assert_contains "backup: note mécanisme prod Ouvaton" 'mécanisme de sauvegarde Ouvaton' "${BACKUP_SCRIPT}"
assert_contains "backup: indirection Docker FR-21" 'KERMESSE_REHEARSAL_CONTAINER' "${BACKUP_SCRIPT}"
assert_not_contains "backup: ne touche jamais la prod Ouvaton" 'sftp' "${BACKUP_SCRIPT}"

# ─── deploy-rehearsal.sh : contrats --use-existing-artifact ─────────────────
assert_contains "rehearsal: flag --use-existing-artifact parsé" '--use-existing-artifact' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: USE_EXISTING_ARTIFACT variable" 'USE_EXISTING_ARTIFACT' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: skip packaging en mode RC" 'promotion du binaire qualifié' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: vérifie archive préexistante" 'artefact préexistant absent' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: vérifie sidecar préexistant" 'sidecar SHA256 absent' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: vérifie checksum en mode RC" 'checksum de l'"'"'artefact préexistant invalide' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: parse JSON failed[] après migration" 'failed_count' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: postflight vérifie pending[]" 'PENDING_COUNT' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: postflight vérifie failed[]" 'FAILED_COUNT' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: preuve idempotence (seconde migration)" 'Preuve d'"'"'idempotence' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: idempotence = no-op attendu" 'Seconde migration (idempotence)' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: fail si failed non vide" 'migrations en échec détectées' "${REHEARSAL_SCRIPT}"
assert_contains "rehearsal: postflight fail si pending non vide" 'migrations encore en attente' "${REHEARSAL_SCRIPT}"

# ─── ci.yml : manifeste RC ───────────────────────────────────────────────────
assert_contains "ci: génère le manifeste RC" 'Generate release candidate manifest' "${CI_WORKFLOW}"
assert_contains "ci: manifeste contient commit_sha" '"commit_sha"' "${CI_WORKFLOW}"
assert_contains "ci: manifeste contient ci_run_id" '"ci_run_id"' "${CI_WORKFLOW}"
assert_contains "ci: manifeste contient archive_sha256" '"archive_sha256"' "${CI_WORKFLOW}"
assert_contains "ci: manifeste contient artifact_name" '"artifact_name"' "${CI_WORKFLOW}"
assert_contains "ci: manifeste contient timestamp_utc" '"timestamp_utc"' "${CI_WORKFLOW}"
assert_contains "ci: upload manifeste dans artefact main" 'kermesse-deploy-manifest.json' "${CI_WORKFLOW}"
assert_contains "ci: log artifact-id" 'artifact-id' "${CI_WORKFLOW}"
assert_contains "ci: preuves RC séparées" 'rc-evidence-' "${CI_WORKFLOW}"
assert_contains "ci: rétention preuves 30 jours" 'retention-days: 30' "${CI_WORKFLOW}"

# ─── deploy-ouvaton.yml : promotion sans rebuild ─────────────────────────────
assert_contains "deploy: job download-and-verify" 'download-and-verify' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: input ci_run_id" 'ci_run_id' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: input expected_sha" 'expected_sha' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: download avec run-id" 'run-id:' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: download avec github-token" 'github-token:' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: vérifie le manifeste après download" 'Verify manifest and archive identity' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: vérifie SHA manifeste vs head_sha" 'MANIFEST_SHA' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: vérifie run_id manifeste vs run téléchargé" 'MANIFEST_RUN_ID' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: vérifie SHA256 sidecar vs manifeste" 'SIDECAR_SHA256' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: double vérification SHA avant deploy" 'Verify artifact identity before deploy' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: checkout SHA candidat dans job deploy" 'candidate_sha' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: valide conclusion success" "workflow_run.conclusion == 'success'" "${DEPLOY_WORKFLOW}"
assert_contains "deploy: valide branche = main" 'RUN_BRANCH' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: valide workflow = CI" 'RUN_WORKFLOW' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: refuse run expiré ou absent" 'Only successful runs may be promoted' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: refuse branch non-main" 'Only main branch runs may be promoted' "${DEPLOY_WORKFLOW}"
assert_contains "deploy: refuse SHA discordant" 'Refusing to promote' "${DEPLOY_WORKFLOW}"
assert_not_contains "deploy: plus de Composer install dans build job" 'Install dependencies (with dev for tests)' "${DEPLOY_WORKFLOW}"
assert_not_contains "deploy: plus de PHPUnit dans build job" 'vendor/bin/phpunit' "${DEPLOY_WORKFLOW}"
assert_not_contains "deploy: plus de packaging dans build job" 'package-deploy-artifact.sh' "${DEPLOY_WORKFLOW}"

if [ "${fail}" -ne 0 ]; then
    echo "ÉCHEC : tests du pipeline qualify/backup/RC échoués." >&2
    exit 1
fi

echo "TOUS LES TESTS qualify-release-candidate + deploy pipeline OK"
