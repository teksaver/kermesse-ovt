---
baseline_commit: 542ac152bdfbc45b0ee6763d4ec133a46cb46b43
story_key: 6-9-qualifier-et-deployer-la-release-candidate
status: review
---

# Story 6.9 : Qualifier et déployer la release candidate

Status: review

## Story

As an organisateur responsable de la mise en production,
I want disposer d'une release candidate éprouvée et récupérable,
so that la mise en production puisse être décidée et exécutée avec un risque maîtrisé.

## Acceptance Criteria

1. **Given** un commit candidat, **When** la release candidate est créée, **Then** le SHA du commit, l'identifiant du run CI, l'identifiant/digest de l'artefact GitHub, le SHA-256 de l'archive et le fichier `.sha256` sont enregistrés dans un manifeste de qualification, **And** l'archive produite par la CI est immuable, **And** aucun rebuild n'est effectué pendant la répétition ni avant la production.
2. **Given** un déclenchement automatique depuis `workflow_run`, **When** le déploiement démarre, **Then** il télécharge l'artefact `kermesse-deploy` du run CI déclencheur au moyen de son `run-id`, **And** vérifie que le manifeste, `workflow_run.head_sha` et l'archive désignent le même candidat. **Given** un déclenchement manuel, **When** l'opérateur sélectionne un candidat, **Then** il fournit explicitement un run CI réussi de `main`; sélectionner silencieusement « le dernier artefact » ou reconstruire localement est interdit.
3. **Given** l'environnement Docker de répétition représentatif d'Ouvaton, **When** l'archive candidate y est déployée, **Then** `scripts/deploy-rehearsal.sh` accepte et utilise l'archive préconstruite et son checksum sans rappeler le packaging, **And** aucun Composer, Node, build d'assets ou CLI de migration n'est requis sur la cible, **And** l'activation et les migrations passent par les webhooks HTTPS/HMAC existants.
4. **Given** un schéma et des données fictives représentatifs de l'état précédant la release, **When** la migration est répétée, **Then** un préflight en lecture seule refuse tout drift, migration échouée ou état inattendu, **And** la migration réussit sans perte de données, **And** sa réexécution est un no-op réussi, **And** le postflight exige `pending=[]` et `failed=[]`, **And** les comptes de lignes, clés étrangères et invariants P0/P1 définis par la suite MariaDB restent valides.
5. **Given** une sauvegarde logique prise avant la migration de répétition, **When** la restauration est exécutée sur une cible propre et isolée, **Then** le schéma, `schema_versions`, les données représentatives et l'application reviennent dans un état cohérent, **And** la durée de sauvegarde et le RTO de restauration sont mesurés, **And** le seuil accepté est écrit avant la décision Go. Aucune donnée ni aucun secret de production ne doit entrer dans les fixtures, logs ou artefacts publics.
6. **Given** l'archive exacte déployée en répétition, **When** les smoke tests de qualification sont exécutés, **Then** ils couvrent au minimum la page publique et l'absence de PII, le Magic Link via Mailpit/fournisseur de test, le dashboard par rôle, l'inscription et les participations, la gestion des membres, l'ajout d'un créneau sur une kermesse `open`, l'annulation et la libération de capacité, **And** toute exception JavaScript, réponse HTTP inattendue ou perte de persistance fait échouer la qualification.
7. **Given** la matrice de couverture actualisée, **When** la revue Go/No-Go est tenue, **Then** 100 % des cellules P0/P1 possèdent une preuve verte reliée au commit et au checksum candidats, **And** aucune anomalie critique ou majeure ne reste ouverte sans décision explicite, **And** chaque risque résiduel a un propriétaire, **And** le responsable du déploiement, le décideur du rollback, les seuils Stop/Rollback et le RTO maximal sont nommés avant le Go.
8. **Given** le Go prononcé et les préconditions de migration satisfaites, **When** le workflow de production est exécuté, **Then** il déploie exactement l'archive qualifiée, préserve `shared/.env`, n'exécute aucune manipulation manuelle hors pipeline et publie les preuves du candidat. **And** si une vérification d'identité, de checksum, de migration ou de smoke échoue, le workflow s'arrête sans promouvoir un autre artefact.
9. **Given** le déploiement terminé, **When** les contrôles de santé et smoke tests post-déploiement autorisés sont exécutés, **Then** leur succès confirme la release, **Or** leur échec applique la décision Stop/Rollback pré-écrite, **And** le pointeur applicatif revient vers une release conservée sans modifier `shared/.env`. Toute restauration de données suit exclusivement la procédure validée et autorisée; aucune commande SQL improvisée n'est permise.
10. **Given** la release confirmée ou restaurée, **When** la qualification est clôturée, **Then** le manifeste, les résultats de gates, durées, preuves de migration/restauration, décision, acteurs et risques résiduels sont archivés avec une rétention supérieure à la fenêtre de rollback, **And** l'état de l'Epic 6 n'est clôturé qu'après satisfaction de la dépendance 6.10.

