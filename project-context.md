---
project_name: 'Kermesse'
user_name: 'Sylvain'
date: '2026-06-12'
sections_completed: ['technology_stack', 'language_specific', 'framework_specific', 'testing', 'code_quality', 'development_workflow', 'critical_dont_miss']
existing_patterns_found: 19
---

# Project Context for AI Agents

_This file contains critical rules and patterns that AI agents must follow when implementing code in this project. Focus on unobvious details that agents might otherwise miss._

---

## Technology Stack & Versions

- **Backend & Core**:
  - **PHP**: ^8.2
  - **Framework**: CodeIgniter 4 (^4.7)
  - **Dépendances**: Composer
- **Frontend**:
  - Assets statiques locaux (`public/assets`). **Aucun build d'assets en production** (ni npm, ni webpack, ni Vite).
  - Rendu des vues : Partials PHP natifs.
- **Testing**: PHPUnit ^10.5.16
- **Databases & Convergence**:
  - **MariaDB** : Cible de production (Ouvaton) et de validation CI/CD obligatoire.
  - **SQLite** : Utilisé pour l'exécution rapide des tests locaux en mémoire/fichier.
  - *Règle critique* : Les requêtes, tests de base de données et migrations SQL doivent impérativement être conçus pour être **compatibles avec MariaDB ET SQLite** (attention aux contraintes FK et aux types).
