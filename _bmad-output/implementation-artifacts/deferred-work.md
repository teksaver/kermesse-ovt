## Deferred from: code review of 4-2-orchestrateur-de-repetition-a-commande-unique.md (2026-06-07)
- Inadequate Error Reporting: The `on_error` trap is vague and lacks `$LINENO` or `$BASH_COMMAND` for better debugging.
- Brittle Path Duplication: Routes are hardcoded twice (once in hmac_sign, once in curl), which could lead to mismatch errors. — ✅ RÉSOLU Story 5.5 (2026-06-08) : variable de route unique par opération (ACTIVATE_ROUTE/MIGRATE_ROUTE/STATUS_ROUTE ; ROUTE côté workflow).
- Static Summary: The success summary block statically prints [OK] instead of reflecting individual step success based on variables.

## Deferred from: code review of 4-3-injection-d-echecs-dans-la-repetition (2026-06-08)
- [Review][Defer] Hardcoded Remote Paths (kermesse/staging) [scripts/deploy-rehearsal.sh:116] — deferred, pre-existing — ✅ RÉSOLU/périmé Story 5.5 (2026-06-08) : staging entièrement paramétré par REMOTE_STAGING (rehearsal ET production, via transfer-archive.sh).

## Deferred from: code review of 4-4-idempotence-et-remise-a-zero-de-la-cible-locale.md (2026-06-08)
- Out-of-scope modifications (Scope Creep) — deferred: ne pas perdre le travail réalisé et laisser les user stories suivantes assurer la conformité finale

## Deferred from: code review of 4-5-client-de-repetition-dockerise.md (2026-06-08)
- Race Conditions from Missing Healthchecks — deploy-client n'attend pas la santé de ses dépendances.
- Fragile Container Environment Detection — Utilisation de KERMESSE_REHEARSAL_CONTAINER au lieu de /.dockerenv.
- Hardcoded Database Port Assumption — Le port par défaut est supposé dans le script reset.
- Desynchronized Compose Configurations — Nom de la base kermesse codé en dur dans docker-compose. — ✅ RÉSOLU Story 5.6 (2026-06-08) : ancres YAML (&db-name / &db-user / &db-pass + bloc &ci-db-env).
- Weak default credentials injected [docker-compose.yml] — deferred, pre-existing
## Deferred from: code review of 4-6-observabilite-de-la-base-locale-phpmyadmin.md (2026-06-08)
- Insecure Documentation Practices [docs/local-orbstack.md] — Passwords hardcoded in docs.

## Deferred from: code review of 5-1-bascule-de-deploy-ouvaton-yml-vers-archive-webhooks.md (2026-06-08)
- `curl -f` hides HTTP response body on errors [.github/workflows/deploy-ouvaton.yml]
- Duplicated transfer logic between CI and script [scripts/transfer-archive.sh] — ✅ RÉSOLU Story 5.5 (2026-06-08) : deploy-ouvaton.yml appelle scripts/transfer-archive.sh (plus de lftp inline pour le transfert).
- Missing automated rollback if migration fails [app/Services/MigrationRunnerService.php]
- Hardcoded artifact name in payload prevents dynamic versioning [.github/workflows/deploy-ouvaton.yml]
- `VARCHAR(64)` primary key bloats secondary indexes [app/Services/MigrationRunnerService.php]
- Shell pipeline for HMAC is fragile [.github/workflows/deploy-ouvaton.yml]
- Password injected directly into lftp command line [.github/workflows/deploy-ouvaton.yml]

## Deferred from: code review of 5-2-retrait-du-legacy-zip-et-du-mirror.md (2026-06-08)
- Hardcoded artifact filenames (`kermesse-deploy.tar.gz`) [.github/workflows/deploy-ouvaton.yml] — ⚠️ PARTIEL Story 5.5 (2026-06-08) : centralisé côté scripts (scripts/lib/artifact.sh) ; chemins YAML upload/download-artifact laissés littéraux (une action ne peut pas sourcer un script — écart documenté).
- Dangerous manual JSON construction in bash [.github/workflows/deploy-ouvaton.yml]
- Pointless logic duplication (inline lftp in CI vs transfer-archive.sh) — Unifier requiert une adaptation non triviale (mapping de variables CI) dépassant le périmètre de nettoyage actuel. — ✅ RÉSOLU Story 5.5 (2026-06-08) : mapping OUVATON_DEPLOY_* → TARGET_* dans le step + appel de transfer-archive.sh.

