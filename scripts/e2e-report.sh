#!/usr/bin/env bash
# Ouvre le rapport HTML Playwright dans le navigateur.
#
# Pas besoin de Node/npm sur l'hôte.
# Usage: bash scripts/e2e-report.sh [--port=<port>]
#
# Génère le rapport avec: bash scripts/e2e.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPORT_DIR="${PROJECT_ROOT}/playwright-report"
PORT=9323

for arg in "$@"; do
  case "${arg}" in
    --port=*) PORT="${arg#--port=}" ;;
  esac
done

if [ ! -f "${REPORT_DIR}/index.html" ]; then
  echo "Aucun rapport trouvé dans playwright-report/." >&2
  echo "Lance d'abord : bash scripts/e2e.sh" >&2
  exit 1
fi

echo "=== Rapport E2E disponible sur http://localhost:${PORT} ==="
echo "    (Ctrl+C pour arrêter)"

# Ouvre le navigateur après un court délai pour laisser le serveur démarrer.
(sleep 1 && open "http://localhost:${PORT}" 2>/dev/null || true) &

uv run python -m http.server "${PORT}" --directory "${REPORT_DIR}"