- **Infrastructure & Déploiement** :
  - **Environnement local** : Docker / OrbStack (pour valider la parité de production avec MariaDB et Mailpit).
  - **CI/CD & Scripts ops** : GitHub Actions, Bash, `lftp` (transfert de l'archive vers Ouvaton).
- **Tooling IA (BMad)** :
  - **Python** : L'utilisation de `uv run python` est **obligatoire** pour toutes les commandes de scripts BMad.

## Critical Implementation Rules

### Language-Specific Rules (PHP)

- **Typage Strict (PHP 8.2)** : Utilisez systématiquement le typage fort pour les paramètres et les types de retour, en particulier dans les Services et Modèles. Privilégiez les fonctions natives PHP 8 (`str_contains`, `array_is_list`, etc.) plutôt que d'ajouter des dépendances inutiles.
  *Pourquoi ?* Le typage fort prévient les erreurs insidieuses de conversion de type (ex: variables POST HTTP traitées comme des entiers).
- **Gestion des erreurs (Fail-Fast)** : Ne jamais ignorer silencieusement (swallow) d'exceptions.
  *Pourquoi ?* Une erreur silencieuse pendant une migration DB ou un script de déploiement entraîne une désynchronisation fatale.
- **Concurrence & Transactions** : Les opérations modifiant des états limités (ex: ajout d'inscription) doivent utiliser des transactions SQL (`db->transStart()`) et des verrous/conditions stricts.
  *Pourquoi ?* Empêche les "race conditions" et le surbooking de créneaux.
- **Isolation de la logique métier (Invariants)** : Les règles complexes (capacité, doublons, annulations) doivent résider exclusivement dans les classes de Service (ex: `SignupService`), jamais dans les contrôleurs.
  *Pourquoi ?* Sécurise les invariants métier indépendamment des routes ou des points d'entrée futurs de l'application.
- **Sécurité et Jetons (Tokens)** : Ne stockez et ne comparez jamais de tokens d'accès bruts (hachage obligatoire). Toute gestion passe par le `TokenService`.
  *Pourquoi ?* Les "Magic Links" sont la seule barrière d'accès ; une fuite donnerait directement les privilèges Owner/Admin.
- **Traçabilité des Emails** : Tout rendu et envoi d'email doit utiliser le `EmailService` et enregistrer une trace dans la table `email_events`.
  *Pourquoi ?* Permet l'audit métier en cas de non-réception ou de plainte d'un participant.

### Framework-Specific Rules (CodeIgniter 4)

- **Architecture MVC Stricte & Domaines** : 
  - Gardez les Controllers, Services, Models, Filters, et Views dans leurs dossiers natifs respectifs. Séparez le routage de manière stricte : `Auth`, `Home`, `Kermesse/Public`, `Kermesse/Dashboard`, `Ops`.
- **Exécution des Migrations (Interdiction de la CLI en Prod)** : 
  - *Règle critique* : La production ne doit **jamais** dépendre de `php spark migrate` exécuté via SSH ou CLI.
  - Les migrations post-déploiement s'exécutent **exclusivement** via des webhooks HTTPS protégés (ex: `POST /ops/migrate`).
- **Sécurité des routes Ops** : Toute route sous `/ops/` doit obligatoirement utiliser le filtre `OpsAuthFilter` (validation HMAC, anti-replay, timestamp, lock BDD).
- **Vues, View Models & Prévention des Fuites (PII)** : 
  - Préparez toujours un tableau structuré (View Model) dans le contrôleur. **Aucune requête SQL ni calcul métier n'est toléré dans les vues `.php`**. 
  - *Prévention Fuite de Données* : Ne passez **jamais** d'Entités de base de données complètes aux vues publiques. Les données personnelles (emails, noms, Magic Links) doivent être consciencieusement filtrées en amont.
  - Respectez les jetons UX documentés : Mobile-first (320px width), marges 16px, radius 8px, cibles tactiles 44px minimum, aucun texte < 13px.
- **Protection CSRF & Pattern PRG** :
  - **CSRF** : `<?= csrf_field() ?>` est obligatoire dans tous les formulaires HTML.
  - **PRG (Post/Redirect/Get)** : Toute soumission POST réussie aboutit à une redirection HTTP (`return redirect()->to(...)`).
- **Configuration & Build de Production** :
  - **Local** : L'application ne lit *pas* de fichier `.env`. Variables injectées via `docker-compose.yml`.
  - **Production (Ouvaton)** : Fichier `shared/.env` géré exclusivement par un workflow manuel GitHub (`sync-production-env.yml`). Les scripts de déploiement de routine ne doivent **absolument jamais** écraser ou générer un `.env`.
  - *Prévention Crash Prod* : Le serveur de production n'exécute **aucun build** (ni `composer install`, ni `npm run build`). L'archive de déploiement générée par la CI doit être 100% autonome et contenir le dossier `vendor/` et les assets statiques.

### Testing Rules

- **Indépendance de la Production & Mocks** : Les tests (locaux et CI) ne doivent **jamais** dépendre d'un service de production. Interceptez l'envoi d'email via des Mocks (ex: mock du `EmailService`) et utilisez une base SQLite via le fichier de configuration dédié `.env.testing`.
- **Tests des Invariants (Obligatoires)** : Avant de valider ou de s'appuyer sur un flux métier, vous devez obligatoirement tester :
  - La concurrence sur la capacité des créneaux (en testant les comportements face aux verrous ou violations d'unicité).
  - Les doublons et les chevauchements d'inscriptions.
  - La validité, le scope et l'expiration des tokens d'authentification.
  - Le comportement de l'application lorsque la kermesse est fermée ou désactivée.
- **Privacy Tests (Absence de PII)** : Toute vue publique doit avoir un test fonctionnel affirmant explicitement l'absence de PII (emails, noms complets, Magic Links) dans le HTML. Utilisez les assertions natives de CI4 (`assertDontSee`) plutôt que des expressions régulières fragiles.
- **Fixtures & Fabricator** : Utilisez toujours le `Fabricator` natif de CodeIgniter 4 pour initialiser la base de données de test avec des fausses données aléatoires. Ne hardcodez **jamais** de données issues de la production.
- **Stratégie de Couverture** : 
  - *Tests Unitaires* : Réservés à la logique métier pure (`app/Services`) et aux requêtes BDD complexes (`app/Models`).
  - *Tests Fonctionnels (Feature)* : Indispensables pour tester les Contrôleurs, les flux d'Authentification (Magic Links), l'interface du Dashboard, et l'étanchéité des Filtres (ex: `OpsAuthFilter`).

### Code Quality & Style Rules

- **Formatage & Typage Strict** : Le respect de PSR-12 et l'instruction `declare(strict_types=1);` sont **obligatoires** pour tout fichier PHP backend (Controllers, Services, Models). Typez explicitement tous les arguments et retours de méthodes.
- **Nommage & Ubiquitous Language** : Nommez les classes et méthodes avec le vocabulaire exact du métier. Interdiction des suffixes génériques fourre-tout (`Manager`, `Helper`, `Data`). Exemple : utilisez `SlotAvailabilityService` plutôt que `SignupValidator`.
- **Transfert de Données (DTO vs Arrays)** : Évitez de transférer des tableaux associatifs non structurés complexes entre les couches (ex: du Contrôleur au Service). Préférez les objets (DTO/Entities). Si l'usage d'un tableau est inévitable, sa structure doit être documentée avec `@phpstan-type`.
- **Commentaires (Le "Pourquoi")** : Ne commentez jamais *ce que fait* le code. Commentez uniquement le *pourquoi* : décisions d'architecture, contournements spécifiques, logiques métier surprenantes. Tout composant dans `app/Services` doit posséder un DocBlock expliquant sa responsabilité métier exacte.
- **Principe de Responsabilité Unique (SRP)** : Si un contrôleur fait plus qu'orchestrer une requête HTTP ou préparer un View Model, la logique doit être extraite dans un Service.
- **Historique & Commits** : Appliquez strictement le standard **Conventional Commits** (`feat:`, `fix:`, `refactor:`, `test:`, `chore:`). Incluez la référence de la Story ou du problème traité pour la traçabilité.

### Development Workflow Rules

- **Git Worktrees & Parallélisation (Règle IA)** : 
  - Le développement (Story, Bugfix) se fait systématiquement sur une branche dédiée (ex: `feature/story-123`).
  - *Règle critique* : Utilisez des **Git Worktrees distincts** pour chaque nouvelle branche. Cela garantit que le travail en parallèle ne corrompt pas le dépôt principal ou le contexte d'un autre agent.
- **Pull Requests & Quality Gates** : 
  - Tout ajout sur `main` s'effectue via une Pull Request.
  - L'approche "push and pray" est interdite : avant d'ouvrir une PR, la branche locale doit réussir à 100% la suite de tests (`phpunit` / `composer test`) et l'analyse statique (`phpstan`).
- **Déploiement Continu (CI/CD)** : 
  - Le merge sur `main` déclenche le déploiement de routine automatisé via GitHub Actions (`deploy-ouvaton.yml`).
  - *Interdiction absolue* : Ne déployez jamais de code ou de fichiers manuellement (ex: SFTP) sur l'environnement de production.
- **Gestion des Secrets de Prod** : Le `.env` de production est géré et mis à jour *exclusivement* par le workflow manuel `sync-production-env.yml`.
- **Exécution BMad (Contrainte d'Infrastructure)** : Utilisez **exclusivement** la commande `uv run python` pour invoquer les scripts BMad. L'utilisation globale de `python` ou `python3` brisera l'exécution du framework en raison de dépendances manquantes.
- **Cycle TDD BMad** : Le développement de fonctionnalités suit rigoureusement le cycle de test (Red, Green, Refactor) imposé par la compétence `bmad-dev-story`.

### Critical Don't-Miss Rules (For AI Agents)

1. **Zéro Fuite PII** : L'injection de données personnelles (emails, noms de famille, Magic Links) dans les vues publiques est une faute critique. Filtrez systématiquement les Entités en amont dans le Contrôleur.
2. **`uv run python` Uniquement** : C'est la *seule* commande valide pour exécuter les outils BMad. Lancer `python` ou `python3` globalement corrompt l'orchestration IA.
3. **Zéro CLI en Production** : N'exécutez **jamais** de commandes (comme `php spark migrate` ou `composer`) directement sur la production Ouvaton. Le pipeline CI/CD et ses webhooks sécurisés sont les uniques portes d'entrée.
4. **Git Worktrees Obligatoires** : Tout agent développant ou cherchant en parallèle doit impérativement utiliser un `git worktree` temporaire isolé pour ne pas détruire le contexte du dépôt principal.
5. **Barrière d'Écriture (Write Constraints)** : Interdiction d'effectuer des écritures (INSERT/UPDATE/DELETE) en base de données en dehors du dossier `app/Services`. Interdiction totale d'inventer de nouvelles tables SQL sans spécification (Epic/Story) formellement validée.