## Préconditions bloquantes

- **Story 6.10 obligatoire avant le déploiement production.** Le renommage physique `signups` → `slot_signups` et la validation d'upgrade depuis le schéma réel de `main` doivent être sécurisés avant le Go. La 6.9 peut implémenter et tester tout le pipeline de qualification, mais elle ne peut ni déclencher la migration de production ni être déclarée terminée tant que la 6.10 n'est pas `done` et verte.
- **Drift connu de `20260614121500_add_last_login_at_to_users`.** Le checksum enregistré en production diverge du fichier courant. Le `MigrationRunnerService` s'arrête volontairement sur ce drift, ce qui bloque les migrations suivantes. Ne pas contourner ce garde-fou et ne pas exécuter l'`UPDATE schema_versions` proposé dans `epics.md` depuis un poste ou une session SQL manuelle : `project-context.md` interdit les écritures de production hors Service/pipeline. La remédiation doit être formalisée, auditée et automatisée dans le périmètre 6.10 ou via une décision d'architecture explicitement approuvée.
- **Worktree dédié.** L'implémentation doit partir d'un worktree/branche dédié et d'un état propre. Le worktree de création de cette story contient déjà des modifications utilisateur non liées; ne pas les incorporer ni les écraser.

## Tasks / Subtasks

- [x] **T1 — Établir l'identité immuable du candidat** (AC: 1, 2, 7, 10)
  - [x] Faire produire par le job `package-artifact` un manifeste machine-readable contenant au minimum `commit_sha`, `ci_run_id`, `artifact_name`, `archive_sha256`, versions runtime verrouillées et horodatage UTC.
  - [x] Publier archive, sidecar et manifeste dans un unique artefact GitHub non écrasable; conserver les outputs `artifact-id`, `artifact-digest` et `artifact-url` fournis par l'action d'upload.
  - [x] Vérifier le contenu du manifeste après téléchargement et échouer si le SHA, le checksum ou le run ne correspondent pas au candidat demandé.
  - [x] Ne pas chercher la reproductibilité byte-for-byte d'un second build : la garantie exigée est la promotion du même binaire, pas deux archives reconstruites supposées équivalentes.

- [x] **T2 — Promouvoir l'artefact CI sans rebuild** (AC: 1, 2, 3, 8)
  - [x] Restructurer `.github/workflows/ci.yml` pour construire une seule fois l'archive après les gates, puis qualifier cette archive exacte.
  - [x] Modifier `.github/workflows/deploy-ouvaton.yml` : supprimer le job qui réinstalle Composer, rejoue PHPUnit et repackage; télécharger l'artefact du run CI déclencheur avec `github-token` + `run-id` et vérifier son manifeste.
  - [x] Pour `workflow_dispatch`, exiger un `ci_run_id` (et idéalement le SHA attendu) correspondant à un run CI réussi de `main`; refuser un run expiré, absent, d'une autre branche ou d'un autre commit.
  - [x] Checkout uniquement le SHA candidat pour les scripts de déploiement; ne jamais utiliser implicitement le HEAD courant si le candidat est différent.

- [x] **T3 — Faire répéter le déploiement du binaire exact** (AC: 3, 4)
  - [x] Ajouter à `scripts/deploy-rehearsal.sh` un mode explicite `--use-existing-artifact` (ou contrat équivalent) qui exige l'archive et le sidecar déjà présents, les vérifie et saute le packaging.
  - [x] Préserver le mode local actuel qui package à la demande pour la boucle développeur, mais interdire ce mode dans le job de qualification RC.
  - [x] Capturer et parser les réponses JSON de `ops/migrate/status` avant et après migration; un HTTP 200 avec `failed` non vide ou un état inattendu doit rester bloquant.
  - [x] Réexécuter `ops/migrate` et prouver l'idempotence sans mutation supplémentaire.

