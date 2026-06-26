---
title: 'Stabilisation tests et infra post-EPIC 6'
type: 'stabilization'
created: '2026-06-26'
status: 'ready'
context:
  - '{project-root}/project-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problème :** La story 6-11 a livré 66 tests MariaDB verts et 564 tests SQLite verts, mais 3 findings de revue critiques n'ont pas été appliqués : une exception non gérée dans `MigrationRunnerService::reconcileChecksum()` (chemin critique de prod), des erreurs CI masquées par `2>/dev/null` dans `scripts/e2e.sh`, et une fuite d'état `innodb_lock_wait_timeout` entre tests dans `SlotSignupInvariantsMariaDBTest`.

**Approche :** Appliquer chirurgicalement les 3 patches critiques en spec standalone, sans rouvrir l'EPIC 6. Les 6 autres findings de 6-11 (fragilités de fixtures, docs, vars CI) sont différés en story 7-2.

## Boundaries & Constraints

**Always :** Conserver la suite MariaDB verte (66 tests). Ne pas régresser la suite SQLite (564 tests). PHPStan niveau 7 propre.

**Never :** Ne pas toucher aux routes, au schéma SQL, ni aux règles métier. Ne pas introduire de changements d'architecture. Ne pas merger de code sans suite verte.

## I/O & Edge-Case Matrix

| Scénario | Input / État | Comportement attendu |
|----------|-------------|---------------------|
| reconcileChecksum — version inconnue | `$version` absente de `schema_versions` | Lève une exception typée avec message clair — pas de crash silencieux |
| reconcileChecksum — DB inaccessible | Connexion coupée pendant UPDATE | Exception propagée vers l'appelant, logged, échec explicite |
| e2e.sh — échec mysql/mariadb | Commande retourne exit code non-zéro | Script s'arrête et affiche l'erreur — pas de faux positif silencieux |
| SlotSignupInvariantsMariaDBTest — test suivant | innodb_lock_wait_timeout modifié par le test précédent | tearDown remet la valeur à la valeur initiale — isolation garantie |

</frozen-after-approval>

## Tasks

- [x] **T1 — Exception non gérée dans `MigrationRunnerService::reconcileChecksum()`**
  - Fichier : `app/Services/MigrationRunnerService.php`
  - Corps extrait dans `doReconcileChecksum()` (privée) ; `reconcileChecksum()` publique l'enveloppe dans try/catch et relance une `RuntimeException` avec le `$version` dans le message
  - Appelant (`DriftController`) reçoit l'exception normalement

- [x] **T2 — `2>/dev/null` masque les erreurs dans `scripts/e2e.sh`**
  - Fichier : `scripts/e2e.sh`
  - Retiré `2>/dev/null` de `docker compose config` (ligne COMPOSE_PROJECT) — seule redirection critique
  - Ajouté garde explicite : si `COMPOSE_PROJECT` vide → `exit 1` avec message clair
  - Les autres `2>/dev/null` sont intentionnels (teardown, `command -v`, polling, `docker volume create --idempotent`)

- [x] **T3 — Fuite d'état `innodb_lock_wait_timeout` dans `SlotSignupInvariantsMariaDBTest`**
  - Fichier : `tests/database/SlotSignupInvariantsMariaDBTest.php`
  - Dans les 3 tests de concurrence : changé en `SET SESSION` explicite + ajouté `SET SESSION innodb_lock_wait_timeout = @@GLOBAL.innodb_lock_wait_timeout` avant `$db2->close()`

## Verification

**Suite complète :**
```
vendor/bin/phpunit --exclude-group mariadb --no-coverage
```
Expected : 564/564 verts.

**Suite MariaDB (Docker local) :**
```
vendor/bin/phpunit -c phpunit.mariadb.local.xml --no-coverage
```
Expected : 66/66 verts.

**PHPStan :**
```
composer analyse
```
Expected : 0 erreurs.

## Findings différés → Story 7-2

Les 6 findings suivants de la revue 6-11 sont volontairement différés en story `7-2-nettoyage-du-code-zombie` :

- Variables d'environnement critiques pour les tests Ops manquantes dans `ci.yml`
- Omission de `created_at`/`updated_at` dans les insertions SQL [`RoleServiceMariaDBTest.php`]
- Purge statique des tables fragile (DROP TABLE) [`ManageSlotsMariaDBTest.php`]
- Absence de validation de succès sur les inserts bruts [`RoleServiceMariaDBTest.php`]
- Documenter l'exception temporaire à la règle Fabricator dans `project-context.md`
- Message d'erreur du garde anti-régression MySQLi non mis à jour [`FullMigrationStackMariaDBTest.php`]
