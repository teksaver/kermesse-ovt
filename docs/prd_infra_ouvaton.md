---
title: "PRD: Chantier infrastructure — parité locale Ouvaton & déploiement atomique"
status: final
created: 2026-06-06
updated: 2026-06-06
owner: Sylvain
---

# PRD: Chantier infrastructure — parité locale Ouvaton & déploiement atomique

## 0. Objet du document

Ce PRD cadre un **chantier d'infrastructure** dont le but est de pouvoir tester, en local, le déploiement de Kermesse **dans les mêmes conditions que l'hébergeur Ouvaton**, et de fiabiliser le transfert applicatif en production.

Il s'agit d'un PRD technique à acteur unique (le développeur / la chaîne CI), distinct du PRD produit Kermesse. Il ne modifie pas le périmètre fonctionnel volontaire/admin.

Sources internes prises en compte (non dupliquées) :

- `docs/deployment-ouvaton.md` — pipeline GitHub Actions, FTPS, préservation `.env`.
- `docs/local-orbstack.md` — environnement Docker local (services `app` + `db`).
- `docs/migration-runner.md` — contrat de l'endpoint `POST /ops/migrate`.

## 1. Contexte et contrainte fondamentale

Ouvaton est un hébergement mutualisé **runtime-only** : pas de Docker, Composer, NPM, PHPUnit, `php spark`, client `mysql`, ni accès SSH/CLI externe pour la CI/CD. Tout est préparé par GitHub Actions et livré en artefact prêt à exécuter ; les migrations passent par un endpoint HTTP signé.

Le risque ciblé par ce chantier : **les surprises de déploiement**. Un code qui tourne en local mais casse sur Ouvaton (limites PHP), un transfert réseau interrompu qui laisse un site à demi-déployé, ou un script de déploiement qu'on ne découvre cassé qu'en l'exécutant réellement contre la production.

## 2. État existant et delta visé

Honnêteté sur le déjà-fait — ce chantier **fait évoluer** une base existante, il ne part pas de zéro.

| Objectif | État actuel | Delta visé par ce PRD |
|----------|-------------|-----------------------|
| 1 — Brider Docker aux limites Ouvaton | Docker local fonctionnel, mais **aucun bridage** `php.ini` ni alignement explicite des extensions/version MariaDB sur Ouvaton ; **limites réelles Ouvaton inconnues** | **Nouveau** — d'abord *mesurer* les limites réelles via une sonde déployée, puis refléter ces limites dans le conteneur |
| 2 — Déploiement atomique | Transfert `lftp mirror --reverse --delete` (fichier par fichier) d'un artefact `.zip` | **Changement** — packager en `.tar.gz` et transférer en **un bloc**, activation atomique côté serveur via route ops dédiée |
| 3 — Migrations via endpoint HTTP sécurisé | `POST /ops/migrate` (HMAC-SHA256, anti-rejeu, verrou, idempotent) **déjà implémenté et documenté** | **Consolidation** — déclenchement automatique post-activation + vérification d'état sans mutation, intégrés à la répétition locale (obj. 4) ; pas de refonte du runner |
| 4 — Testabilité locale du déploiement complet | `docker compose` + appel `ops/migrate` local possibles séparément | **Nouveau** — orchestrer la **répétition complète** (packaging → transfert simulé → activation → migration automatique) localement, sans GitHub Actions ni Ouvaton |

L'objectif 3 étant déjà livré côté runner, ce PRD le traite comme un composant à *orchestrer et vérifier*, pas à réécrire.

## 3. Acteur et parcours

Acteur unique : **le développeur/mainteneur** (toi), parfois relayé par le runner GitHub Actions qui exécute les mêmes scripts.