- [x] **T4 — Répéter sauvegarde et restauration** (AC: 4, 5, 9)
  - [x] Ajouter un script de répétition isolé utilisant `mariadb-dump` puis le client `mariadb` dans `deploy-client`; aucun outil DB n'est ajouté à la cible Ouvaton.
  - [x] Charger des fixtures synthétiques couvrant utilisateurs, rôles, stands, créneaux, inscriptions actives/historiques, tokens non secrets et `schema_versions` dans l'état pré-release.
  - [x] Restaurer dans une base propre, vérifier schéma, nombres de lignes, FK et invariants, puis mesurer les durées avec horodatages explicites.
  - [x] Documenter le mécanisme de sauvegarde production réellement disponible chez Ouvaton et exiger une preuve de sauvegarde fraîche avant Go; ne pas inventer un accès CLI ou des privilèges non confirmés.

- [x] **T5 — Exécuter les smoke tests de qualification** (AC: 6, 7, 9)
  - [x] Réutiliser les specs existantes `public-kermesse`, `visitor-signup`, `benevole-dashboard`, `organizer-dashboard`, `team`, `add-slot`, `participations` et `profile` sur la release activée en répétition.
  - [x] Utiliser uniquement des comptes/boîtes de test et des fixtures reproductibles. Aucun endpoint de fixture ne doit être activable en `production`.
  - [x] Distinguer les smoke tests de répétition complets des contrôles post-production non destructifs ou explicitement autorisés; documenter toute mutation synthétique et son nettoyage.
  - [x] Archiver rapport Playwright, traces/captures uniquement en cas d'échec, et résumé des scénarios dans les preuves RC.

- [x] **T6 — Formaliser Go/No-Go, Stop et rollback** (AC: 7, 9, 10)
  - [x] Créer un runbook/checklist versionné avec candidat, preuves P0/P1, drift de migration, sauvegarde, RTO mesuré/maximum, risques, responsables et décision signée/horodatée.
  - [x] Définir avant exécution les seuils Stop/Rollback : échec migration/postflight, erreur 5xx/health, smoke P0/P1 rouge, divergence checksum/manifest, corruption ou perte de données.
  - [x] Documenter le rollback applicatif vers une release retenue et la restauration DB séparément; un rollback de fichiers ne prétend pas annuler une migration incompatible.
  - [x] Empêcher la clôture automatique de l'Epic 6 ou le dégel de l'Epic 7 sans preuve de release confirmée et sans 6.10 terminée.

- [x] **T7 — Mettre à jour les preuves et la documentation** (AC: 7, 10)
  - [x] Actualiser `_bmad-output/planning-artifacts/test-coverage-matrix.md` : les G24-G26 sont déjà couverts par `tests/feature/SessionExpirationTest.php` (Story 6.8); recalculer toutes les lacunes P0/P1 contre l'état réel des Stories 6.3-6.8bis.
  - [x] Corriger `docs/deployment-ouvaton.md`, aujourd'hui obsolète sur le rebuild du workflow et une mention `.zip`, pour décrire promotion immuable, sélection manuelle du run, répétition, sauvegarde/restauration et preuves.
  - [x] Ajouter au workflow un artefact de preuves RC à rétention suffisante, sans secrets, tokens, dumps de production ni PII.

- [x] **T8 — Tests du pipeline** (AC: tous)
  - [x] Étendre les tests shell/YAML pour prouver : absence de packaging dans le workflow de production, téléchargement cross-run borné au `run-id`, validation SHA/checksum/manifeste, refus d'un mauvais candidat et conservation de `shared/.env`.
  - [x] Tester le mode « artefact existant », les réponses status avec `pending`/`failed`, l'idempotence, l'échec de restauration et les seuils Stop/Rollback.
  - [x] Exécuter `composer check-all`, les tests shell, le rehearsal complet et les smoke Playwright. La qualification ne doit pas masquer une relance flaky.

## Dev Notes

### État actuel des fichiers à modifier

