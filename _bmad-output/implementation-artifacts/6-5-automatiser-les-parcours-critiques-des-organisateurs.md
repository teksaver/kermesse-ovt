# Story 6.5 : Automatiser les parcours critiques des organisateurs

---
status: done
epic: 6
baseline_commit: d70a0bdce9d640de4c2f8dc6f70b88f15537e0dc
---

## 🎯 Story Foundation

**Epic 6:** Stabilisation et préparation à la production.
Les organisateurs et bénévoles peuvent utiliser les parcours critiques de Kermesse sans régression connue ; l'équipe dispose de preuves automatisées reproductibles sur PHPUnit, MariaDB et navigateur réel, puis qualifie l'artefact immuable destiné à Ouvaton par une décision Go/No-Go fondée sur des preuves.

**User Story:**
As an Owner, Admin ou Gestionnaire,
I want que mes opérations de gestion soient validées selon mon rôle,
So that je puisse piloter la kermesse sans erreur de permission ni corruption du planning.

### Acceptance Criteria (BDD)

**Given** un Owner configurant une nouvelle kermesse,
**When** il crée les stands et créneaux puis ouvre les inscriptions,
**Then** chaque transition réussit,
**And** les données configurées restent visibles et cohérentes après rechargement.

**Given** une kermesse ouverte puis fermée,
**When** l'Owner ou l'Admin change son état,
**Then** les actions publiques et administratives autorisées correspondent immédiatement au nouvel état,
**And** les données existantes sont conservées.

**Given** un Gestionnaire connecté,
**When** il ouvre le dashboard,
**Then** il accède à « Gestion des inscrits » et « Mes participations »,
**And** les onglets Modification et Équipe sont absents,
**And** les accès directs correspondants sont refusés côté serveur.

**Given** un Owner, Admin ou Gestionnaire autorisé,
**When** il ajoute, corrige, déplace ou annule une inscription,
**Then** l'opération respecte les invariants de capacité, doublon et chevauchement,
**And** son résultat persiste après rechargement,
**And** les historiques et traces d'acteur sont corrects.

**Given** un Owner ou Admin dans l'onglet Équipe,
**When** il invite, réinvite ou révoque un membre,
**Then** le rôle et l'état d'invitation affichés sont corrects,
**And** les permissions du membre changent conformément à FR14 et FR16.

**Given** l'utilisateur courant affiché dans l'équipe,
**When** il consulte sa propre ligne,
**Then** elle est identifiée par « Vous »,
**And** l'auto-révocation est impossible côté interface et serveur,
**And** le flux « Quitter » n'apparaît que lorsqu'il est autorisé.

**Given** un Owner,
**When** une tentative de révocation ou de départ le cible,
**Then** l'action est refusée,
**And** la propriété de la kermesse est préservée.

**Given** deux kermesses distinctes,
**When** un utilisateur tente d'agir sur une ressource appartenant à l'autre kermesse,
**Then** l'accès est refusé,
**And** aucune donnée inter-kermesse n'est lue ou modifiée.

**Given** les scénarios organisateurs P0/P1 de la matrice,
**When** la Story est terminée,
**Then** chaque interaction dépendant du rendu ou du JavaScript possède un test Playwright,
**And** chaque règle serveur reste également protégée au niveau PHPUnit approprié.

## 🧠 Developer Context & Guardrails

### 🏗️ Technical Requirements & Architecture Compliance
- **Tests Framework:** Playwright (TypeScript) configuré dans la story 6.3.
- **Environnement d'exécution:** Tous les tests doivent s'exécuter dans le service Docker `e2e` via la commande `scripts/e2e.sh`.
- **Infrastructure:** MariaDB containerisé; aucune dépendance à un serveur Ouvaton.
- **Fixtures:** Utiliser ou étendre `e2e/fixtures/e2e-setup.sql` avec les données requises : kermesses dans les différents états, rôles associés aux utilisateurs de tests (Owner, Admin, Gestionnaire), stands et créneaux pour les tests de gestion.
- **Web-First Assertions:** Utilisez l'auto-wait de Playwright (ex: `expect(locator).toBeVisible()`). Aucune temporisation arbitraire (`waitForTimeout`) n'est autorisée.
- **Coverage:** Vérifier que les requêtes serveur et règles PHPUnit correspondantes existent. N'ajoutez pas de tests E2E superflus si une règle serveur est suffisamment validée par PHPUnit. Concentrez les E2E sur les interactions de l'interface (JavaScript, rendu, feedback asynchrone).

