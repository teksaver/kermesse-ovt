---
title: 'Activation autonome et éphémère sur Ouvaton sans dépendance CodeIgniter'
type: 'refactor'
created: '2026-06-13'
status: 'done'
baseline_commit: ''
context:
  - '{project-root}/claude.md'
  - '{project-root}/docs/deployment-ouvaton.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-fix-cicd-deployments.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Le webhook d'activation `/ops/activate` de CodeIgniter requiert que l'application soit déjà installée et fonctionnelle. Si le serveur de production Ouvaton est vidé pour initialisation, ou si la release active contient un bug fatal (comme un appel de fonction `exec()` interdite ou une erreur de syntaxe), l'appel de webhook échoue. Il y a une dépendance circulaire qui empêche le premier déploiement ou la récupération après une erreur fatale.

**Approach:** Rendre l'étape d'activation 100% robuste en s'affranchissant du framework CodeIgniter pour cette phase. Durant le déploiement CI/CD, nous allons :
1. Générer un jeton temporaire à usage unique.
2. Injecter ce jeton dans un script PHP d'activation autonome `ops-bootstrap-activate.php` (qui utilise `PharData` pour l'extraction).
3. Téléverser ce script dans le dossier public `httpdocs/` lors de l'envoi du shim `index.php`.
4. Appeler ce script via HTTP (avec le jeton) pour réaliser l'extraction, la validation de la release et la bascule atomique.
5. Supprimer immédiatement le script `ops-bootstrap-activate.php` du serveur via SFTP.

## Boundaries & Constraints

**Always:**
- Utiliser un token temporaire fort à usage unique de 32 octets généré dynamiquement à chaque déploiement.
- Valider la présence de `app/`, `vendor/` et `public/` dans l'archive extraite avant d'effectuer la bascule.
- Supprimer immédiatement le script d'activation autonome à la fin du workflow (en cas de succès comme en cas d'échec) pour éviter qu'il ne reste accessible publiquement.
- Respecter le paramètre `kermesse.releasesRetention` en lisant `shared/.env` (ou fallback sur 3) pour nettoyer les anciennes releases de façon pérenne.

**Ask First:**
- S'assurer que le workflow n'interfère pas avec d'autres tâches en cours en CI.

**Never:**
- Ne pas laisser de fichiers d'activation non protégés ou persistants sur le serveur web root.
- Ne pas réintroduire d'appels à `exec()`, `shell_exec()`, ou d'autres fonctions système bloquées par Ouvaton.

</frozen-after-approval>

## Code Map

- `deploy/ops-bootstrap-activate.tpl.php` -- [NEW] Template PHP autonome pour l'activation.
- `.github/workflows/deploy-ouvaton.yml` -- [MODIFY] Orchestrateur GitHub Actions pour intégrer l'activation autonome et la suppression du fichier éphémère.
- `scripts/deploy-httpdocs.sh` -- [MODIFY] Ajustement pour autoriser l'envoi du script temporaire si nécessaire, ou utilisation d'une commande lftp séparée dans le workflow.
- `app/Services/ReleaseActivationService.php` -- [MODIFY] Conserver et s'assurer que la méthode `extractArchive` utilise `PharData` (déjà fait localement) pour maintenir les tests au vert.

## Tasks & Acceptance

**Execution:**
- [x] Créer `deploy/ops-bootstrap-activate.tpl.php` avec la logique d'extraction autonome validée par token et nettoyage des anciennes releases.
- [x] Modifier `.github/workflows/deploy-ouvaton.yml` pour générer le script avec son token, le pousser, l'appeler puis le détruire.
- [x] Adapter les scripts/workflows de manière à ce qu'une erreur de décompression interrompe le déploiement proprement.
- [x] Tester que la suite de tests PHPUnit locale est toujours verte.

**Acceptance Criteria:**
- Given un serveur Ouvaton vide (hors `shared/.env`), when le job de déploiement s'exécute, then il déploie et active la première release sans erreur.
- Given une release active contenant une erreur PHP fatale, when un nouveau déploiement est lancé, then le correctif est déployé et activé avec succès.
- Given le déploiement se termine (succès ou échec), when le workflow s'achève, then le fichier `ops-bootstrap-activate.php` est supprimé du serveur Ouvaton.

## Spec Change Log

- 2026-06-26 -- Mini-audit reviewer : bootstrap autonome livre et verifie via `deploy/ops-bootstrap-activate.tpl.php`, `.github/workflows/deploy-ouvaton.yml`, `scripts/deploy-httpdocs.sh`, `tests/shell/ops-bootstrap-activate.test.sh` et `tests/shell/deploy-ouvaton-workflow.test.sh`. Statut passe a `done`.

## Design Notes

Le script autonome utilise `PharData` natif, disponible sur Ouvaton. Il lit `shared/.env` de manière autonome avec une expression régulière simple pour extraire la configuration de rétention des releases, limitant les dépendances systèmes au strict minimum.

## Verification

**Commands:**
- `vendor/bin/phpunit` -- expected: suite de tests au vert.
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/deploy-ouvaton.yml")'` -- expected: YAML valide.
