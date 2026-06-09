#!/usr/bin/env bash
# Helper partagé d'échappement lftp — SOURCE DE VÉRITÉ UNIQUE pour insérer une valeur
# (identifiant, mot de passe) DANS une chaîne entre quotes SIMPLES d'un script lftp, ex :
#   open -u 'USER','PASS' -p PORT sftp://HOST
#
# Sourcé par :
#   - scripts/transfer-archive.sh   (transfert de production + rehearsal)
#   - scripts/deploy-rehearsal.sh   (injections post-transfert)
# afin que l'échappement ne dérive JAMAIS entre les deux (cf. doublons signalés en review).
#
# Hors périmètre : .github/workflows/sync-production-env.yml utilise un échappement
# DOUBLE-quote distinct (lftp_quote) et ne checkout pas le dépôt → il ne peut pas sourcer
# ce fichier. L'échappement de TARGET_KEY (double imbrication via ssh -i '...') reste
# spécifique à chaque appelant et n'est pas couvert ici.
#
# Ce fichier est SOURCÉ (pas exécuté) ; il ne pose pas son propre `set -e`.

# lftp_squote <valeur>
# Échappe une valeur destinée à être insérée entre quotes simples dans une commande lftp :
#   backslash -> \\   et   quote simple -> \'
#
# Implémentation caractère par caractère VOLONTAIRE : le remplacement de motif
# ${v//\'/\\\'} produit un résultat DIFFÉRENT selon la version de bash (3.2 ne réduit pas
# \\ -> \ dans la chaîne de remplacement, contrairement à bash >= 4). transfer-archive.sh
# tourne en bash 5 (conteneur/runner) mais deploy-rehearsal.sh est lancé depuis l'hôte
# macOS en bash 3.2 : la boucle garantit un échappement identique partout.
lftp_squote() {
  local value="${1:-}" out="" i char
  for (( i = 0; i < ${#value}; i++ )); do
    char="${value:i:1}"
    case "${char}" in
      '\') out+='\\' ;;
      "'") out+="\\'" ;;
      *)   out+="${char}" ;;
    esac
  done
  printf '%s' "${out}"
}
