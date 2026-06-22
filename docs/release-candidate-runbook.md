# Runbook de qualification et de déploiement en production — Kermesse

> **Précondition bloquante** : La Story 6.10 (remédiation du drift de checksum
> `20260614121500_add_last_login_at_to_users` + migration `signups → slot_signups`)
> doit être `done` et verte avant tout Go. Ce runbook décrit le processus de
> qualification mais **interdit explicitement le Go** tant que 6.10 est incomplète.

---

## 1. Identité du candidat

À remplir avant la revue Go/No-Go :

| Champ | Valeur |
|-------|--------|
| `commit_sha` | _(SHA du commit candidat)_ |
| `ci_run_id` | _(ID du run CI GitHub)_ |
| `archive_sha256` | _(SHA-256 de kermesse-deploy.tar.gz)_ |
| `artifact_timestamp` | _(horodatage UTC de la CI)_ |
| Date de qualification | _(date de la répétition)_ |

---

## 2. Checklist Go/No-Go

### 2.0 Variables GitHub à positionner avant tout Go

Ces variables doivent être configurées dans **Settings → Environments → production → Variables** du dépôt GitHub :

| Variable | Valeur requise | Quand la positionner |
|----------|---------------|----------------------|
| `DEPLOY_PRODUCTION_GO` | `true` | Après validation Go/No-Go humaine — à remettre à `false` après chaque déploiement |
| `STORY_610_DONE` | `true` | Après que la Story 6.10 soit marquée `done` et verte en CI |

> **Important** : Le workflow `deploy-ouvaton.yml` refuse tout déploiement automatique
> (`workflow_run`) si `DEPLOY_PRODUCTION_GO != 'true'`. Ce garde-fou est intentionnel :
> chaque déploiement en production nécessite un Go humain explicite.
>
> La précondition `STORY_610_DONE` bloque l'étape de déploiement même pour les
> déclenchements manuels (`workflow_dispatch`) tant que 6.10 n'est pas validée.

### 2.1 Préconditions obligatoires (toutes doivent être ✅ avant le Go)

- [ ] **6.10 terminée et verte** — drift checksum résolu, migration `signups → slot_signups` validée — `vars.STORY_610_DONE = 'true'`
- [ ] **Manifeste RC vérifié** — `commit_sha`, `archive_sha256` et `ci_run_id` cohérents entre CI, sidecar et manifeste
- [ ] **Archive immuable** — aucun rebuild entre CI et production ; l'archive promue est identique (même SHA-256) à celle qualifiée
- [ ] **CI verte sur `main`** — les 6 gates (PHPUnit SQLite, PHPStan, MariaDB, E2E Playwright, rehearsal, package) ont toutes passé pour le commit candidat
- [ ] **Répétition réussie** — `scripts/deploy-rehearsal.sh --use-existing-artifact` termine avec REHEARSAL OK (activation, migration, idempotence, postflight OK)
- [ ] **Sauvegarde production fraîche** — preuve d'une sauvegarde récente chez Ouvaton avant la migration (backup automatique Ouvaton ou demande manuelle documentée)
- [ ] **Drift migration absent** — aucun drift de checksum sur `schema_versions` en production (vérifié via `POST /ops/migrate/status`)
- [ ] **Smoke tests qualification** — tous les scénarios P0/P1 de `scripts/qualify-release-candidate.sh` verts

### 2.2 Couverture P0/P1 (matrice de qualification)

| ID | Parcours | Preuve | Statut |
|----|----------|--------|--------|
| G01 | Race condition capacité | `tests/Database/MigrationRunnerMariaDBTest` | ✅ |
| G02 | Magic Link MariaDB réel | `tests/Feature/MagicLinkVerifyTest` (MariaDB group) | ✅ |
| G04 | FK slot_signups validées | `tests/Database/MariaDBSchemaTest` | ✅ |
| G24 | Session expirée GET | `tests/Feature/SessionExpirationTest` (6 tests) | ✅ |
| G25 | Session expirée POST | `tests/Feature/SessionExpirationTest` | ✅ |
| G26 | Open redirect bloqué | `tests/Feature/SessionExpirationTest` | ✅ |

### 2.3 Risques résiduels et propriétaires

| Risque | Propriétaire | Décision |
|--------|-------------|---------|
| Drift checksum `schema_versions` production | Sylvain Tenier | Bloquant — résolu dans 6.10 |
| Version PHP Ouvaton non recalibrée (`/ops/probe`) | Sylvain Tenier | Risque faible — PHP 8.3 présumé |
| RTO restauration non mesuré sur données réelles Ouvaton | Sylvain Tenier | Accepté — mesuré en rehearsal Docker |

