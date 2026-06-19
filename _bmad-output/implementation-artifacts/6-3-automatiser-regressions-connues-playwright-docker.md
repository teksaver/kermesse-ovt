# Story 6.3: Automatiser les trois régressions connues avec Playwright

---
baseline_commit: 743ce563f95c6561cafbf5ca45460b7e008855df
---

Status: review

## Story

As an équipe de livraison,
I want exécuter les parcours critiques dans un vrai navigateur,
so that les régressions JavaScript et d’intégration soient détectées avant fusion.

## Acceptance Criteria

1. **Given** le dépôt Kermesse, **when** l’environnement E2E est installé, **then** Playwright est une dépendance de développement uniquement, aucun build JavaScript n’est requis en production, et les fichiers Playwright et Node sont exclus de l’archive Ouvaton.
2. **Given** la configuration Playwright, **when** les tests sont exécutés, **then** deux profils Chromium sont disponibles — mobile et desktop — et les sélecteurs utilisent prioritairement les rôles accessibles, libellés ou `data-testid` stables.
3. **Given** une machine de développement ou un runner CI disposant de Docker, **when** la suite Playwright est lancée, **then** elle s’exécute dans un service Docker dédié basé sur une image Playwright officielle épinglée et alignée avec la dépendance du projet ; aucun runtime Node, navigateur ou dépendance Playwright n’est requis sur l’hôte ; la même image et la même commande sont utilisées localement et en CI.
4. **Given** l’environnement Docker E2E, **when** les scénarios démarrent, **then** l’application et MariaDB sont accessibles via le réseau interne Docker, des contrôles de santé empêchent le lancement prématuré, et traces, captures d’écran, rapports et journaux sont écrits dans un répertoire récupérable depuis l’hôte ou la CI.
5. **Given** les données nécessaires aux scénarios, **when** les fixtures sont préparées, **then** elles sont fictives et reproductibles, ne dépendent jamais de la production, et aucun endpoint de préparation n’est accessible hors environnement `testing`.
6. **Given** un bénévole possédant plusieurs inscriptions actives, **when** il ouvre « Mes participations », **then** toutes ses inscriptions sont visibles après l’initialisation JavaScript et restent visibles après rechargement complet.
7. **Given** un Admin connecté et un autre membre de l’équipe, **when** l’onglet Équipe est affiché, **then** la ligne de l’utilisateur courant porte « Vous », les actions interdites sur lui-même sont absentes, et l’autre membre conserve ses actions autorisées.
8. **Given** une kermesse ouverte et un stand actif, **when** un Owner ou Admin ajoute un créneau depuis l’interface, **then** le succès est visible, le créneau apparaît dans le dashboard, persiste après rechargement et apparaît sur la page publique.
9. **Given** l’exécution d’un scénario E2E, **when** une exception JavaScript, une réponse HTTP inattendue ou une erreur console non autorisée survient, **then** le test échoue.
10. **Given** un échec Playwright en CI, **when** les artefacts sont collectés, **then** trace, capture d’écran et journaux navigateur sont conservés pour diagnostic.
11. **Given** les trois scénarios, **when** la suite smoke est exécutée plusieurs fois, **then** aucun test flaky n’est toléré avant son passage en gate bloquant.

## Tasks / Subtasks

- [x] Installer le socle Node/Playwright sans introduire de build frontend de production (AC: 1, 2, 3)
  - [x] Créer `package.json` et le lockfile avec `@playwright/test` en `devDependencies`, épinglé à `1.60.0`.
  - [x] Créer `playwright.config.ts` avec deux projets Chromium explicites : desktop et mobile à 320 px minimum.
  - [x] Configurer `testDir`, `baseURL`, timeouts, zéro retry masquant une instabilité, rapport HTML/JUnit, trace et capture sur échec.
  - [x] Ajouter à `.gitignore` les sorties E2E (`playwright-report/`, `test-results/`, éventuels états d’authentification), sans ignorer les specs ni les fixtures versionnées.