- **PJ-1. Répéter un déploiement avant de l'envoyer en vrai.** Avant de pousser sur `main`, je lance une commande unique en local qui rejoue tout le pipeline (packaging de l'artefact, transfert dans un serveur cible local, activation atomique, migration appliquée automatiquement si nécessaire) et me dit en clair si ça passe — sans toucher GitHub Actions ni Ouvaton.
- **PJ-2. Développer aux limites de production.** Mon conteneur local applique les mêmes limites PHP qu'Ouvaton (mesurées, pas devinées), donc un code qui dépasse `memory_limit` ou `max_execution_time` échoue **chez moi**, pas en production.
- **PJ-3. Survivre à une coupure réseau.** Si le transfert applicatif est interrompu, le site en place reste intact et servi ; aucun état à demi-déployé n'est exposé.
- **PJ-4. Ne jamais oublier une migration.** Chaque déploiement vérifie automatiquement l'état du schéma et applique les migrations en attente, sans geste manuel ; s'il n'y a rien à appliquer, l'étape est un no-op silencieux.

## 4. Fonctionnalités

Les exigences sont groupées par objectif et numérotées de façon stable (FR-N).

### 4.1 Parité de l'environnement local avec Ouvaton (Objectif 1)

**Description :** On mesure d'abord la configuration runtime *réelle* d'Ouvaton à l'aide d'une sonde déployée, puis le conteneur `app` reflète ces limites pour que les dépassements se manifestent en développement. Réalise PJ-2.

- **FR-1 : Sonde de configuration réelle.** Un composant PHP déployable récupère et renvoie les faits de configuration runtime réels de la cible : version PHP, `memory_limit`, `max_execution_time`, `post_max_size`, `upload_max_filesize`, extensions chargées, et version du serveur MariaDB (obtenue via la connexion `database.default.*`). Sortie en JSON structuré, exploitable pour calibrer FR-3/4.
- **FR-2 : Exposer la sonde en route ops signée.** La sonde est une route ops `POST /ops/probe` protégée par HMAC (même socle `OpsAuthFilter` que `/ops/migrate`). Elle n'expose **aucun secret** (ni valeurs `.env`, ni credentials) — uniquement des faits de configuration — et reste désactivable/retirable une fois la mesure faite.
- **FR-3 : Brider les limites PHP.** Le conteneur applique, via un `php.ini` versionné, les limites cibles Ouvaton **calibrées sur la sortie de la sonde** (point de départ : `memory_limit = 128M`, `max_execution_time = 30`). Les valeurs sont centralisées et documentées.
- **FR-4 : Aligner extensions, version PHP et version MariaDB.** Les extensions activées localement, la version PHP du conteneur `app` et la version MariaDB du service `db` correspondent à ce que la sonde a relevé sur Ouvaton.
- **FR-5 : Rendre les limites visibles et vérifiables.** Une commande locale affiche la configuration runtime effective du conteneur (`memory_limit`, `max_execution_time`, version PHP, extensions, version MariaDB) afin de la comparer aux valeurs mesurées par la sonde.

### 4.2 Déploiement atomique par archive transférée en un bloc (Objectif 2)

**Description :** Le déploiement applicatif package le projet dans une archive `.tar.gz` unique, transférée d'un seul tenant vers un staging hors web root, puis activée atomiquement côté serveur. Une coupure réseau pendant le transfert ne corrompt jamais le site en place. Réalise PJ-3.

