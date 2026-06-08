#!/usr/bin/env bash
# Helper partagé de signature HMAC-SHA256 pour les webhooks ops signés
# (/ops/activate, /ops/migrate, /ops/migrate/status). SOURCE DE VÉRITÉ UNIQUE du
# format de payload imposé par OpsAuthFilter :
#   timestamp \n nonce \n POST \n routePath \n sha256(body)
#
# Sourcé par :
#   - scripts/deploy-rehearsal.sh           (répétition locale + job CI deploy-rehearsal)
#   - .github/workflows/deploy-ouvaton.yml  (déploiement de production)
# pour garantir que la signature ne dérive JAMAIS entre local, CI et prod (FR-18).
#
# Dépendances : openssl, awk, date. Lit OPS_HMAC_SECRET dans l'environnement.
# NB : ce fichier est SOURCÉ (pas exécuté). Il ne pose pas son propre `set -e` :
# l'appelant gère son mode d'erreur ; les fonctions renvoient un code non-zéro sur échec.

# ops_sign <route> [body]
# Calcule la signature et expose les variables globales SIGN_TS, SIGN_NONCE, SIGN_SIG.
# Body par défaut : '{}'.
# NE PAS écrire `${2:-{}}` : bash (3.2 ET 5.x) referme l'expansion sur le 1er `}` de
# la valeur par défaut puis ajoute un `}` littéral → le sha256 signé diffère du body
# réellement envoyé → signature rejetée (ops_unauthorized). Variable intermédiaire = sûr.
ops_sign() {
  local route="$1"
  local _body_default='{}'
  local body="${2:-${_body_default}}"

  if [ -z "${OPS_HMAC_SECRET:-}" ]; then
    echo "ERREUR (ops_sign) : OPS_HMAC_SECRET est requis pour signer les requêtes ops." >&2
    return 1
  fi

  SIGN_TS="$(date +%s)"
  SIGN_NONCE="$(openssl rand -hex 16)"
  local body_hash payload
  body_hash="$(printf '%s' "${body}" | openssl dgst -sha256 | awk '{print $NF}')"
  payload="$(printf '%s\n%s\nPOST\n%s\n%s' "${SIGN_TS}" "${SIGN_NONCE}" "${route}" "${body_hash}")"
  SIGN_SIG="$(printf '%s' "${payload}" | openssl dgst -sha256 -hmac "${OPS_HMAC_SECRET}" | awk '{print $NF}')"
}

# ops_require_https_endpoint <base_url> <min_secret_len>
# Pré-vol de PRODUCTION : mutualise la validation jusqu'ici dupliquée entre les étapes
# « activate » et « migrate » de deploy-ouvaton.yml. Impose https:// et une longueur
# minimale du secret HMAC. Renvoie non-zéro si une condition échoue (fail-fast appelant).
ops_require_https_endpoint() {
  local base_url="$1"
  local min_secret_len="$2"

  if ! printf '%s' "${base_url}" | grep -iq '^https://'; then
    echo "::error::BASE_URL doit commencer par https:// pour les opérations de production." >&2
    return 1
  fi
  if [ "${#OPS_HMAC_SECRET}" -lt "${min_secret_len}" ]; then
    echo "::error::OPS_HMAC_SECRET doit faire au moins ${min_secret_len} caractères." >&2
    return 1
  fi
}