- [x] Ajouter le service Docker E2E et une commande unique (AC: 3, 4)
  - [x] Étendre `docker-compose.yml` avec un profil/service `e2e` basé sur `mcr.microsoft.com/playwright:v1.60.0-noble`, sans installation de navigateur sur l’hôte.
  - [x] Aligner strictement l’image Docker et `@playwright/test` sur `1.60.0`; monter le dépôt en lecture appropriée et les sorties E2E vers l’hôte.
  - [x] Utiliser les noms de services Docker (`app`, `db`) et leurs healthchecks; ne jamais cibler `localhost` depuis le conteneur E2E.
  - [x] Fournir une commande versionnée unique, réutilisable telle quelle localement et en CI, qui démarre les dépendances, attend leur santé, prépare les fixtures, exécute les tests puis propage le code de sortie.
  - [x] Garantir le nettoyage reproductible des données et conteneurs sans supprimer les artefacts d’échec.
- [x] Mettre en place les fixtures E2E isolées (AC: 5)
  - [x] Préparer une kermesse `open`, un stand actif, des créneaux, un bénévole avec plusieurs inscriptions actives, un Owner, un Admin et un autre membre d’équipe.
  - [x] Générer uniquement des identités fictives déterministes; aucun secret ni donnée de production.
  - [x] Si un endpoint de setup/reset est retenu, le déclarer uniquement quand `ENVIRONMENT === 'testing'`, le rendre inaccessible en développement/production, et tester explicitement ce verrouillage. Préférer un mécanisme de fixture interne au réseau Docker et au profil `e2e`.
  - [x] Authentifier les scénarios via le flux réel ou une fixture de session strictement test-only; ne pas affaiblir `AuthFilter`, `RoleFilter`, CSRF ou les invariants métier.
- [x] Automatiser la régression « Mes participations » (AC: 6, 9, 11)
  - [x] Ouvrir le dashboard en tant que bénévole et vérifier l’affichage de toutes les inscriptions après `DOMContentLoaded`.
  - [x] Recharger complètement la page et vérifier de nouveau la liste; couvrir le cas sans sidebar où le seul panneau doit recevoir `is-open`.
- [x] Automatiser la régression « Vous » dans l’équipe (AC: 7, 9, 11)
  - [x] Ouvrir l’onglet Équipe en tant qu’Admin, identifier la ligne courante par son contenu accessible et vérifier le badge « Vous ».
  - [x] Vérifier l’absence d’auto-révocation/auto-action et la présence des actions autorisées sur l’autre membre.
- [x] Automatiser la régression d’ajout de créneau sur kermesse ouverte (AC: 8, 9, 11)
  - [x] Soumettre le formulaire réel avec CSRF en tant qu’Owner ou Admin et attendre le résultat PRG visible.
  - [x] Vérifier le créneau dans le dashboard, après reload, puis sur `/k/{public_slug}`; ne pas valider uniquement la base.
- [x] Installer les garde-fous et preuves diagnostiques (AC: 4, 9, 10, 11)
  - [x] Échouer sur `pageerror`, erreurs console non autorisées et réponses HTTP inattendues; maintenir une allowlist minimale, documentée et précise.
  - [x] Utiliser les assertions web-first et l’auto-wait Playwright; interdire les temporisations arbitraires et les retries silencieux.
  - [x] Exécuter la smoke suite répétée pour démontrer sa stabilité et consigner la commande/résultat dans le Dev Agent Record.
- [x] Protéger l’archive Ouvaton (AC: 1)
  - [x] Mettre à jour `scripts/package-deploy-artifact.sh` pour refuser/exclure `node_modules`, `package*.json`, `playwright.config.*`, specs/fixtures E2E, rapports, traces et captures.
  - [x] Étendre `tests/shell/package-deploy-artifact.test.sh` afin que la présence de chacun de ces artefacts fasse échouer le garde-fou ou soit prouvée absente de l’archive.
  - [x] Préserver le contrat existant : archive autonome PHP avec `vendor/` et assets statiques, aucun `npm`, Node ou build en production.