- **FR-6 : Packager en archive unique.** Le packaging produit un unique `.tar.gz` contenant exactement le contenu déployable (mêmes inclusions/exclusions que l'artefact actuel : `app/`, `vendor` prod, `public/`, `database/`, placeholders `writable/`, `composer.*`, `.env.example`, docs ; exclusions `.git`, `_bmad*`, `node_modules`, `tests`, secrets, `.env*`).
- **FR-7 : Garantir l'intégrité de l'archive.** Le packaging vérifie l'absence de fichiers interdits (notamment tout `.env`/secret) et échoue sinon. Un checksum SHA-256 de l'archive est produit pour vérification après transfert.
- **FR-8 : Transférer l'archive d'un seul bloc.** Le transfert envoie le `.tar.gz` comme un fichier unique vers un emplacement de staging hors document root, et non par synchronisation fichier-par-fichier.
- **FR-9 : Activer atomiquement via une route ops dédiée.** Après réception complète et vérification du checksum, une route ops applicative **`POST /ops/activate`** (signée HMAC, sur le modèle de `/ops/migrate` : fraîcheur timestamp, anti-rejeu, verrou) extrait l'archive depuis le staging et **bascule atomiquement** vers la nouvelle version (extraction dans un dossier daté puis bascule de pointeur/dossier). Aucune dépendance SSH/CLI Ouvaton. Un transfert ou une extraction interrompue laisse la version précédente intacte et servie.
- **FR-10 : Préserver les invariants de production.** L'activation ne touche jamais le `.env` de production ni le contenu runtime de `writable/` (logs, sessions, cache, uploads). La séparation `httpdocs/` (shim `index.php`) / dossier applicatif hors web root est conservée. La rétention des versions précédentes est bornée (nettoyage des anciens dossiers datés au-delà d'un seuil).

> Décision (R-1) tranchée : activation par route ops `POST /ops/activate`, car c'est la seule voie réellement atomique compatible runtime-only (FTPS sans CLI), et elle réutilise l'architecture de sécurité ops déjà en place. L'ancien `lftp mirror` est remplacé pour le transfert applicatif.

### 4.3 Migrations MariaDB automatiques et idempotentes (Objectif 3)

**Description :** À chaque déploiement, le schéma est mis à l'état attendu **automatiquement** : on vérifie l'état, on applique uniquement les migrations en attente, et on ne fait rien s'il n'y a rien à faire. Le runner `POST /ops/migrate` (HMAC, fraîcheur timestamp, anti-rejeu, verrou, idempotence par checksum) reste le moteur ; ce chantier en cadre l'orchestration et la vérification. Réalise PJ-4.

- **FR-11 : Réutiliser le runner existant sans régression.** Pas de second mécanisme de migration. Le contrat de `docs/migration-runner.md` (en-têtes, payload signé, codes 200/500, verrou, idempotence) reste la référence. La route `/ops/activate` partage le même socle d'authentification (`OpsAuthFilter`).
- **FR-12 : Déclenchement automatique post-activation.** La migration est l'**étape automatique finale de chaque déploiement** (réel et simulé), déclenchée sans intervention manuelle juste après l'activation atomique (FR-9). L'appel est inconditionnel : c'est le runner, idempotent, qui décide s'il y a quelque chose à appliquer.
- **FR-13 : Application basée sur l'état (vérifier puis appliquer si besoin).** Le runner lit l'état (`schema_versions`), n'applique que les migrations **absentes ou précédemment échouées**, ignore celles déjà appliquées (`skipped`), et refuse une migration déjà appliquée dont le checksum a changé (anti-dérive). Aucune migration n'est rejouée à tort ; un déploiement sans nouvelle migration produit `applied: 0`.
- **FR-14 : Vérification d'état sans mutation (dry-run/statut).** Une capacité en lecture seule permet de connaître l'écart entre migrations présentes dans l'artefact et état appliqué (en attente / appliquées / échouées) **sans rien modifier**. Elle sert à (a) la vérification de la répétition locale et (b) l'observabilité avant/après déploiement. [HYPOTHÈSE : exposée comme mode dry-run de `/ops/migrate` ou route `POST /ops/migrate/status` signée HMAC ; à trancher en architecture.]
- **FR-15 : Échec propagé.** Le déclencheur interprète la réponse JSON (`applied`/`skipped`/`failed`) ; le déploiement n'est considéré réussi que si l'étape migration renvoie 200 avec `failed = 0`. Tout `failed > 0` ou code non-200 fait échouer le déploiement de façon visible.

> Note de séquencement : l'ordre est *activation puis migration*, cohérent avec le pipeline actuel. Il existe une courte fenêtre où le nouveau code peut s'exécuter avant l'application d'une migration de schéma. Acceptable pour le MVP mono-opérateur ; documenté en R-6.