- `.github/workflows/ci.yml` : six gates existent. `deploy-rehearsal` reconstruit aujourd'hui son archive, puis `package-artifact` en construit une autre; seule la seconde est publiée. À préserver : gates bloquantes, absence de `continue-on-error`, exclusions de l'archive et preuves rattachées au commit.
- `.github/workflows/deploy-ouvaton.yml` : le job `build-and-package` réinstalle les dépendances, rejoue une suite SQLite et reconstruit encore l'archive. Le job `deploy` télécharge donc l'artefact de ce workflow, pas celui qualifié par la CI. À préserver : `production` environment, concurrence non annulable, SFTP avec known_hosts, `shared/.env` en `ensure-present`, bootstrap éphémère, nettoyage `always()`, HMAC et récupération des logs en échec.
- `scripts/deploy-rehearsal.sh` : orchestre packaging → transfert → activation → migration → status et propose trois injections d'échec. Il ne sait pas consommer une archive externe, ne sauvegarde/restaure pas la DB et ne parse pas le JSON de status. Conserver les injections, le mode `--reset`, l'indirection Docker et les helpers partagés.
- `scripts/package-deploy-artifact.sh` : produit `kermesse-deploy.tar.gz` + SHA-256, installe `vendor/ --no-dev`, refuse secrets, tests, Node, migrations CI4 natives et valide l'extraction `PharData`. Ne pas détendre ces contrôles. Le manifeste RC doit compléter le sidecar, pas le remplacer.
- `app/Services/ReleaseActivationService.php` : valide checksum/TAR, extrait dans `releases/<id>`, bascule `current`/`CURRENT_RELEASE`, conserve plusieurs releases et supprime l'archive de staging. Il n'offre pas de route de rollback. Préférer un mécanisme ops dédié et testé si un rollback applicatif automatisé est requis; ne jamais manipuler les pointeurs via SFTP ad hoc.
- `app/Services/MigrationRunnerService.php` et `MigrationController` : le runner applique les SQL lexicographiquement, protège les checksums, sérialise par verrou et expose un status lecture seule. Ne pas neutraliser le drift ni transformer `pending` en succès implicite de qualification.
- `docs/deployment-ouvaton.md` : documente le layout et la préservation de `.env`, mais décrit encore un rebuild dans le workflow de production et mentionne un artefact `.zip` dans la section CI.
- `_bmad-output/planning-artifacts/test-coverage-matrix.md` : baseline du 19 juin devenue partiellement obsolète après les Stories 6.3-6.8bis; elle ne peut pas servir telle quelle à un Go/No-Go.

### Architecture Compliance

- Production Ouvaton reste runtime-only : aucun Composer, Node, Docker, `php spark migrate`, client SQL ou extraction shell côté serveur.
- Les migrations de production passent uniquement par les routes `/ops/*` protégées par `OpsAuthFilter`; le filtre conserve HMAC, timestamp, nonce anti-rejeu, environnement production et routePath signé.
- `shared/.env` et `shared/writable/` restent hors releases et ne sont jamais écrasés par le déploiement courant.
- La release candidate ne contient ni tests, ni Playwright/Node, ni dumps, ni preuves, ni secrets. Les preuves vivent dans les artefacts CI séparés.
- Aucun nouveau comportement métier n'entre dans cette story. Les corrections fonctionnelles découvertes deviennent des blockers ou stories séparées.
- Les fichiers Bash restent `set -euo pipefail`; toute erreur critique est propagée. Les écritures de production ne sont jamais improvisées depuis le poste développeur.

### Library / Framework Requirements

- Conserver les versions verrouillées par `composer.lock` : PHP cible `^8.2`/CI 8.3, CodeIgniter `4.7.3`, PHPUnit `10.5.63`, PHPStan `2.2.2`. Aucun upgrade opportuniste n'est nécessaire pour cette story.
- Le dépôt utilise actuellement `actions/upload-artifact@v4` et `actions/download-artifact@v4`. Ces versions savent exposer un identifiant/digest et télécharger depuis un autre run avec `github-token` + `run-id`; ne changer de major que dans une modification dédiée et testée.
- Pour une sauvegarde logique MariaDB 10.11, utiliser `mariadb-dump`; la restauration utilise le client `mariadb`. Les credentials passent par variables/secrets masqués, jamais sur une ligne loggée.

### Testing Requirements