- [x] Mettre à jour la matrice de couverture (AC: 6–8, 11)
  - [x] Passer les trois régressions smoke concernées et G09 au statut couvert avec les chemins exacts des specs.
  - [x] Ne pas déclarer couverts les parcours étendus réservés aux Stories 6.4/6.5 ni les gates CI de la Story 6.7.

## Dev Notes

### Contrat et limites de périmètre

- Cette story livre l’infrastructure Docker Playwright et exactement trois smoke tests de non-régression. Les parcours bénévoles et organisateurs exhaustifs restent en 6.4/6.5. La promotion en check PR obligatoire et les gates complètes restent en 6.7.
- La commande doit être utilisable par un poste ou un runner qui ne possède que Docker. Ne pas demander `npm`, Node ou Chromium sur l’hôte.
- Le frontend reste constitué d’assets statiques locaux. `package.json` sert uniquement aux tests; aucun bundler, transpileur ou étape de build frontend ne doit apparaître dans le runtime ou le packaging.

### État actuel à préserver

- `public/assets/js/app.js` contient seulement les améliorations progressives globales. La navigation dashboard est aujourd’hui inline dans `app/Views/kermesse/dashboard.php` : elle ajoute `body.js-active`, ouvre le panneau demandé par le hash, et, en vue à panneau unique sans sidebar, ajoute `is-open` au seul panneau. Le smoke « Mes participations » doit protéger précisément ce fallback.
- `KermesseAdminController::show()` prépare `myParticipations`, `teamMembers`, `tabs`, `currentUserId` et `signupsOpen`. Il résout aussi les inscriptions orphelines. Les tests E2E consomment ce contrat; ils ne doivent pas déplacer de logique métier dans la vue ni contourner le service.
- La vue dashboard omet du DOM les onglets et actions non autorisés. Pour le scénario Équipe, vérifier l’absence réelle des actions sur l’utilisateur courant, pas seulement un état CSS désactivé.
- L’ajout de créneau passe par `SlotController` → `CreateSlotDTO` readonly → `SlotService`, avec PRG, contrôle du rôle et revalidation du scope kermesse/stand. Le test E2E doit traverser l’UI et préserver cette frontière; aucune insertion directe ne remplace l’action testée.
- `docker-compose.yml` fournit déjà `app`, `db`, `mailpit` et un profil `rehearsal`; réutiliser le réseau et les healthchecks existants. Ne pas dupliquer une seconde stack applicative.
- `scripts/package-deploy-artifact.sh` exclut déjà les dossiers racine `node_modules` et `tests`, installe Composer avec `--no-dev`, puis inspecte l’archive. Étendre ces listes et validations plutôt que créer un second packager.

### Architecture et sécurité

- PHP 8.2, CodeIgniter 4.7, MariaDB en cible réelle; SQLite reste réservé aux tests rapides. Les fixtures E2E utilisent MariaDB et les migrations SQL applicatives réelles.
- Aucun endpoint de test ne doit être enregistré en production. Un simple secret sur une route de production est insuffisant : la route elle-même doit être absente hors `testing`.
- Conserver CSRF, PRG, séparation Controller/Service/DTO, isolation multi-kermesse et absence de PII sur les pages publiques.
- Les traces peuvent contenir des données de test ou des cookies. Elles sont des artefacts temporaires non versionnés, avec rétention CI bornée; elles ne vont jamais dans l’archive Ouvaton.
- Sélecteurs : `getByRole`, `getByLabel`, `getByText` avec périmètre stable, puis `data-testid` seulement si l’interface accessible ne suffit pas. Ne pas cibler des classes CSS de présentation ou des positions DOM.
- Écouter `page.on('pageerror')`, `page.on('console')` et les réponses; exclure explicitement les redirections HTTP attendues du mécanisme « réponse inattendue ».

### Fichiers attendus