### 4.4 Répétition complète du déploiement en local (Objectif 4 — crucial)

**Description :** Un opérateur peut simuler et exécuter le **processus de déploiement de bout en bout** au sein de l'environnement Docker local, sans dépendre de GitHub Actions ni d'Ouvaton. Réalise PJ-1.

- **FR-16 : Orchestrer la répétition par une commande unique.** Un script/commande unique enchaîne : packaging (FR-6/7) → transfert vers une **cible locale simulant Ouvaton** (FR-8) → activation atomique via `/ops/activate` (FR-9) → migration automatique (FR-12) → vérification d'état et de résultat (FR-14/15), et retourne un statut clair (succès/échec) avec un résumé lisible.
- **FR-17 : Fournir une cible de déploiement locale.** L'environnement Docker inclut une cible jouant le rôle d'Ouvaton : un endpoint de réception du transfert (ex. service FTPS local ou volume de staging) **et** un serveur web servant la version activée avec les **mêmes limites runtime** qu'au §4.1. La cible ne requiert ni Ouvaton ni accès réseau externe.
- **FR-18 : Réutiliser les mêmes scripts qu'en CI.** Les scripts exécutés en local sont **les mêmes** que ceux appelés par GitHub Actions (paramétrés par variables d'environnement), pour que « ça passe en local » prédise « ça passe en CI/prod ». Pas de chemin de code divergent local-only.
- **FR-19 : Simuler les échecs.** La répétition permet d'injecter au minimum : une coupure de transfert (archive incomplète) — l'activation doit être refusée et la version précédente préservée ; un checksum invalide — rejet ; une migration en échec — statut d'échec propagé.
- **FR-20 : Être idempotent et reproductible.** Relancer la répétition à blanc ne laisse pas d'état résiduel bloquant ; une commande de remise à zéro de la cible locale est fournie.

## 5. Exigences non-fonctionnelles

- **NFR-1 — Sécurité des secrets.** Aucun secret ni `.env` de production n'entre dans l'archive, les logs, ou le dépôt. La répétition locale utilise des secrets de développement non sensibles (ex. HMAC local déjà présent dans `docker-compose.yml`). La sonde (FR-1) ne renvoie aucun secret.
- **NFR-2 — Préservation de production.** Le déploiement n'écrase jamais le `.env` de production ni le runtime `writable/`. Règle absolue conservée depuis `docs/deployment-ouvaton.md`.
- **NFR-3 — Atomicité observable.** L'état « à demi-déployé » ne doit jamais être servi : pendant transfert/extraction, l'URL sert l'ancienne version ou une page de maintenance, jamais un mélange.
- **NFR-4 — Parité local ⇄ production.** Limites runtime, version PHP/MariaDB, et scripts de déploiement sont alignés sur les mesures réelles ; tout écart connu est documenté.
- **NFR-5 — Runtime-only respecté.** Aucune étape de production ne requiert Docker, Composer, NPM, PHPUnit, `php spark`, ni client `mysql`. Sonde, activation et migration s'exécutent dans l'application déployée via routes ops signées, sans SSH/CLI Ouvaton.
- **NFR-6 — Lisibilité opérateur.** Sorties en clair, statut final non ambigu, messages d'erreur exploitables (français pour l'opérateur, codes/logs techniques en anglais conformément aux règles projet).
- **NFR-7 — Testabilité CI.** La répétition tourne aussi en GitHub Actions (ou un sous-ensemble) pour empêcher la dérive entre scripts locaux et CI ; elle ne dépend d'aucun service Ouvaton.

## 6. Critères d'acceptation (Definition of Done)

