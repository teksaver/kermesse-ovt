#!/usr/bin/env bash
# Nom unique de l'artefact de déploiement — SOURCE DE VÉRITÉ partagée.
# Producteur : scripts/package-deploy-artifact.sh.
# Consommateurs : scripts/transfer-archive.sh, scripts/deploy-rehearsal.sh.
#
# Écart documenté : les chemins YAML (.github/workflows/ci.yml et deploy-ouvaton.yml,
# via actions/upload-artifact|download-artifact) restent littéraux — une action GitHub
# ne peut pas sourcer un script bash. Centraliser ici couvre tout le code shell.
#
# Sourcé (pas exécuté) ; surchargeable par l'environnement.
KERMESSE_ARTIFACT_NAME="${KERMESSE_ARTIFACT_NAME:-kermesse-deploy.tar.gz}"