- **NEW** : `package.json`, lockfile Node, `playwright.config.ts`, dossier de specs/fixtures E2E et script/commande d’orchestration si nécessaire.
- **UPDATE** : `docker-compose.yml`, `.gitignore`, `scripts/package-deploy-artifact.sh`, `tests/shell/package-deploy-artifact.test.sh`, `_bmad-output/planning-artifacts/test-coverage-matrix.md`.
- **UPDATE uniquement si nécessaire pour des sélecteurs stables ou un verrou de fixture** : `app/Views/kermesse/dashboard.php`, `app/Views/partials/stand_group.php`, `app/Config/Routes.php` et un contrôleur/service test-only dédié. Lire intégralement et préserver les comportements décrits ci-dessus avant modification.
- Ne pas modifier `.github/workflows/ci.yml` pour installer le gate bloquant complet : ce raccordement appartient à 6.7. La commande Docker créée ici doit néanmoins être directement réutilisable par ce futur job.

### Exigences de test

- Les trois specs doivent réussir sous les projets Chromium desktop et mobile; le mobile doit réellement exercer un viewport de 320 px pour préserver le contrat UX.
- Chaque test part d’un état déterministe et peut être rejoué seul, dans n’importe quel ordre. Aucun partage implicite d’état ou dépendance à une spec précédente.
- Vérifier le packaging via les tests shell existants. Les tests PHPUnit existants (`MyParticipationsTest`, `TeamMembersTabTest`, `ManageSlotsTest`, `ManageSlotsMariaDBTest`) restent la preuve serveur et ne doivent pas être dupliqués assertion par assertion en E2E.
- La validation finale inclut au minimum : smoke suite répétée, tests shell de packaging et suites PHP touchées. Toute instabilité est un défaut à corriger, pas un motif pour augmenter les retries.

### Intelligence de la story précédente et de Git

- La Story 6.2 a créé `_bmad-output/planning-artifacts/test-coverage-matrix.md` et identifié G09 (navigation onglets JavaScript) comme lacune P1. Elle confirme aussi que l’E2E navigateur est totalement absent et que la dette de schémas SQL dupliqués relève de 6.6.
- Les commits récents montrent les trois causes à figer : `62988b3` (participations masquées par le JS), `2e1514a` (détection « c’est moi »), `743ce56` (créneau sur kermesse ouverte via Service/DTO et preuve MariaDB). Réutiliser leurs contrats au lieu de recréer des flux parallèles.

### Informations techniques actuelles

- Au 19 juin 2026, la documentation officielle expose Playwright `1.60.0` et l’image `mcr.microsoft.com/playwright:v1.60.0-noble`. L’image et `@playwright/test` doivent avoir exactement la même version, faute de quoi les exécutables navigateur peuvent être introuvables.
- L’image officielle contient les navigateurs et dépendances système, mais pas nécessairement la dépendance de projet : conserver le lockfile et installer les dépendances dev dans le conteneur/volume prévu, jamais sur l’hôte ni en production.
- Les projets Playwright sont le mécanisme officiel pour décliner une même suite en profils desktop/mobile. Utiliser les `devices`/viewports du config plutôt que dupliquer les specs.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-63--Automatiser-les-trois-régressions-connues-avec-Playwright]
- [Source: _bmad-output/planning-artifacts/test-coverage-matrix.md#22-Régressions-connues]
- [Source: _bmad-output/planning-artifacts/test-coverage-matrix.md#23-Récapitulatif-des-lacunes-P0P1-et-plan-daction]
- [Source: _bmad-output/planning-artifacts/architecture.md#Test-Organization]
- [Source: project-context.md#Technology-Stack--Versions]
- [Source: project-context.md#Testing-Rules]
- [Source: _bmad-output/implementation-artifacts/6-2-cartographier-parcours-critiques-et-couverture.md#Dev-Notes]
- [Playwright — Docker](https://playwright.dev/docs/docker)
- [Playwright — Projects](https://playwright.dev/docs/test-projects)

## Dev Agent Record

### Agent Model Used

À renseigner par l’agent de développement.

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created

### File List

