---
title: 'Corriger le déploiement CI/CD Ouvaton'
type: 'bugfix'
created: '2026-06-12'
status: 'done'
baseline_commit: '3102d5a90749185c2b57ebfbbac10269930001ef'
context:
  - '{project-root}/claude.md'
  - '{project-root}/docs/deployment-ouvaton.md'
  - '{project-root}/_bmad-output/implementation-artifacts/5-1-bascule-de-deploy-ouvaton-yml-vers-archive-webhooks.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Les déploiements Ouvaton échouent, et le dernier correctif sur `deploy-ouvaton.yml` contourne l'échec avec `set cmd:fail-exit no` autour de la création des dossiers `shared/writable`, ce qui masque aussi des erreurs réelles de chemin, permissions ou connexion. Le workflow contient aussi une génération inline fragile du shim `httpdocs/index.php` et des chemins applicatifs codés directement dans le YAML.

**Approach:** Restaurer un comportement fail-fast en supprimant le fallback silencieux, puis factoriser la génération du shim et la création idempotente des dossiers distants dans des scripts versionnés et testables. Le workflow doit rester conforme à l'architecture archive + webhooks : upload de l'archive par `put`, activation `/ops/activate`, migration `/ops/migrate`, jamais de `.env` généré ou transféré.

## Boundaries & Constraints

**Always:** Conserver `deploy-ouvaton.yml` comme workflow unique de déploiement production. Préserver le `.env` de production dans `shared/`. Vérifier la clé hôte SFTP via `OUVATON_SFTP_KNOWN_HOST`. Tout échec de transfert, création de dossier, upload shim/assets, activation ou migration doit rendre le job rouge. Les chemins Ouvaton doivent venir des variables `OUVATON_DEPLOY_REMOTE_FOLDER` et `OUVATON_HTTPDOCS_FOLDER`.

**Ask First:** Demander validation avant de changer le protocole SFTP, les noms des variables/secrets GitHub, le modèle `shared/current/releases/staging`, ou l'ordre `upload archive → httpdocs → activate → migrate`.

**Never:** Ne pas réintroduire `mirror` pour l'application. Ne pas générer, transférer ou archiver un vrai `.env`. Ne pas utiliser `cmd:fail-exit no` comme garde large. Ne pas hard-coder `kermesse`, `httpdocs`, `/tmp/httpdocs-index.php` ou un chemin filesystem Ouvaton dans la logique métier du workflow.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Dossiers absents | `shared/writable/*` n'existe pas encore | Les dossiers requis sont créés puis le shim peut résoudre `shared/writable` | Echec immédiat si un mkdir ou cd distant échoue |
| Dossiers déjà présents | `shared/writable/*` existe déjà | Le déploiement continue sans erreur parasite | Aucun fallback global silencieux |
| Chemin distant invalide | `OUVATON_DEPLOY_REMOTE_FOLDER` ou `OUVATON_HTTPDOCS_FOLDER` faux | Le job échoue avant activation | Message GitHub Actions explicite |
| Upload archive OK mais shim/assets KO | Archive déposée, upload httpdocs échoue | Pas d'activation ni migration | Job rouge, erreur visible |

</frozen-after-approval>

## Code Map

- `.github/workflows/deploy-ouvaton.yml` -- orchestration GitHub Actions production ; doit rester mince, sans logique fragile inline.
- `scripts/transfer-archive.sh` -- transfert archive validé par répétition ; à préserver.
- `scripts/lib/lftp-escape.sh` -- échappement lftp existant ; à réutiliser.
- `scripts/lib/ops-sign.sh` -- signature HMAC partagée ; à préserver.
- `docs/deployment-ouvaton.md` -- source opérationnelle des chemins et invariants Ouvaton.
- `docker/deploy-web/entrypoint.sh` -- référence locale pour le shim rehearsal ; ne pas modifier sauf nécessité prouvée.

## Tasks & Acceptance

**Execution:**
- [x] `.github/workflows/deploy-ouvaton.yml` -- supprimer le bloc `set cmd:fail-exit no` ajouté autour des `mkdir` et remplacer la logique inline fragile par un appel à un script versionné fail-fast.
- [x] `scripts/deploy-httpdocs.sh` -- créer un script idempotent qui valide ses variables, génère le shim dans un fichier temporaire sûr, crée les dossiers `shared/writable/{cache,logs,session,uploads}` sans masquer les erreurs réelles, puis upload `index.php`, `.htaccess`, `robots.txt` et `assets/`.
- [x] `scripts/deploy-httpdocs.sh` -- refuser les chemins vides, absolus, contenant `..`, quotes ou caractères hors whitelist ; utiliser `scripts/lib/lftp-escape.sh`.
- [x] `scripts/deploy-httpdocs.sh` -- échouer explicitement si `.htaccess` manque ou si `deploy-staging/public` n'a pas été extrait.
- [x] `tests/shell/deploy-httpdocs.test.sh` -- ajouter des tests shell pour validation des chemins, absence de `cmd:fail-exit no`, génération du shim, et refus des chemins invalides.
- [x] `docs/deployment-ouvaton.md` -- aligner la documentation si le point d'entrée httpdocs est déplacé dans un script dédié.

**Acceptance Criteria:**
- Given les dossiers `shared/writable` existent déjà, when le job déploie, then il continue sans désactiver globalement `cmd:fail-exit`.
- Given un chemin distant invalide ou non autorisé, when le script démarre, then il échoue avant toute connexion lftp.
- Given un upload httpdocs échoue, when le workflow s'exécute, then `/ops/activate` et `/ops/migrate` ne sont pas appelés.
- Given l'artefact de déploiement est produit, when le workflow s'exécute, then aucun `.env` n'est généré, uploadé ou inclus.

## Spec Change Log

- 2026-06-26 -- Mini-audit reviewer : implementation presente et verifiee dans `scripts/deploy-httpdocs.sh`, `.github/workflows/deploy-ouvaton.yml`, `tests/shell/deploy-httpdocs.test.sh` et `docs/deployment-ouvaton.md`. Statut passe a `done`.

## Design Notes

Le bug vient d'un correctif trop large : `cmd:fail-exit no` rend idempotent un `mkdir`, mais il masque aussi une cible absente, une permission refusée ou une mauvaise racine. La correction doit traiter l'idempotence localement : soit par commandes lftp qui ne désactivent pas fail-fast, soit par vérification ciblée post-création. Le workflow ne doit plus contenir un long script PHP/lftp inline ; ce code est difficile à tester et a déjà divergé des contraintes.

## Verification

**Commands:**
- `bash tests/shell/deploy-httpdocs.test.sh` -- expected: tests shell verts.
- `bash scripts/package-deploy-artifact.sh` -- expected: archive + checksum produits, sans `.env`.
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/deploy-ouvaton.yml")'` -- expected: YAML valide.
- `git diff --check` -- expected: aucun problème de whitespace.
