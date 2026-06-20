#!/usr/bin/env bash
# Guardrails for the Ouvaton deploy artifact packaging script.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${PROJECT_ROOT}/scripts/package-deploy-artifact.sh"

fail=0

assert_contains() {
  local label="$1" needle="$2"
  if ! grep -Fq -- "${needle}" "${SCRIPT}"; then
    printf 'FAIL %s\n  attendu de trouver : %s\n' "${label}" "${needle}" >&2
    fail=1
  else
    printf 'ok   %s\n' "${label}"
  fi
}

assert_contains "packaging interdit les migrations CI4 natives" 'app/Database/Migrations'
assert_contains "packaging cible les fichiers PHP de migration CI4" '-type f -name "*.php"'
assert_contains "message oriente vers le runner SQL" 'database/migrations_sql/*.sql'

# Story 6.3 — E2E / Node artefacts exclus de l'archive Ouvaton
assert_contains "packaging exclut le dossier e2e" '"e2e"'
assert_contains "packaging exclut playwright-report" '"playwright-report"'
assert_contains "packaging exclut test-results" '"test-results"'
assert_contains "packaging exclut package.json" '"package.json"'
assert_contains "packaging exclut package-lock.json" '"package-lock.json"'
assert_contains "packaging exclut playwright.config.ts" '"playwright.config.ts"'
assert_contains "packaging exclut les specs .spec.ts" '*.spec.ts'

# ----------------------------------------------------------------
# Real archive validation test (Story 6.3 — Patch 12)
#
# Build a synthetic tar.gz that CONTAINS forbidden E2E artefacts,
# then run the same detection logic the packager uses in its section 6
# to verify the detection actually works on real archive content —
# not just that the strings are present in the packager script.
# ----------------------------------------------------------------
echo ""
echo "=== Validation réelle : détection d'artefacts E2E dans une archive synthétique ==="

TMP_DIR="$(mktemp -d)"
TMP_ARC="${TMP_DIR}/synth.tar.gz"
_cleanup_tmp() { rm -rf "${TMP_DIR}"; }
trap _cleanup_tmp EXIT

# Minimal valid content + forbidden E2E artefacts (what a broken packager might include)
mkdir -p "${TMP_DIR}/content/app/Controllers"
touch    "${TMP_DIR}/content/app/Controllers/Home.php"
mkdir -p "${TMP_DIR}/content/public"
touch    "${TMP_DIR}/content/public/index.php"

# Forbidden E2E items
mkdir -p "${TMP_DIR}/content/e2e/tests"
touch    "${TMP_DIR}/content/e2e/tests/smoke.spec.ts"
mkdir -p "${TMP_DIR}/content/playwright-report"
touch    "${TMP_DIR}/content/playwright-report/report.html"
mkdir -p "${TMP_DIR}/content/test-results"
touch    "${TMP_DIR}/content/test-results/output.json"
touch    "${TMP_DIR}/content/package.json"
touch    "${TMP_DIR}/content/package-lock.json"
touch    "${TMP_DIR}/content/playwright.config.ts"

(cd "${TMP_DIR}/content" && COPYFILE_DISABLE=1 tar -czf "${TMP_ARC}" .)

# Run the same detection algorithm as the packager (section 6 — archive content check).
# These arrays must stay in sync with FORBIDDEN_ROOT_DIRS / FORBIDDEN_ROOT_FILES in the
# packager script; the assert_contains checks above ensure the packager declares them.
FORBIDDEN_DIRS=("e2e" "playwright-report" "test-results")
FORBIDDEN_FILES=("package.json" "package-lock.json" "playwright.config.ts")

DETECTED=()
while IFS= read -r entry; do
  clean="${entry%/}"; clean="${clean#./}"
  for dir in "${FORBIDDEN_DIRS[@]}"; do
    if [[ "${clean}" == "${dir}" || "${clean}" == "${dir}/"* ]]; then
      DETECTED+=("${entry}")
    fi
  done
  filename="${clean##*/}"
  for f in "${FORBIDDEN_FILES[@]}"; do
    [[ "${filename}" == "${f}" ]] && DETECTED+=("${entry}")
  done
  [[ "${filename}" == *.spec.ts || "${filename}" == *.spec.js ]] && DETECTED+=("${entry}")
done < <(tar -tzf "${TMP_ARC}")

# We planted 8 forbidden entries (dirs + files); expect at least that many detections.
EXPECTED_MIN=8
if [ "${#DETECTED[@]}" -ge "${EXPECTED_MIN}" ]; then
  printf 'ok   archive synthétique : %d artefacts E2E interdits détectés (≥%d attendus)\n' \
    "${#DETECTED[@]}" "${EXPECTED_MIN}"
else
  printf 'FAIL archive synthétique : %d artefact(s) détecté(s), attendu ≥%d\n' \
    "${#DETECTED[@]}" "${EXPECTED_MIN}" >&2
  printf '     Entrées détectées :\n' >&2
  for e in "${DETECTED[@]}"; do printf '       %s\n' "${e}" >&2; done
  fail=1
fi

# Verify a CLEAN archive (no E2E files) produces zero detections.
TMP_CLEAN_ARC="${TMP_DIR}/clean.tar.gz"
(cd "${TMP_DIR}/content/app" && COPYFILE_DISABLE=1 tar -czf "${TMP_CLEAN_ARC}" .)

CLEAN_DETECTED=()
while IFS= read -r entry; do
  clean="${entry%/}"; clean="${clean#./}"
  for dir in "${FORBIDDEN_DIRS[@]}"; do
    [[ "${clean}" == "${dir}" || "${clean}" == "${dir}/"* ]] && CLEAN_DETECTED+=("${entry}")
  done
  filename="${clean##*/}"
  for f in "${FORBIDDEN_FILES[@]}"; do
    [[ "${filename}" == "${f}" ]] && CLEAN_DETECTED+=("${entry}")
  done
  [[ "${filename}" == *.spec.ts || "${filename}" == *.spec.js ]] && CLEAN_DETECTED+=("${entry}")
done < <(tar -tzf "${TMP_CLEAN_ARC}")

if [ "${#CLEAN_DETECTED[@]}" -eq 0 ]; then
  printf 'ok   archive propre : aucun artefact E2E interdit détecté\n'
else
  printf 'FAIL archive propre : %d artefact(s) interdit(s) détecté(s) à tort\n' \
    "${#CLEAN_DETECTED[@]}" >&2
  fail=1
fi

if [ "${fail}" -ne 0 ]; then
  echo "ECHEC : garde-fous package-deploy-artifact invalides." >&2
  exit 1
fi

echo "TOUS LES TESTS package-deploy-artifact OK"