### 📚 Previous Story Intelligence (Story 6.4)
- **Erreurs console :** Les exceptions JS ou erreurs réseau feront échouer le test Playwright via `e2e/helpers/fixtures.ts`.
- **Responsive :** Les suites s'exécutent avec les profils `chromium` (desktop) et `Mobile Chrome` (320px). L'interface d'administration est compactée sur mobile (cartes regroupées pour les stands, actions admin "inline", etc). Veiller à la compatibilité à 320 px.
- **Rechargement :** Utiliser `page.reload()` pour valider la persistance d'états asynchrones ou l'exactitude des calculs côté backend (par exemple, la place libérée/attribuée ou le statut de la kermesse).
- **Indépendance des tests :** Garder les tests mutables indépendants, s'ils modifient le statut d'une kermesse ou consomment un créneau, prévoyez des fixtures dédiées afin que les tests ne s'interfèrent pas ou marquer correctement `.skip` sur certains profils mobiles si la fixture est unique par run.
- **Debug Bar :** L'allowlist restreinte pour CodeIgniter 4 Debug Bar est déjà en place. Scoper vos sélecteurs sur les panneaux cibles (ex: `.confirmation-panel`, `.stand-group`) et pas juste la page pour éviter de sélectionner des éléments cachés de la debug bar.

### 🔍 Git Intelligence Summary
Recent commits:
- `d70a0bd` feat(story-6.4): automatiser les parcours critiques du bénévole — 60 tests E2E verts
- `542aaec` feat(story-6.3): infrastructure Playwright/Docker + 18 smoke tests E2E verts
- `743ce56` fix(story-6.1): appliquer les 8 patches de revue de code
- `728b630` docs(planning): aligner epics et PRD avec l'implémentation livrée (2e1514a)
- `2e1514a` feat: détection 'c'est moi' dans l'équipe + notifications Owner sur changements

Insight: The E2E Playwright infrastructure is fully solid with over 78 tests passing. Tests must leverage existing helper functions and strictly adhere to the patterns set in story 6.3/6.4.

### 🌐 Project Context Reference
- **uv run python:** Obligatoire pour exécuter tous les scripts BMad (règle IA).
- **Zéro Fuite PII:** L'injection de données personnelles (emails, noms de famille, Magic Links) dans les vues publiques est une faute critique. Ne jamais exposer de données de l'Admin ou du Bénévole sur des parcours publics.
- **Isolation d'État:** Les vues du tableau de bord "Gestion des Inscrits" n'exposent que ce qui correspond au rôle et à la kermesse courante. Il faut valider formellement qu'on ne peut pas croiser ou altérer des données d'autres kermesses.
- **RBAC & Contraintes:** Les requêtes modifiantes doivent avoir été protégées correctement côté PHP (et potentiellement vérifiées par E2E pour l'affichage de non-disponibilité des onglets `Modification` et `Équipe` d'un Gestionnaire).

## 📋 Tasks / Subtasks

- [x] T1 — Étendre les fixtures SQL pour les tests organisateurs
  - [x] T1.1 — Ajouter le token magic-link pour `gestionnaire@e2e.test`
  - [x] T1.2 — Ajouter l'invitation en attente (`pending-invite@e2e.test`)
  - [x] T1.3 — Ajouter la kermesse `kermesse-e2e-lifecycle` (état `preparation`)
  - [x] T1.4 — Ajouter le "Stand Organisateurs E2E" avec 4 créneaux dédiés