---

## 3. Seuils Stop/Rollback (à définir avant le Go)

Ces seuils **doivent être décidés et écrits** avant d'appuyer sur le Go.

| Condition | Action |
|-----------|--------|
| Échec migration `POST /ops/migrate` (HTTP ≠ 200 ou `failed != []`) | STOP — rollback applicatif immédiat |
| Postflight `pending != []` ou `failed != []` après migration | STOP — enquête obligatoire avant toute décision |
| Smoke test P0 échoué après déploiement | STOP — rollback applicatif |
| Smoke test P1 échoué après déploiement | Décision dans les 30 min — rollback ou correctif |
| HTTP 5xx persistant (> 2 min) sur page publique | STOP — rollback applicatif |
| Divergence SHA-256 entre artefact CI et artefact deploy | STOP — ne pas promouvoir |
| Corruption ou perte de données détectée | STOP — rollback données + applicatif |

**RTO maximum accepté** : _(à décider — ex. 30 min)_

---

## 4. Procédure de rollback applicatif

Le rollback applicatif bascule `CURRENT_RELEASE` vers une release précédemment
conservée **sans modifier `shared/.env`**.

> **Important** : un rollback de fichiers n'annule pas une migration incompatible.
> Si la migration a modifié le schéma de manière incompatible avec la version
> précédente, une restauration DB séparée (procédure §5) est nécessaire.

### 4.1 Rollback via webhook (à implémenter dans 6.10 ou une story dédiée)

À ce jour, `ReleaseActivationService` ne dispose pas de route de rollback automatisée.
Le rollback applicatif s'effectue par re-déploiement de la dernière release stable.

**Procédure temporaire** :
1. Identifier le `ci_run_id` de la dernière release stable dans l'historique des preuves RC
2. Déclencher manuellement `deploy-ouvaton.yml` avec ce `ci_run_id` comme input
3. Vérifier que le smoke P0 passe après activation de la release précédente

### 4.2 Rollback DB (restauration)

Uniquement si la migration a causé une incompatibilité de données. Cette procédure
suit exclusivement le backup Ouvaton validé (§2.1) et ne doit **jamais** utiliser
de commandes SQL improvisées.

1. Confirmer la disponibilité et la fraîcheur de la sauvegarde Ouvaton
2. Contacter le support Ouvaton pour une restauration de la base MariaDB
3. Re-déployer la release stable correspondant au backup restauré
4. Vérifier les invariants P0 après restauration

---

## 5. Responsables

| Rôle | Nom |
|------|-----|
| Responsable du déploiement | _(à nommer)_ |
| Décideur Go/No-Go | _(à nommer)_ |
| Décideur rollback | _(à nommer)_ |
| Contact support Ouvaton | support@ouvaton.coop |

---

## 6. Décision Go/No-Go

| Champ | Valeur |
|-------|--------|
| Décision | ☐ **GO** / ☐ **NO-GO** |
| Date et heure | _(à remplir)_ |
| Signé par | _(à remplir)_ |
| Commentaire | _(anomalies arbitrées, risques acceptés, conditions du Go)_ |

---

## 7. Résultats post-déploiement

| Champ | Valeur |
|-------|--------|
| Heure début déploiement | |
| Heure fin déploiement | |
| Migration OK | ☐ Oui / ☐ Non |
| Smoke P0 post-deploy | ☐ Vert / ☐ Rouge |
| Décision finale | ☐ Release confirmée / ☐ Rollback exécuté |
| Observations | |

---

## 8. Archive des preuves

Conserver avec rétention supérieure à la fenêtre de rollback (minimum 30 jours) :

- Manifeste RC (`rc-evidence-<run_id>/manifest.json` dans GitHub Artifacts)
- Log de répétition (`rc-evidence/rehearsal.log`)
- Rapport de qualification (`rc-evidence/rc-qualification-report.json`)
- Ce runbook complété et signé
- Preuve de sauvegarde Ouvaton

---

## 9. Condition de clôture de l'Epic 6

L'Epic 6 ne peut être déclaré `done` que lorsque :

- La Story **6.10** est `done` et verte (drift + migration `signups → slot_signups`)
- La release a été confirmée en production (décision §7 = "Release confirmée")
- Ce runbook est archivé avec toutes les preuves