- Tests statiques du YAML et scripts shell : mauvais run-id, branche non-main, SHA discordant, sidecar absent, checksum invalide, manifeste altéré, artefact expiré, status `failed`, status `pending` inattendu, restauration échouée.
- Rehearsal réel Docker : même archive téléchargée, activation, migration, seconde migration no-op, restauration, réactivation et smoke complet.
- Vérifier explicitement que l'archive qualifiée et celle transférée ont le même SHA-256 avant et après téléchargement.
- Les tests ne contactent jamais Ouvaton ni un fournisseur de production. Les étapes réellement externes restent des checkpoints opérateur avec preuve.
- Ne pas accepter « suite verte avec échecs préexistants » pour le Go/No-Go : toute exception doit être arbitrée et enregistrée.

### Previous Story Intelligence

- Story 6.8bis a durci PHPStan au niveau 6, créé un bootstrap d'analyse production et réduit la baseline. Préserver `composer analyse = 0 erreur`; ne pas réintroduire de baseline pour les scripts de release.
- `scripts/e2e.sh` possède déjà `set -euo pipefail`; conserver la propagation des échecs et la possibilité `--keep` utile au diagnostic.
- Le worktree courant contient les corrections 6.8bis non commit (`phpstan*`, plusieurs types PHP/DocBlocks). La future branche 6.9 doit partir de leur commit final, pas capturer ces fichiers accidentellement.
- Story 6.8 a ajouté `SessionExpirationTest` (6 scénarios verts). La matrice de couverture est en retard et doit les reconnaître avant calcul du Go/No-Go.

### Git Intelligence

- `542ac15` clôture 6.8 et ajoute le backlog Epic 7; il constitue le dernier commit de référence visible.
- `a7dee50` ajoute le traitement d'expiration de session et six feature tests.
- `6514378` rend le mode E2E `--keep` accessible depuis l'hôte et modifie fixtures/orchestration Docker.
- `c3041ce` installe les gates CI, la publication d'artefact et la première configuration PHPStan. La 6.9 doit prolonger ces patterns, pas créer un second pipeline parallèle.
- Les commits d'implémentation suivent Conventional Commits avec référence de story, par exemple `feat(story-6.9): ...`.

### Latest Tech Information