- [x] T2 — Étendre l'infrastructure E2E pour le rôle gestionnaire
  - [x] T2.1 — Ajouter `'gestionnaire'` au type de `storageStateFor` dans `fixtures.ts`
  - [x] T2.2 — Ajouter l'authentification gestionnaire dans `global-setup.ts`

- [x] T3 — Créer `e2e/tests/organizer-dashboard.spec.ts`
  - [x] T3.1 — AC3 : Restrictions de rôle gestionnaire (onglets visibles/absents)
  - [x] T3.2 — AC7 : Protection Owner (bouton révocation absent)
  - [x] T3.3 — AC2 : Lifecycle kermesse (ouvrir + fermer)
  - [x] T3.4 — AC4 : Admin ajoute une inscription via modale `<dialog>`
  - [x] T3.5 — AC4 : Admin annule une inscription via `<details>`
  - [x] T3.6 — AC4 : Admin déplace une inscription
  - [x] T3.7 — AC5 : Owner invite un nouveau membre
  - [x] T3.8 — AC5 : Owner relance une invitation en attente

- [x] T4 — Exécuter les tests et valider les 78+ tests existants sans régression
  - [x] T4.1 — Lancer `bash scripts/e2e.sh` et vérifier que les anciens tests passent
  - [x] T4.2 — Vérifier que les nouveaux tests organisateurs passent

### Review Findings