## Deferred from: code review of 5-3-repetition-executee-en-ci-pour-la-parite-scripts-local-ci.md (2026-06-08)
- [Review][Defer] Use Docker Compose healthchecks instead of bash curl loop [.github/workflows/ci.yml:162] — deferred, pre-existing

## Deferred from: code review (2026-06-08)
- Parsing de checksum fragile `app/Services/ReleaseActivationService.php` (blind) - L'utilisation de `explode` sur l'espace est fragile. (Différé: hors périmètre strict PR)

## Deferred from: code review of 5-4-restaurer-la-gestion-separee-du-env-de-production.md (2026-06-09)
- Dette technique sur l'échappement en double (lftp-escape.sh vs sync-production-env.yml) - Le workflow gère son propre échappement (pas de checkout).

## Deferred from: code review of 3-3-creer-ou-reutiliser-le-compte-benevole-lors-de-l-inscription.md (2026-06-10)
- Mise à jour des informations bénévole — point à traiter globalement, y compris pour les owners
- Règles de suppression (Foreign Keys) — à traiter finement, pour différencier suppression des infos de la kermesse et gestion des utilisateurs

## Deferred from: one-shot fix CI mariadb drop-list (spec-epic-3-fix-ci-mariadb-drop-list.md) (2026-06-10)
- [HAUTE VISIBILITÉ — à traiter dans la PR #29] DROP TABLE destructif dans la migration [database/migrations_sql/20260610090000_create_volunteers_and_signups.sql:34] — ✅ RÉSOLU revue PR #29 (2026-06-10) : DROP retiré, ré-application idempotente vérifiée. — `DROP TABLE IF EXISTS signups;` avant CREATE : toute ré-application (reset de schema_versions + /ops/migrate, cf. scripts/deploy-rehearsal.sh) détruit silencieusement les inscriptions en production ; asymétrique (volunteers n'a pas de DROP). Retirer la ligne avant tout déploiement.
- Test d'intégration manquant pour la migration volunteers/signups [tests/database/MigrationRunnerMariaDBTest.php] — le pattern par-migration (stands, slots) n'a pas été étendu : ni uq_volunteers_kermesse_email ni les règles RESTRICT des FK signups ne sont vérifiées via MigrationRunnerService. Idem pour 20260607183000_update_ops_nonces_schema.sql (trou préexistant).
- Deux inventaires de tables maintenus à la main et sensibles à l'ordre FK [tests/database/MigrationRunnerMariaDBTest.php:41, .github/workflows/ci.yml:107] — remplacer le teardown par un drop dynamique (FOREIGN_KEY_CHECKS=0 + SHOW TABLES) et dériver la liste ci.yml des fichiers de migration pour éliminer la classe d'oubli.
- L'étape « Verify schema tables exist » est invalidée par l'étape suivante [.github/workflows/ci.yml:104] — les tests droppent le schéma vérifié de kermesse_test ; utiliser une 2e base dédiée aux tests destructifs ou documenter la destruction.
- Vérification d'existence seulement, unidirectionnelle [.github/workflows/ci.yml:107] — ne détecte ni tables inattendues ni forme des colonnes/index ; passer à une comparaison d'ensembles a minima.
- Liste de drop d'OpsMigrateEndpointMariaDBTest non durcie [tests/database/OpsMigrateEndpointMariaDBTest.php:37] — « blank database » inexact en CI ; cassera comme MigrationRunnerMariaDBTest si une future FK pointe vers ses tables.

## Deferred from: code review of 3-4-garantir-capacite-doublon-et-chevauchement (PR #29, 3 couches) (2026-06-10)
- Email de confirmation annoncé mais jamais envoyé [app/Views/public/signup_confirmation.php:15] — la page affirme « vous a été envoyé » sans aucun appel EmailService ni écriture email_events ; couvert par la story 3.5 (backlog). Ne pas merger l'epic 3 avant que 3.5 ne livre l'envoi réel.
- Fixtures admin avec volunteer_id=0 + paramètre $name mort [tests/feature/AdminLifecycleTest.php:764, AdminSlotTest.php:788, AdminStandTest.php:812, PublicVolunteerPageTest.php:1270] — valeurs violant la FK production, les feature tests SQLite ne peuvent pas détecter les bugs d'intégrité ; créer un vrai volunteer dans les helpers.
- Message de chevauchement sans date (H:i seul) [app/Controllers/Public/SignupController.php:143] — ambigu si kermesse multi-jours ; inclure la date quand elle diffère du créneau cible.

## Deferred from: code review of 1-1-reinitialiser-le-socle-en-greenfield-purge-legacy-init-codeigniter-runner-de-migration.md (2026-06-11)
- [Review][Defer] Duplication stricte de code entre `app.php` et `public.php` (`app/Views/layouts/`) — deferred, pre-existing
- [Review][Defer] `AuthFilter.php` redirige agressivement sans conserver l'URL initiale visée — deferred, pre-existing
- [Review][Defer] `AuthFilter.php` ne gère pas proprement les requêtes AJAX/API (retourne une redirection HTML) — deferred, pre-existing
- [Review][Defer] `LockingReadsTrait::forUpdateSuffix` hardcode "MySQLi" ce qui limite la portabilité (`app/Models/LockingReadsTrait.php`)
- [Review][Defer] Race condition potentielle avec les flash data de session pour la confirmation de signup (`app/Controllers/Kermesse/Public/SignupController.php`)
- [Review][Defer] Message d'erreur ambigu "slot_unavailable" si le créneau a été supprimé physiquement (`app/Services/SignupService.php`)
- [Review][Defer] L'email de confirmation peut partir avec un nom de stand vide si celui-ci est supprimé simultanément (`app/Services/SignupService.php`)

## Deferred from: code review of 1-3-demander-un-lien-de-connexion-magic-link.md (2026-06-11)
- [Review][Defer] `skipValidation(true)` dans TokenService [app/Services/TokenService.php:152] — deferred, mécanisme interne existant
- [Review][Defer] Désynchronisation de la timezone (date vs UTC) [app/Services/TokenService.php:149] — deferred, usage hérité
- [Review][Defer] Inconsistance de Dependency Management [app/Services/EmailService.php:109] — deferred, dette technique mineure

## Deferred from: code review of 1-4-valider-le-magic-link-et-creer-la-session-php-globale (2026-06-11)
- [Review][Defer] Brittle Timestamp Parsing [app/Services/TokenService.php] — deferred, pre-existing
- [Review][Defer] Tightly Coupled User Profile Defaults [app/Models/UserModel.php] — deferred, pre-existing
- [Review][Defer] Ambiguous Failure States [app/Services/TokenService.php] — deferred, pre-existing
- [Review][Defer] Unsalted Hashes for PII [app/Models/UserModel.php] — deferred, pre-existing

## Deferred from: code review of 1-5-afficher-l-accueil-connecte-tableau-de-bord-global-et-gerer-la-deconnexion.md (2026-06-11)
- [Review][Defer] Pas de pagination dans findKermessesForUser [app/Models/UserRoleModel.php] — deferred, pre-existing (acceptable pour le périmètre actuel)
- [Review][Defer] Pas de filtre par statut de kermesse [app/Models/UserRoleModel.php] — deferred, pre-existing (le statut des kermesses sera implémenté à l'Epic 2)
- [Review][Defer] Nettoyage tardif de l'email [app/Controllers/Auth/MagicLinkController.php] — deferred, pre-existing (fonctionnel mais non centralisé à l'entrée)
- [Review][Defer] Concurrence sur findByEmailHash [app/Models/UserModel.php] — deferred, pre-existing (cas extrême peu probable en l'état)

## Deferred from: code review of 2-2-ajouter-et-modifier-des-stands.md (2026-06-11)
- [Review][Defer] Méthode d'Update non RESTful (POST au lieu de PUT/PATCH) [Routes.php] — deferred, pre-existing
- [Review][Defer] Duplication du schéma BDD dans les tests [tests/feature/CreateKermesseTest.php] — deferred, pre-existing
- [Review][Defer] Condition de course sur le calcul de `display_order` [app/Controllers/Kermesse/Dashboard/StandController.php:40] — deferred, pre-existing
## Deferred from: code review of 2-3-ajouter-et-modifier-des-creneaux-avec-capacite.md (2026-06-11)
- Zero Overlap Prevention (slots can overlap)
- No Capacity Reduction Protection (dropping capacity below registered volunteers)

## Deferred from: code review of 2-4-supprimer-un-stand-avec-securite-destructive (2026-06-11)
- Hardcoded strings for confirmation and flash messages: Breaks localization but acceptable for MVP.
- Sloppy test teardowns using DELETE FROM: Uses DELETE FROM instead of TRUNCATE or transactions, which leaves auto-increment counters polluted.
- Brittle frontend JS event listeners: Binds on load instead of delegation.
- Blind input casting (int) for IDs: Invalid string IDs silently become 0 instead of 400 Bad Request.

## Deferred from: code review of 2-4-supprimer-un-stand-avec-securite-destructive (2026-06-11) Round 2
- Missing Audit Logging: No audit trail for stand deletion.
- Unlocalized Error/Success Messages: Hardcoded French strings.
- Test Setup using explicit CREATE TABLE: Divergence risk vs real migrations.
- Missing Rate Limiting / Brute Force Protection: POST route lacks throttling.
- Poor Defensive Programming on Inputs: Blind (int) cast.

## Deferred from: code review of 2-5-gerer-l-etat-d-ouverture-de-la-kermesse.md (2026-06-12)
- Tight Coupling Precluding True Unit Testing (`new KermesseLifecycleService()`)
- Superficial Test Assertions (tests très basiques)
- Fictional Test Schema Setup (RAW CREATE TABLE au lieu de migrations)
- Hardcoded Database Prefixes in Test (`db_` hardcodé)
- Manual Data Teardown Over Transactions (DELETE au lieu de transactions/refresh)

## Deferred from: code review of 3-1-afficher-la-page-publique-de-la-kermesse.md (2026-06-12)
- Modifications du CI/CD (deploy-ouvaton.yml) — C'est un chantier DevOps séparé de la story produit 3.1

## Deferred from: code review of 3-2-s-inscrire-a-un-creneau-visiteur-non-connecte-et-rattachement-par-email.md (2026-06-12)

- Naive Exception Swallowing Destroys Transactions: Catching \Throwable around the divergence insert to prevent aborting signup. Fails on strict DB drivers, but MariaDB handles it gracefully. Deferred as pre-existing architecture design.


## Deferred from: code review of 3-3-s-inscrire-a-un-creneau-utilisateur-connecte.md (2026-06-12)
- Hostile UX (No Edit Path): Locked profile data has no link to update it.
- Brittle Dual-Form Architecture: signup_form.php maintains two separate form structures instead of one reused form.
- Fragile View Assertions: Tests rely on loose assertStringContainsString checks instead of structural DOM assertions.

## Deferred from: code review of 3-5-envoyer-l-email-de-confirmation-benevole.md (2026-06-12)
- [Review][Defer] Unbounded Token Proliferation — No logic to revoke or recycle existing magic links.
- [Review][Defer] Synchronous Bottleneck — Token generation is executed synchronously during critical signup path.

## Deferred from: code review of 3-6-resoudre-les-divergences-de-profil-a-la-connexion.md (2026-06-12)
- Duplicated Test Infrastructure for db_profile_divergences raw SQL [tests/feature/ProfileResolutionTest.php]

## Pre-existing issues found during cherry-pick of f19b7a0

The following issues were found by the review agents (Adversarial/Edge Case) in the cherry-picked code. They have been deferred as they are pre-existing vulnerabilities in the original authored commit and require a separate story to fix:
- Tar Bomb Vulnerability: No extraction limits in PharData -> extractTo.
- CPU/Memory Exhaustion: Custom PHP tar parser (validateTarGzArchive) uses iterative stream reads lacking limits.
- Parser Differential: Validating with custom parser but extracting with PharData allows path validation bypass.
- Integer Overflow: octdec returns float when exceeding PHP_INT_MAX, breaking payload skipping logic.
- Fragile Rejection of Valid Tar Types: Rejects global extended headers (type g).
- Timezone Desync: fetch-ouvaton-logs.yml uses UTC date, failing if Ouvaton server is in a different timezone.
- Wasteful apt-get: fetch-ouvaton-logs.yml runs apt-get update every time.
- Inadequate Path Traversal Protections: Windows absolute paths (C:/) bypass the leading slash check.
- LFTP Variable Expansion: lftp_quote wraps in double quotes, expanding $ in credentials.
- Newline Injection: bash tar validation can be bypassed by filenames with newlines.
- Unhandled Exceptions: string manipulation in parser lacks try/catch, fataling out ungracefully.

## Deferred from: code review of 4-1-afficher-le-tableau-de-bord-interne-par-role.md (2026-06-13)
- Les fixtures Feature ajoutées répètent la dette préexistante de schéma SQL manuel non portable et sans Fabricator [tests/feature/DashboardRoleSectionsTest.php:62] — la suite contient déjà ce pattern sur les tests Feature historiques ; traiter via une story de refonte des helpers/factories de tests plutôt que dans le périmètre de Story 4.1.

## Deferred from: code review of 4-2-afficher-mes-participations-benevole.md (2026-06-13)
- Blacklist vs. Whitelist for Status [app/Models/SignupModel.php] — deferred, pre-existing convention
- Redundant Schema Duplication in Tests [tests/feature/MyParticipationsTest.php] — deferred, pre-existing test pattern
- Manual Database Teardown in Tests [tests/unit/SignupModelTest.php] — deferred, pre-existing test pattern
- Inline CSS Pollution [app/Views/kermesse/dashboard.php] — deferred, pre-existing UI pattern

## Deferred from: code review (2026-06-13) [4-4-afficher-la-gestion-des-participants-admin-gestionnaire.md]
- Unbounded data loading in findActiveParticipantsForKermesse (Blind Hunter)
- Missing exception handling for date parsing (Time::parse) (Blind Hunter)
- In-View CSS Bloat in dashboard.php (Blind Hunter)
- Blind trust in capacity constraints (assumes occupied <= capacity) (Blind Hunter)

## Deferred from: code review of 5-1-modif-3-2-tracer-les-modifications-d-inscription.md (2026-06-15)
- Security Anti-Pattern / Missing Email Verification in ProfileController::update()
- RoleService: TOCTOU race condition on role deletion

## Deferred from: code review of 5-2-modif-4-1-navigation-par-4-onglets-et-suivi-d-acces-par-kermesse.md (2026-06-15)
- Duplication de schéma de test — Copier-coller de requêtes SQL brutes de schéma dans 11 fichiers de test

## Deferred from: code review of 5-3-modif-4-4-renommer-gestion-des-inscrits-et-badge-de-modification.md (2026-06-15)
- Incomplete Modifier Identification (first name only)
- Potential Soft-Delete Leak in Joins
- Brittle Test Data Dependency (assumes Admin name)

## Deferred from: code review of 5-4-modif-3-6-confirmation-1re-connexion-et-reconciliation-par-kermesse.md (2026-06-15)
- [Review][Defer] Worthless Phone Number Validation — deferred, pre-existing
- [Review][Defer] Destroyed Divergence Audit Trail — deferred, pre-existing

## Deferred from: code review of 5-5-modif-4-5-onglet-equipe-membres-invitation-et-reinvitation.md (2026-06-15)
- Inconsistent CSS Architecture [public/assets/css/app.css] — deferred, pre-existing

## Deferred from: code review of 5-6-dupliquer-un-stand.md (2026-06-15)
- Fragile Double-Submit Protection (UI)
- Content Security Policy (CSP) Violations (Inline JS)
- CSRF Token Proliferation (in loops)

## Deferred from: discussion sur la gestion de profil (Story 5.7)
- Feature Idea: Permettre la fusion de deux comptes (merge) si un utilisateur a légitimement utilisé deux adresses e-mails différentes dans le passé.
- Feature Idea: Supporter les e-mails multiples pour un même utilisateur (pour éviter de recréer le problème après une fusion).

## Deferred from: code review of 5-7-gerer-ses-propres-coordonnees-page-profil (2026-06-15)
- Texte Codé en Dur (Anti-pattern d'i18n) [app/Controllers/Kermesse/Dashboard/KermesseAdminController.php:12] : La chaîne 'un administrateur' est codée en dur plutôt que d'utiliser `lang()`.
- Suppression Silencieuse des Traces d'Exceptions [app/Controllers/Kermesse/Dashboard/KermesseAdminController.php:18-19] : Bloc `catch (\Throwable)` sans journaliser l'objet exception, perdant ainsi la stack trace.
- Cauchemar de Maintenance avec des Schémas SQL Dupliqués [tests/feature/ProfileUpdateTest.php] : Utilisation de très longues requêtes SQL brutes pour la structure des tables au lieu d'utiliser des migrations ou Fabricator.

## Deferred from: code review of 5-8-onglet-equipe-revoquer-un-role.md (2026-06-15)
- Raw SQL table creation in unit tests [tests/unit/RoleServiceRemoveRoleTest.php]
- No self-lockout protection [app/Views/kermesse/dashboard.php]
- Incomplete test coverage for inactive signups [tests/unit/RoleServiceRemoveRoleTest.php]
- Race condition dans hasActiveSlotSignups : lecture + mutation non atomique (check-then-act sans transaction) [app/Services/RoleService.php] — pré-existant, patron cohérent avec le reste du codebase.
- Slots/stands soft-deletés non filtrés dans hasActiveSlotSignups : la jointure n'exclut pas deleted_at IS NOT NULL [app/Services/RoleService.php] — ✅ CLOS (checkpoint review 2026-06-15, faux positif) : l'invariant documenté SignupModel:192-203 garantit que la suppression d'un stand/créneau cascade sur le statut de l'inscription ; filtrer le statut seul suffit, cohérent avec l'approche canonique.
- Définition d'« actif » divergente dans hasActiveSlotSignups (`= 'active'` vs `whereNotIn(INACTIVE_STATUSES)`) [app/Services/RoleService.php] — ✅ RÉSOLU (checkpoint review 2026-06-15) : aligné sur SignupModel::INACTIVE_STATUSES + 2 tests de verrou.
- Auto-révocation : un Admin peut soumettre son propre userId à l'endpoint et se rétrograder en bénévole ; aucun contrôle en amont du contrôleur [app/Controllers/Kermesse/Dashboard/KermesseAdminController.php::removeTeamMember].
- resendInvitation() ne vérifie pas que le rôle cible est Admin/Gestionnaire : peut envoyer un magic link à un bénévole avec le label `benevole` dans l'email [app/Services/RoleService.php::resendInvitation].
- Clé flash `invite_success` réutilisée pour la confirmation de révocation — couplage sémantique fragile.
- `data-member` JSON dans les attributs DOM expose potentiellement des PII (email) côté HTML [app/Views/kermesse/dashboard.php] — onglet admin uniquement ; risque confiné mais à évaluer.

## Deferred from: code review (5-10-onglet-gestion-des-inscrits-annuler-et-corriger-une-inscription)
- Catastrophic DRY Violation in Test Schemas: Modifies over a dozen test files to update `CREATE TABLE db_signups`.
- Inadequate Notification Context: Cancellation email only notifies the user of the kermesse name, omitting stand, date, time slot.
- Hardcoded Presentation Strings: `AdminSignupController` uses hardcoded French flash messages.
## Deferred from: code review of 5-10-onglet-gestion-des-inscrits-annuler-et-corriger-une-inscription.md (2026-06-16)
- Absence d'internationalisation (lang()) dans les vues et mails (dette technique préexistante).

## Deferred from: code review of 5-11-onglet-gestion-des-inscrits-ajouter-une-inscription-manuellement.md (2026-06-16)
- Brute-Force Type Juggling [app/Controllers/Kermesse/Dashboard/AdminSignupController.php]
- Hardcoded Validation Rules [app/Controllers/Kermesse/Dashboard/AdminSignupController.php]
- Leaky DTO Design [app/Services/AdminCreateSignupDTO.php]
- Hidden Dependencies [app/Services/SignupService.php]
- Brittle Routing in Views [app/Views/kermesse/dashboard.php]
- CSP Violations Waiting to Happen [app/Views/kermesse/dashboard.php]

## Deferred from: code review of story-5.14 (2026-06-17)

- Migration orphelin `cancelled` → `canceled_by` NULL → reclassé `removed` (database/migrations_sql/20260619000000...sql:17). Historique, probabilité quasi-nulle.
- Migration : `updated_at` utilisé comme horodatage d'annulation/refus (backfill one-shot imprécis).
- Authz par correspondance email dans `markAccepted/markRejected` (app/Models/SignupModel.php) — borné par `id`, `orWhere('email')` redondant après rattachement orphelins au login.
- `new SignupService(...)` instancié en dur dans les contrôleurs — cohérent avec pattern existant ; refactor DI global = tâche dédiée.
- Pas de DTO `readonly` pour `acceptSignup/rejectSignup` (3 scalaires).
- Cosmétique : Historique masque l'auto-annulation bénévole si stand supprimé ensuite ; orphelin admin sans snapshot → nom vide en liste PII ; tri `ORDER BY u.last_name` met les NULL en tête.
- transBegin() vs transStart() (app/Services/SignupService.php:80,185,273,364,437) — à échanger en checkpoint avec l'architecte ; justification race d'insertion documentée, code pré-existant.

## Deferred from: code review of 6-1-corriger-ajout-creneau-kermesse-ouverte.md (2026-06-18)

- Rejeter une kermesse sans `event_date` au lieu d'utiliser silencieusement la date courante (`app/Controllers/Kermesse/Dashboard/SlotController.php:44`) — comportement préexistant hors du correctif Story 6.1.

## Deferred from: code review of 5-3-modif-4-4-renommer-gestion-des-inscrits-et-badge-de-modification.md (2026-06-19)

## Deferred from: code review of 6-4-automatiser-les-parcours-critiques-du-benevole.md (2026-06-19)

- [Review][Defer] Isoler la stack Compose E2E pour que son cleanup n’arrête pas la stack de développement [`scripts/e2e.sh:30`] — déjà planifié pour la Story 6.7.

- [Review][Defer] Définir le rendu d’un audit partiel (`last_modified_by_user_id` sans date ou utilisateur joint absent) — deferred : je n’ai pas les éléments sur la manière dont ce badge s’affiche.

## Deferred from: discussion E2E strategy (Story 6.3, 2026-06-19)

- [Idea] Mode `--with-staging` pour `scripts/e2e.sh` : copier la base de dev locale (`kermesse`) vers `kermesse_e2e` avant d’appliquer les migrations et les fixtures E2E par-dessus. Utile pour tester des cas limites déjà construits manuellement. Implémentation : ajouter un `mysqldump kermesse | mysql kermesse_e2e` conditionnel en tête du script, avant le step de migration.

- [Idea] Dataset pré-migration pour tester les migrations sur données existantes : créer un dump SQL représentatif de l’état de la base *avant* une migration donnée, l’appliquer sur une base vierge, jouer la migration par-dessus, puis vérifier l’état résultant. Complète les smoke tests applicatifs (qui partent d’un schéma final propre) en validant que les migrations de données (backfills, `ALTER TABLE` sur colonnes existantes) ne corrompent pas les lignes préexistantes. À traiter dans **Story 6.6** ("Fiabiliser les preuves MariaDB sur les parcours critiques").

## Deferred from: code review of 6-5-automatiser-les-parcours-critiques-des-organisateurs.md (2026-06-20)
- [Review][Defer] App Bug: PRG redirects to invalid tab id [e2e/tests/organizer-dashboard.spec.ts]
- [Review][Defer] Unscalable Magic Link Fixtures [e2e-setup.sql]
- [Review][Defer] Fragile SQL Teardown Scripts [e2e-setup.sql]

## Deferred from: code review of 6-7-installer-les-gates-ci-de-preparation-a-la-production.md (2026-06-21)
- [Review][Defer] Blind Trust in E2E Script Exit Codes: The `e2e-playwright` job executes `bash scripts/e2e.sh`. Unless it has strict `-e` exit code forwarding, failures in `docker compose up` might be masked with exit code 0.
- [Review][Defer] PHPStan Level too low: PHPStan configured at level 5, which leaves complex parts exposed to type errors.
- [Review][Defer] Baseline hides real issues: The 319-line baseline file masks logical bugs (like offset errors) and dead code warnings rather than fixing them.
- [Review][Defer] PHPStan bootstrap poisoning: `phpstan.neon.dist` uses test environment bootstrap loader, distorting analysis of production execution paths.


## Deferred from: code review of 6-8-gerer-l-expiration-de-session-avec-redirection-gracieuse (2026-06-21)
- Message flash codé en dur (internationalisation) : Le message d'erreur est codé en dur au lieu d'utiliser le système de traduction.
- Couplage avec la clé de session user_id : Lecture directe de `session()->get('user_id')` au lieu d'utiliser un service d'authentification.
- Accès direct à $_SESSION dans les tests : Contournement des mécanismes de test via `$_SESSION`.

## Deferred from: code review of 6-9-qualifier-et-deployer-la-release-candidate (2026-06-22)

- [Review][Defer] Définir la stratégie atomique migration/activation/rollback [.github/workflows/deploy-ouvaton.yml:399] — c'est précisément le but de la Story 6.10, qui porte la sécurisation des migrations et du rollback avant tout Go production.

## Deferred from: code review of 6-8bis-resoudre-la-dette-technique-ci-deferred-findings-de-la-story-6-7 (2026-06-22)

- [Review][Defer] Fiabiliser la relecture après une collision concurrente sous isolation transactionnelle [app/Services/RoleService.php:294] — le chemin existant relit immédiatement la ligne gagnante sans stratégie de retry ni garantie qu'elle soit visible dans le snapshot courant.

## Deferred from: code review of 6-10-securiser-le-deploiement-des-migrations-incompatibles (2026-06-22)

- [Review][Defer] Missing Test Coverage for Infrastructure Failures [tests/database/MigrationDriftReconcileMariaDBTest.php] — deferred, pre-existing
- [Review][Defer] Absence of Rate Limiting or Brute Force Protection [app/Controllers/Ops/DriftController.php] — deferred, pre-existing

## Deferred from: code review of 6-11-restaurer-l-execution-reelle-des-tests-d-integration-mariadb-en-ci (2026-06-23)
- [Review][Defer] Adoption de Fabricator et remplacement des INSERT bruts — Reporté en attente d'une refonte des helpers de test (bug API v4.7).
- [Review][Defer] Duplication de configuration ($migrate = false) [tests] — Violation DRY mineure.
- [Review][Defer] Exceptions SQL génériques pour lock timeout [app/Models/SlotModel.php] — Respecte la spec mais pourrait utiliser une exception plus typée.
- [Review][Defer] Horodatages hardcodés (2099, 2026) dans les inserts [tests/database/RoleServiceMariaDBTest.php] — Pratique fragile mais non bloquante.
- [Review][Defer] Test TOCTOU basé sur modification synchrone [tests/database/MigrationDriftReconcileMariaDBTest.php] — Difficile d'émuler une vraie course concurrente sans outils externes.
- [Review][Defer] Couplage instanciation models avec $db2 [tests/database/SlotSignupInvariantsMariaDBTest.php] — Requis par la spec pour la concurrence.
- [Review][Defer] Configuration via .env temporaire pour repro locale [project-context] — Toléré selon la spec.

## [defer] ExampleDatabaseTest.php manque $migrate=false (surfacé par 6.11)

- **Fichier** : `tests/database/ExampleDatabaseTest.php`
- **Problème** : Pas de `$migrate = false` ni `$refresh = false`. Masqué par le filtre `--group mariadb` dans `composer test:mariadb`, mais si on exécute `phpunit --configuration phpunit.mariadb.xml --testsuite Database` sans le filtre `--group`, CI4 tentera de migrer via son runner natif et échouera (table `factories` absente du schéma SQL).
- **Priorité** : Faible — pré-existant, hors périmètre story 6.11.

## Deferred from: checkpoint spec/test-stabilization-post-epic6 (2026-06-27)

- **Test unitaire manquant — `SlotSignupService::autoAcceptUnconfirmedAfterLogin`** [`app/Services/SlotSignupService.php:764`] — L'invariant orphan-only (`created_by IS NULL`) de Story 5.14 n'est couvert par aucun test PHPUnit. Le bug original (auto-confirmation des inscriptions admin au login) a été attrapé par les E2E, pas par la suite unitaire. Un test feature sur `MagicLinkVerifyTest` avec deux cas (inscription `created_by IS NULL` → confirmée, inscription `created_by = adminId` → non confirmée) protégerait cet invariant. **Véhicule : story 7-2.**

- **Double surface `$canModify` pour les boutons lifecycle** [`app/Views/kermesse/dashboard.php:45` et `app/Views/kermesse/dashboard.php:134`] — Les boutons Ouvrir/Fermer/Paramètres existent désormais dans deux zones : le header persistent (depuis `8eea3e6`) et potentiellement dans l'onglet si `$canModify` est mal passé. Pas un bug actuel, mais deux surfaces à maintenir en cohérence. À consolider si la logique de visibilité évolue. **Priorité : faible.**

## Deferred from: code review of 6-12-restaurer-le-job-e2e-playwright-en-ci.md (2026-06-23)
- Contradiction in Readiness Fix for automated runs — deferred: aucun changement de code. La readiness est garantie correctement par la boucle bash. La "contradiction" est une redondance saine : si on supprimait le healthcheck Compose, on perdrait la visibilité health et le support docker compose run manuel sans rien gagner en CI.
- Excessive healthcheck start period (120s) [docker-compose.yml]
- Log file overwriting and concurrency risks [scripts/e2e.sh]
- Naive Compose project name extraction / Empty project name [scripts/e2e.sh]
- Silent volume creation failures [scripts/e2e.sh]
- Unsafe Docker network inspection [scripts/e2e.sh]
- Inefficient use of npm ci with persistent volumes [scripts/e2e.sh]
- Hardcoded Playwright Docker Image Version [scripts/e2e.sh]
- Missing path upload warning in CI [.github/workflows/ci.yml]
- app container multiple networks [scripts/e2e.sh]