1. Une sonde ops signée (`/ops/probe`) renvoie en JSON les limites runtime réelles (PHP, `memory_limit`, `max_execution_time`, extensions, version MariaDB) sans exposer de secret (FR-1, FR-2).
2. `docker compose` applique les limites calibrées (point de départ `memory_limit=128M`, `max_execution_time=30`) vérifiables (FR-3, FR-5).
3. Le packaging produit un `.tar.gz` unique + checksum, et échoue si un fichier interdit est présent (FR-6, FR-7).
4. Une commande locale unique rejoue packaging → transfert bloc → activation atomique (`/ops/activate`) → migration automatique → vérification, et sort en succès sur un cas nominal (FR-16).
5. Couper le transfert (archive incomplète) laisse la version précédente servie et refuse l'activation (FR-9, FR-19, NFR-3).
6. Un déploiement sans nouvelle migration produit `applied: 0` (no-op) ; un déploiement avec migration en attente l'applique automatiquement (FR-12, FR-13).
7. Une migration en échec fait échouer le déploiement de façon visible (FR-15, FR-19).
8. Les scripts exécutés en local sont identiques à ceux de CI (FR-18) ; aucun secret de prod n'apparaît dans l'archive/les logs (NFR-1).

## 7. Hors périmètre

- Refonte du runner `/ops/migrate` ou de son contrat de sécurité (déjà livré).
- Changement du protocole de production confirmé (FTPS) au-delà du passage au transfert par archive + activation ops.
- Multi-environnements (staging Ouvaton distinct), blue/green multi-serveurs, rollback automatique de schéma DB, migrations descendantes (down).
- Toute évolution fonctionnelle de l'application Kermesse (volontaires, admin, emails).
- Orchestration multi-cloud ou conteneurisation de la production Ouvaton (interdit : runtime-only).

## 8. Risques et décisions

- **R-1 — Activation atomique sans CLI serveur. → Tranchée.** Route ops `POST /ops/activate` (HMAC, verrou), seule voie réellement atomique compatible FTPS runtime-only. Voir FR-9.
- **R-2 — Mesure des limites réelles. → Tranchée.** Sonde ops `POST /ops/probe` (FR-1/2) ; `128M`/`30` ne sont qu'un point de départ, remplacé par la mesure.
- **R-3 — Forme de la vérification d'état migration.** Mode dry-run de `/ops/migrate` vs route `/ops/migrate/status` dédiée (FR-14). *Ouvert — à trancher en architecture.*
- **R-4 — Fidélité de la cible locale.** Un FTPS local + activation ne reproduit pas parfaitement Ouvaton (permissions, chroot, document root `httpdocs/`). Documenter les écarts connus (NFR-4). *Ouvert.*
- **R-5 — Coexistence avec le pipeline actuel.** Le passage `.zip`+mirror → `.tar.gz`+activation ops impacte `deploy-ouvaton.yml` et `package-deploy-artifact.sh` ; prévoir la transition sans casser les workflows existants. *Ouvert — à planifier au découpage.*
- **R-6 — Fenêtre nouveau code / ancien schéma.** L'ordre activation→migration laisse une courte fenêtre où le nouveau code tourne avant l'application du schéma. Acceptable MVP mono-opérateur ; à réévaluer si une migration devient incompatible avec le code précédent. *Ouvert — documenté.*

## 9. Métriques de succès

- **M-1 :** 100 % des déploiements précédés d'une répétition locale réussie (objectif process).
- **M-2 :** 0 incident « site à demi-déployé » après bascule sur l'activation atomique.
- **M-3 :** Réduction des « surprises de déploiement » liées aux limites runtime (régressions `memory_limit`/`timeout` détectées en local, plus en prod).
- **M-4 :** 0 déploiement nécessitant une migration manuelle a posteriori (migration toujours appliquée automatiquement).
- **Contre-métrique :** la répétition locale ne doit pas devenir si lente ou complexe qu'elle soit contournée — temps d'exécution à garder raisonnable.

## 10. Prochaines étapes

1. Validation de ce PRD par Sylvain.
2. Architecture du chantier (routes `/ops/activate` et `/ops/probe`, forme du dry-run migration, cible locale, transition pipeline) — `bmad-create-architecture`.
3. Découpage en epics/stories — `bmad-create-epics-and-stories`.