- [x] [Review][Patch] Missing E2E test for stand and slot creation flow [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Missing E2E test for editing/correcting a signup [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Missing E2E test for revoking a team member [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Missing current user identification & self-revocation protection [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Abuse of waitForLoadState('networkidle') [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Race Conditions by Conditional Logic (isVisible) [e2e/tests/organizer-dashboard.spec.ts:844]
- [x] [Review][Patch] Naive Overflow Assertions [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Suspicious Hardcoded Timeouts [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Brittle Locator Strategies [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Incomplete Form Validation Testing [e2e/tests/organizer-dashboard.spec.ts]
- [x] [Review][Patch] Edge case: value attribute missing on target option [e2e/tests/organizer-dashboard.spec.ts:783]
- [x] [Review][Defer] App Bug: PRG redirects to invalid tab id [e2e/tests/organizer-dashboard.spec.ts] — deferred, pre-existing
- [x] [Review][Defer] Unscalable Magic Link Fixtures [e2e-setup.sql] — deferred, pre-existing
- [x] [Review][Defer] Fragile SQL Teardown Scripts [e2e-setup.sql] — deferred, pre-existing

## 🗂 File List

- `e2e/fixtures/e2e-setup.sql` (modified)
- `e2e/helpers/fixtures.ts` (modified)
- `e2e/global-setup.ts` (modified)
- `e2e/tests/organizer-dashboard.spec.ts` (new)
- `e2e/tests/visitor-signup.spec.ts` (bug fix: URL regex pour story 5.13)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified)
- `_bmad-output/implementation-artifacts/6-5-automatiser-les-parcours-critiques-des-organisateurs.md` (this file)

## 📝 Dev Agent Record

### Implementation Plan
Concentrer les tests E2E sur les interactions JavaScript qui ne peuvent pas être couvertes par PHPUnit :
- Modales `<dialog>` (add signup) — le bouton submit est désactivé jusqu'à ce que le formulaire soit valide via JS
- Accordéons `<details>` (cancel, move, edit) — expand/collapse dépend du navigateur
- Navigation par onglets (sidebar buttons + accordion headers selon la taille d'écran)
- Transitions de statut de kermesse et leur reflet immédiat dans l'UI

Les règles serveur (403 inter-kermesse, invariants de capacité/doublon/chevauchement) sont déjà couvertes par PHPUnit et ne sont PAS redondées en E2E.

### Completion Notes
- Ajout du token gestionnaire + pending invite + kermesse lifecycle + Stand Organisateurs E2E dans les fixtures SQL
- Extension de `storageStateFor` et `global-setup.ts` pour le rôle gestionnaire
- 18 nouveaux tests E2E dans `organizer-dashboard.spec.ts` couvrant les ACs 2, 3, 4, 5, 7
- Résultat final : **80 tests passent, 10 ignorés (mobile pour les tests mutants), 0 échec**
- Piège `filter({ has: page.getByText(...) })` : évalue le locator globalement → tous les éléments matchent si le texte existe n'importe où sur la page ; corriger avec `filter({ has: page.locator('h3.subsection-title', { hasText: ... }) })` ou `filter({ hasText: ... })` selon le contexte (le dropdown de déplacement injecte le nom du stand dans tous les .participants-stand)
- Correction régression story 5.13 : URL `/signup/confirmation` → `/slot-signup/confirmation` dans `visitor-signup.spec.ts`
- `invite_warning` vs `invite_success` : dans l'env Docker sans SMTP, l'email échoue silencieusement → le contrôleur flashe `invite_warning` ; le test accepte les deux classes de flash

## Suggested Review Order

**Infrastructure E2E — entrée de lecture**

- Navigation helpers `goToDashboard` / `openInscritsTab` / `openEquipeTab` : assert-based waits replacing `networkidle`
  [`organizer-dashboard.spec.ts:36`](../../e2e/tests/organizer-dashboard.spec.ts#L36)

**Nouvelles fixtures SQL (story 6.5)**

- Slot édit 13:00–14:00, user `revoke-me@e2e.test` : données isolées pour les tests mutants AC4/AC5
  [`e2e-setup.sql:228`](../../e2e/fixtures/e2e-setup.sql#L228)

**Nouveaux tests — ACs manquants**

- AC1 : création stand + créneau dans `kermesse-e2e-prep` (Owner, desktop)
  [`organizer-dashboard.spec.ts:261`](../../e2e/tests/organizer-dashboard.spec.ts#L261)

- AC4 : correction d'une fiche d'inscription via `<details class="admin-edit-details">`
  [`organizer-dashboard.spec.ts:549`](../../e2e/tests/organizer-dashboard.spec.ts#L549)

- AC5 : révocation d'un rôle avec acceptance du `confirm()` natif via `page.once('dialog', ...)`
  [`organizer-dashboard.spec.ts:700`](../../e2e/tests/organizer-dashboard.spec.ts#L700)

- AC6 : badge « Vous » et absence de bouton auto-révocation (session admin)
  [`organizer-dashboard.spec.ts:177`](../../e2e/tests/organizer-dashboard.spec.ts#L177)

**Patches qualité — tests existants**

- AC4 add : `submitBtn` désactivé avant remplissage ; filtre `/17:00.+18:00/` remplace `.nth(1)` brittle
  [`organizer-dashboard.spec.ts:326`](../../e2e/tests/organizer-dashboard.spec.ts#L326)

- AC4 move : `expect(targetOption).toBeAttached()` + guard null avant `selectOption`
  [`organizer-dashboard.spec.ts:466`](../../e2e/tests/organizer-dashboard.spec.ts#L466)

- AC3 overflow : `Math.ceil(scrollWidth) > Math.ceil(window.innerWidth)` pour absorber les différences sub-pixel
  [`organizer-dashboard.spec.ts:124`](../../e2e/tests/organizer-dashboard.spec.ts#L124)

**Infrastructure helper + global-setup**

- `storageStateFor('gestionnaire')` : ajout du type + session gestionnaire dans global-setup
  [`fixtures.ts:1`](../../e2e/helpers/fixtures.ts#L1)

## 📅 Change Log
- 2026-06-20: Story créée et implémentée (baseline: d70a0bd)
- 2026-06-20: Tests corrigés — 80/80 verts (suite de la session)
- 2026-06-21: 11 patches de revue appliqués — 87 tests verts (4 nouveaux tests AC1/AC4/AC5/AC6 + 7 corrections qualité)