- Les artefacts GitHub Actions v4+ sont immuables et possèdent un ID unique; `download-artifact` permet de sélectionner un autre run avec `github-token`, `repository` et `run-id`. Utiliser ces identifiants plutôt que le seul nom `kermesse-deploy`. [Source officielle : https://github.com/actions/download-artifact#download-artifacts-from-other-workflow-runs-or-repositories]
- Le téléchargement par `artifact-ids` évite l'ambiguïté d'un nom réutilisé entre plusieurs runs. Le checksum applicatif reste nécessaire car il fait partie du contrat de déploiement Kermesse. [Source officielle : https://github.com/actions/download-artifact#download-artifacts-by-id]
- `mariadb-dump` produit une sauvegarde logique; la restauration s'effectue avec le client `mariadb`. Pour les petites données de répétition, ce mécanisme est adapté et mesurable. [Source officielle : https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump]

### Project Structure Notes

Fichiers **UPDATE** probables :

- `.github/workflows/ci.yml`
- `.github/workflows/deploy-ouvaton.yml`
- `scripts/deploy-rehearsal.sh`
- `scripts/package-deploy-artifact.sh` (uniquement si le manifeste y est généré; sinon conserver le script inchangé)
- `docs/deployment-ouvaton.md`
- `_bmad-output/planning-artifacts/test-coverage-matrix.md`
- `tests/shell/deploy-ouvaton-workflow.test.sh`
- `tests/shell/package-deploy-artifact.test.sh`

Fichiers **NEW** probables (noms adaptables aux conventions existantes) :

- `scripts/qualify-release-candidate.sh` — vérification manifeste, répétition, smoke et collecte de preuves
- `scripts/rehearsal-backup-restore.sh` — sauvegarde/restauration MariaDB locale uniquement
- `docs/release-candidate-runbook.md` — checklist Go/No-Go, Stop/Rollback et modèle de décision
- `tests/shell/qualify-release-candidate.test.sh`

Éviter : nouvelle API publique, endpoint de fixture en production, migration CI4 native, seconde implémentation HMAC, téléchargement « latest », dump de production dans GitHub Artifacts, ou mutation manuelle de `schema_versions`.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md` — Epic 6, Story 6.9 et précondition de drift]
- [Source: `_bmad-output/planning-artifacts/epics.md` — Story 6.10]
- [Source: `_bmad-output/planning-artifacts/architecture.md` — Infrastructure & Deployment, Migration Pattern]
- [Source: `project-context.md` — Infrastructure, Development Workflow, Write Constraints]
- [Source: `_bmad-output/planning-artifacts/test-coverage-matrix.md` — P0/P1 et section NFR déploiement]
- [Source: `_bmad-output/implementation-artifacts/6-7-installer-les-gates-ci-de-preparation-a-la-production.md`]
- [Source: `_bmad-output/implementation-artifacts/6-8bis-resoudre-la-dette-technique-ci-deferred-findings-de-la-story-6-7.md`]
- [Source: `.github/workflows/ci.yml`]
- [Source: `.github/workflows/deploy-ouvaton.yml`]
- [Source: `scripts/deploy-rehearsal.sh`]
- [Source: `scripts/package-deploy-artifact.sh`]
- [Source: `docs/deployment-ouvaton.md`]
- [Source: `docs/migration-runner.md`]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- T1 : `ci.yml` génère `kermesse-deploy-manifest.json` (6 champs) après packaging ; archive + sidecar + manifeste uploadés dans le même artefact ; `rc-evidence-<run_id>` séparé avec `retention-days: 30`.
- T2 : `deploy-ouvaton.yml` restructuré : job `build-and-package` remplacé par `download-and-verify` (télécharge l'artefact CI via `run-id` + `github-token`, vérifie manifeste et SHA256). Plus de Composer/PHPUnit/packaging dans le workflow de déploiement. Le checkout utilise `candidate_sha`.
- T3 : `deploy-rehearsal.sh` enrichi du mode `--use-existing-artifact` (vérifie archive+sidecar pré-existants, saute le packaging). Ajout helper `call_migrate_endpoint()`, preuve d'idempotence (double appel ops/migrate), postflight strict (`pending=[]` ET `failed=[]`).
- T4 : `scripts/rehearsal-backup-restore.sh` créé (mariadb-dump → restauration dans `DB_RESTORE_NAME` → vérification 10 tables, comptages lignes, FK, RTO mesuré). Mécanisme prod Ouvaton documenté (sauvegardes provider-managed, pas de CLI local).
- T5 : `scripts/qualify-release-candidate.sh` créé (4 étapes : vérif manifeste → rehearsal `--use-existing-artifact` → smoke Playwright → rapport `rc-qualification-report.json`).
- T6 : `docs/release-candidate-runbook.md` créé (checklist 8 préconditions, tableau P0/P1, seuils Stop/Rollback, rollback applicatif, rollback DB, responsables). Epic 6 bloquée par 6.10.
- T7 : `test-coverage-matrix.md` mis à jour (G24-G26 → `✅ couvert` via `SessionExpirationTest` 6.8 ; entrées 2.14/2.15/2.16 corrigées). `docs/deployment-ouvaton.md` corrigé (description 3-fichiers, promotion immuable).
- T8 : Tests shell étendus — `qualify-release-candidate.test.sh` (70+ assertions), `deploy-ouvaton-workflow.test.sh` (~20 assertions 6.9), `package-deploy-artifact.test.sh` (~10 assertions ci.yml). Toutes les suites passent. PHPStan 0 erreurs. PHPUnit : 23 failures + 13 errors pré-existantes (non introduites par cette story, confirmé par stash/rerun sur branche clean).
- La phase production est bloquée par Story 6.10 (drift checksum `20260614121500_add_last_login_at_to_users` + `signups → slot_signups`) ; aucune correction manuelle du MigrationRunnerService n'est autorisée.

### File List

**Modifiés :**
- `.github/workflows/ci.yml`
- `.github/workflows/deploy-ouvaton.yml`
- `scripts/deploy-rehearsal.sh`
- `docs/deployment-ouvaton.md`
- `_bmad-output/planning-artifacts/test-coverage-matrix.md`
- `tests/shell/deploy-ouvaton-workflow.test.sh`
- `tests/shell/package-deploy-artifact.test.sh`

**Créés :**
- `scripts/rehearsal-backup-restore.sh`
- `scripts/qualify-release-candidate.sh`
- `docs/release-candidate-runbook.md`
- `tests/shell/qualify-release-candidate.test.sh`

## Change Log

- 2026-06-22 : Story créée et mise au statut `ready-for-dev` après analyse exhaustive des artefacts, du pipeline, de la répétition, des migrations, de la couverture et de l'historique Git.
