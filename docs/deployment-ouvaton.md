# Déploiement Ouvaton — Guide de production

## Contrainte fondamentale : runtime-only

Ouvaton est un hébergement mutualisé. **Le serveur de production ne doit jamais exécuter :**

- Docker ou Docker Compose
- `composer install` ou `composer update`
- `npm install` ou tout outil Node.js
- `phpunit` ou tout framework de test
- `php spark migrate` ou toute commande CodeIgniter CLI
- `mysql < migration.sql` ou tout import SQL manuel côté serveur
- Compilation d'assets (Vite, Tailwind, Webpack…)
- Opérations de cache warmup

Tout est préparé par GitHub Actions et livré en artefact prêt à exécuter.

## Pipeline de déploiement

### Étape 1 : CI (automatique)

Le workflow `.github/workflows/ci.yml` s'exécute sur chaque `push` et `pull_request` vers `main` et `develop`. Il valide `composer.json`, installe les dépendances et exécute les tests.

Sur `push` vers `main` ou `pull_request` ciblant `main`, la CI produit aussi l'artefact `kermesse-deploy.zip` après validation. Les validations MariaDB de CI utilisent un service MariaDB GitHub Actions isolé ; elles ne représentent pas la base de production Ouvaton.

### Étape 2 : Synchronisation du `.env` de production (manuel)

Le workflow `.github/workflows/sync-production-env.yml` se déclenche via `workflow_dispatch` et utilise l'environnement GitHub `production`.

Décision retenue : **les secrets de l'environnement GitHub `production` sont la source de vérité du `.env` Ouvaton**. Le serveur ne reçoit jamais le fichier depuis l'artefact applicatif ; il reçoit un candidat `.env.next` généré dans le runner GitHub Actions, puis activé côté Ouvaton après backup.

Déroulé :

1. Validation des secrets requis, sans afficher leurs valeurs
2. Génération de `${RUNNER_TEMP}/.env.next`
3. Upload de `.env.next` vers le chemin Ouvaton configuré
4. Backup de l'ancien `.env` distant en `.env.backup-<timestamp>` si le fichier existe
5. Remplacement de `.env` par `.env.next`
6. Rollback best-effort vers l'ancien `.env` si l'activation échoue après déplacement du fichier existant

Pour une première installation sans `.env` existant, l'opérateur doit cocher l'input `confirm_first_install_env` au déclenchement manuel du workflow. Sans cette confirmation, l'impossibilité de lire le `.env` distant est traitée comme une erreur de backup et le workflow échoue avant activation.

### Étape 3 : Packaging et déploiement applicatif (automatique)

Le workflow `.github/workflows/deploy-ouvaton.yml` se déclenche automatiquement quand le workflow CI termine avec succès sur `main`. Il peut aussi être lancé manuellement via `workflow_dispatch` (sans race condition : le déclenchement automatique attend que CI soit terminé avant de démarrer).

1. Checkout, setup PHP, validation Composer, tests
2. Exécution de `scripts/package-deploy-artifact.sh`
3. Publication de l'archive `kermesse-deploy.zip` comme artefact GitHub (14 jours)
4. Transfert vers Ouvaton via le protocole confirmé, quand le job `deploy` sera activé
5. Appel post-déploiement de `POST /ops/migrate` via HTTPS/HMAC pour appliquer les migrations en utilisant la connexion MariaDB configurée dans le `.env` de production

Ce workflow ne déclare pas de service MariaDB Docker : la production Ouvaton utilise la base MariaDB managée déjà fournie par l'hébergeur.

## Contenu de l'artefact

## Document root Ouvaton

Le document root Ouvaton est fixé à `httpdocs/`. Le workflow de déploiement gère automatiquement la séparation en deux emplacements distincts :

| Variable | Valeur typique | Contenu déployé |
|----------|---------------|-----------------|
| `OUVATON_DEPLOY_REMOTE_FOLDER` | `kermesse` | `app/`, `vendor/`, `writable/`, `database/`, `public/`, `.env` |
| `OUVATON_HTTPDOCS_FOLDER` | `httpdocs` | `index.php` (shim), `.htaccess`, `robots.txt`, `assets/` |

`OUVATON_DEPLOY_REMOTE_FOLDER` et `OUVATON_HTTPDOCS_FOLDER` sont des **noms de dossier**, passés tels quels à `lftp cd`. Le FTP Ouvaton est chroot dans le home du compte — pas de chemin absolu du filesystem.

`KERMESSE_OUVATON_ROOT` contient le chemin absolu filesystem du home Ouvaton (ex. `/var/www/vhosts/monsite.fr`). Il n'est pas utilisé par lftp mais permet de dériver automatiquement `session.savePath` dans le `.env` généré : `${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}/writable/session`.

Le `index.php` déposé dans `httpdocs/` est un shim généré par le workflow qui définit `ROOTPATH=../kermesse/` et `FCPATH=httpdocs/`, puis charge le bootstrap CodeIgniter. `app/`, `vendor/` et `.env` restent hors du web root et ne sont pas accessibles par URL.

### Inclus

- `app/` — code applicatif CodeIgniter
- `public/` — point d'entrée, `.htaccess`, assets statiques (`css/app.css`, `js/app.js`)
- `vendor/` — dépendances de production (Composer `--no-dev`)
- `writable/` — placeholders uniquement (`index.html`, `.htaccess` par sous-dossier)
- `database/schema/` — fichiers SQL du schéma
- `database/migrations_sql/` — fichiers SQL de migrations
- `composer.json`, `composer.lock` — références des dépendances
- `.env.example` — modèle de configuration
- `docs/` — documentation de déploiement

### Exclus

- `.git/`
- `.github/agents/`, `.agents/`, `.agent/`
- `_bmad-output/`, `_bmad/`
- `node_modules/`
- `tests/`, `phpunit*`, résultats de couverture
- `.env`, `.env.next`, `.env.local`, `.env.*.local`
- `auth.json`, `*.key`, `*.pem`
- Caches locaux (`writable/cache/*`, `writable/logs/*`…)

Le script de packaging vérifie automatiquement l'absence de fichiers interdits et échoue si l'un est détecté.

## Variables et secrets GitHub Actions

Les entrées de configuration sont réparties en deux catégories dans l'environnement GitHub `production` :

- **Variables** (`vars.X`) : configuration non-sensible, visible dans les logs et l'UI GitHub
- **Secrets** (`secrets.X`) : credentials actionnables seuls, masqués partout

### Variables à configurer (`Settings → Environments → production → Variables`)

| Variable | Description |
|----------|-------------|
| `OUVATON_DEPLOY_HOST` | Nom d'hôte du serveur Ouvaton (FTPS) |
| `OUVATON_DEPLOY_USERNAME` | Nom d'utilisateur du compte Ouvaton |
| `OUVATON_DEPLOY_REMOTE_FOLDER` | Nom du dossier applicatif depuis racine FTP (ex. `kermesse`) |
| `OUVATON_HTTPDOCS_FOLDER` | Nom du dossier web root depuis racine FTP (ex. `httpdocs`) |
| `KERMESSE_OUVATON_ROOT` | Chemin absolu filesystem du home Ouvaton (ex. `/var/www/vhosts/monsite.fr`) |
| `KERMESSE_PUBLIC_BASE_URL` | URL publique canonique de l'application |
| `KERMESSE_DATABASE_HOSTNAME` | Hôte MariaDB Ouvaton |
| `KERMESSE_DATABASE_DATABASE` | Nom de la base MariaDB |
| `KERMESSE_DATABASE_USERNAME` | Utilisateur MariaDB |
| `KERMESSE_EMAIL_SMTP_HOST` | Hôte SMTP |
| `KERMESSE_EMAIL_SMTP_USER` | Identifiant SMTP (souvent l'adresse email) |
| `KERMESSE_EMAIL_FROM_EMAIL` | Adresse expéditrice |
| `KERMESSE_APP_TIMEZONE` | Optionnel ; timezone. Défaut : `Europe/Paris` |
| `KERMESSE_EMAIL_FROM_NAME` | Optionnel ; nom d'expéditeur. Défaut : `Kermesse` |
| `KERMESSE_EMAIL_SMTP_PORT` | Optionnel ; port SMTP. Défaut : `587` |
| `KERMESSE_EMAIL_SMTP_CRYPTO` | Optionnel ; chiffrement SMTP. Défaut : `tls` |
| `KERMESSE_ALLOW_INSECURE_TLS` | Optionnel ; `true` autorise temporairement l'appel post-déploiement `/ops/migrate` avec vérification TLS désactivée |

### Secrets à configurer (`Settings → Environments → production → Secrets`)

| Secret | Description |
|--------|-------------|
| `OUVATON_DEPLOY_PASSWORD` | Mot de passe du compte Ouvaton |
| `KERMESSE_DATABASE_PASSWORD` | Mot de passe MariaDB |
| `KERMESSE_EMAIL_SMTP_PASS` | Mot de passe SMTP |
| `KERMESSE_TOKEN_SECRET` | Clé applicative — générer avec `openssl rand -hex 32` |
| `OPS_MIGRATION_HMAC_SECRET` | Clé HMAC ops — générer avec `openssl rand -hex 32` |

Valeurs fixées par le workflow (rien à configurer) : protocole FTPS, port MariaDB `3306`, nom expéditeur `Kermesse`.

## Configurer les secrets GitHub — guide pas-à-pas

Toutes les entrées sont à configurer dans l'**environnement GitHub `production`** (pas dans les secrets/variables de dépôt ou d'organisation). Procédure :

1. Ouvrir le dépôt sur GitHub → **Settings** → **Environments**
2. Créer l'environnement `production` s'il n'existe pas encore

**Étape A — Variables** (`Add variable` dans l'environnement `production`)

| # | Variable | Exemple |
|---|----------|---------|
| 1 | `OUVATON_DEPLOY_HOST` | `ftp.example.invalid` |
| 2 | `OUVATON_DEPLOY_USERNAME` | `monidentifiant` |
| 3 | `OUVATON_DEPLOY_REMOTE_FOLDER` | `kermesse` |
| 4 | `OUVATON_HTTPDOCS_FOLDER` | `httpdocs` |
| 4 | `KERMESSE_PUBLIC_BASE_URL` | `https://kermesse.monasso.fr/` |
| 5 | `KERMESSE_OUVATON_ROOT` | chemin absolu filesystem du home Ouvaton (ex. `/var/www/vhosts/monsite.fr`) — dérive automatiquement `session.savePath` |
| 6 | `KERMESSE_DATABASE_HOSTNAME` | fourni par Ouvaton dans l'espace client |
| 7 | `KERMESSE_DATABASE_DATABASE` | nom de la base MariaDB Ouvaton |
| 8 | `KERMESSE_DATABASE_USERNAME` | utilisateur MariaDB Ouvaton |
| 9 | `KERMESSE_EMAIL_SMTP_HOST` | hôte SMTP du fournisseur d'email |
| 10 | `KERMESSE_EMAIL_SMTP_USER` | identifiant SMTP |
| 11 | `KERMESSE_EMAIL_FROM_EMAIL` | `no-reply@monasso.fr` |

Variables optionnelles (défauts appliqués si absentes) : `KERMESSE_APP_TIMEZONE` (`Europe/Paris`), `KERMESSE_EMAIL_SMTP_PORT` (`587`), `KERMESSE_EMAIL_SMTP_CRYPTO` (`tls`).

Variable de secours temporaire : `KERMESSE_ALLOW_INSECURE_TLS=true` permet au workflow de terminer l'étape post-déploiement même si le certificat HTTPS ne correspond pas au nom d'hôte. Ne l'activer que le temps de corriger le certificat, puis supprimer la variable ou la remettre à `false`.

**Étape B — Secrets** (`Add secret` dans l'environnement `production`)

| # | Secret | Comment |
|---|--------|---------|
| 1 | `OUVATON_DEPLOY_PASSWORD` | mot de passe FTPS Ouvaton |
| 2 | `KERMESSE_DATABASE_PASSWORD` | mot de passe MariaDB Ouvaton |
| 3 | `KERMESSE_EMAIL_SMTP_PASS` | mot de passe SMTP |
| 4 | `KERMESSE_TOKEN_SECRET` | `openssl rand -hex 32` |
| 5 | `OPS_MIGRATION_HMAC_SECRET` | `openssl rand -hex 32` |

```bash
# Générer les deux clés cryptographiques
echo "KERMESSE_TOKEN_SECRET=$(openssl rand -hex 32)"
echo "OPS_MIGRATION_HMAC_SECRET=$(openssl rand -hex 32)"
```

**Vérification** : dans **Settings → Environments → production**, s'assurer que 12 variables et 5 secrets sont listés (+ les 4 optionnels si nécessaire : `KERMESSE_APP_TIMEZONE`, `KERMESSE_EMAIL_FROM_NAME`, `KERMESSE_EMAIL_SMTP_PORT`, `KERMESSE_EMAIL_SMTP_CRYPTO`). Aucun ne doit être vide.

Déclencher ensuite `.github/workflows/sync-production-env.yml` en cochant `confirm_first_install_env` pour la première installation.

## Variables `.env` de production

Le fichier `.env` de production est généré par `.github/workflows/sync-production-env.yml` depuis les secrets GitHub `production`.
Voir `.env.example` pour la liste complète des variables et leurs formats.

Variables critiques à configurer :

- `CI_ENVIRONMENT = production`
- `app.baseURL` — URL publique réelle
- `database.default.*` — identifiants de connexion à la MariaDB managée Ouvaton déjà existante
- `session.savePath` — chemin absolu sur le serveur Ouvaton (dépend de l'arborescence)
- `email.*` — configuration SMTP
- `kermesse.tokenSecret` — secret de 32 octets minimum
- `kermesse.opsMigrationHmacSecret` — secret du runner ops

## Base MariaDB managée

La base MariaDB de production est créée et administrée par Ouvaton. Le projet ne doit pas tenter de créer une base Docker, de démarrer un conteneur, ni d'administrer MariaDB depuis le serveur de production.

L'application CodeIgniter se connecte uniquement à cette base via les variables `database.default.hostname`, `database.default.database`, `database.default.username`, `database.default.password` et `database.default.port` écrites dans le `.env` de production par `.github/workflows/sync-production-env.yml`.

Les changements de schéma sont appliqués après déploiement par l'endpoint applicatif `POST /ops/migrate`. Cet endpoint utilise la connexion DB de l'application déjà configurée ; il ne nécessite ni client `mysql`, ni accès CLI serveur.

## Préservation du `.env` de production

**Règle absolue :** le déploiement applicatif ne doit **jamais** envoyer, écraser ou supprimer le fichier `.env` de production.

- L'artefact n'inclut pas de fichier `.env` (seulement `.env.example`)
- L'artefact n'inclut pas `.env.next`
- Le script de packaging échoue si `.env` ou `.env.next` est détecté dans le staging ou dans le ZIP
- Seul le workflow dédié `.github/workflows/sync-production-env.yml` est autorisé à gérer le `.env` Ouvaton
- Avant remplacement, le workflow sauvegarde l'ancien `.env` distant sous `.env.backup-<timestamp>`
- Le chemin distant doit rester hors document root public ; le document root web doit pointer vers `public/`
- Les backups `.env.backup-<timestamp>` contiennent des secrets et doivent être supprimés après validation ou archivés selon la procédure d'exploitation retenue

## Rotation des secrets de production

Processus standard :

1. Mettre à jour les secrets concernés dans l'environnement GitHub `production`
2. Déclencher manuellement `.github/workflows/sync-production-env.yml`
3. Vérifier que le workflow termine avec succès et mentionne le backup créé, sauf première installation explicitement confirmée
4. Contrôler l'application en production sans afficher le contenu du `.env`
5. Conserver le backup jusqu'à validation fonctionnelle

Rollback :

1. Identifier le backup indiqué par le workflow, par exemple `.env.backup-20260602T143000Z`
2. Restaurer ce backup comme `.env` côté Ouvaton via le protocole d'administration disponible
3. Corriger les secrets GitHub `production`
4. Relancer `.github/workflows/sync-production-env.yml`

Ne jamais coller le contenu du `.env` ou d'un backup dans une issue, un log, un commentaire de PR, ou un artefact GitHub.

## Dossiers `writable/`

L'artefact inclut les placeholders de structure (`index.html`, `.htaccess`) mais **pas** les fichiers générés en runtime (logs, cache, sessions, uploads).

Sur le serveur, les sous-dossiers `writable/` doivent être **accessibles en écriture** par PHP :

- `writable/cache/`
- `writable/debugbar/` (développement uniquement)
- `writable/logs/`
- `writable/session/`
- `writable/uploads/`

## Protocole de transfert

Le workflow utilise **FTPS** (FTP over TLS) via `lftp`, disponible sur tous les comptes Ouvaton mutualisés. Le protocole est fixé en dur — aucun secret `OUVATON_DEPLOY_PROTOCOL` n'est nécessaire.

Le transfert utilise `lftp` avec `mirror --reverse --delete` : les fichiers présents sur Ouvaton mais absents de l'artefact sont supprimés (déploiement propre). Deux exclusions garantissent la sécurité :
- `^\.env` — le `.env` de production et ses backups ne sont jamais touchés
- `^writable/` — logs, sessions, cache et uploads écrits par l'app sont préservés

Le workflow vérifie qu'un run CI réussi existe pour le SHA déployé, et refuse les refs autres que `main`. Pour un déclenchement manuel (`workflow_dispatch`), ce contrôle est actif. Pour le déclenchement automatique, la conclusion du CI garantit déjà la validité.

## Version PHP

Le projet requiert PHP `^8.2` (CodeIgniter 4.7.x). La CI utilise PHP 8.3.
**La version PHP exacte sur Ouvaton reste à confirmer** avant d'activer le déploiement réel.

La version PHP du conteneur local est paramétrée par l'argument de build
`PHP_VERSION_OUVATON` (défaut `8.3`), défini dans le `Dockerfile` et propagé par
`docker-compose.yml` (`app.build.args`). Une fois la version Ouvaton mesurée via
`/ops/probe`, ajuster ce seul argument pour réaligner l'image.

## Limites runtime & parité local ⇄ Ouvaton

Pour que les dépassements de limites runtime échouent en développement plutôt
qu'en production (PJ-2 / FR-3, NFR-4), le conteneur `app` applique un `php.ini`
versionné (`docker/app/php.ini`) monté en `conf.d/zz-kermesse.ini`. Le préfixe
`zz-` garantit qu'il est chargé **en dernier** et écrase donc les défauts de
l'image.

Valeurs actuelles (points de départ documentés, à recalibrer sur la sortie de la
sonde) :

| Directive | Valeur | Source |
| --- | --- | --- |
| `memory_limit` | `128M` | Point de départ PRD infra (FR-3) |
| `max_execution_time` | `30` | Point de départ PRD infra (FR-3) |
| `max_input_time` | `30` | Aligné sur `max_execution_time` |
| `post_max_size` | `8M` | Valeur prudente shared hosting, à confirmer |
| `upload_max_filesize` | `8M` | Valeur prudente shared hosting, à confirmer |
| `date.timezone` | `Europe/Paris` | Aligné sur `app.appTimezone` |

Versions de base de données et d'extensions épinglées :

- **MariaDB `10.11`** (`docker-compose.yml`, service `db`) — confirmée
  manuellement sur Ouvaton ; la sonde la reconfirme via `SELECT VERSION()`.
- **Extensions PHP** installées dans le `Dockerfile` : `intl`, `mysqli`,
  `pdo_mysql`, `zip`. Outils de packaging garantis présents : `tar`, `gzip`
  (requis par l'empaquetage de l'artefact, story 2.1).

### Écarts local ⇄ Ouvaton

Cette section recense les divergences connues entre le runtime local et la cible
Ouvaton, à tenir à jour à chaque mesure de la sonde (NFR-4).

- **Limites PHP** : valeurs ci-dessus = points de départ documentés, **pas encore
  recalibrées** sur une mesure `/ops/probe` réelle. À confronter et corriger dès
  que la sortie de la sonde Ouvaton est enregistrée.
- **Version PHP** : `8.3` côté local (`PHP_VERSION_OUVATON`) ; valeur exacte
  Ouvaton à reconfirmer via la sonde.
- **Extensions PHP** : la liste locale (`intl mysqli pdo_mysql zip`) n'a pas
  encore été confrontée aux `extensions` renvoyées par `/ops/probe` sur Ouvaton.
  Tout écart constaté doit être soit corrigé dans le `Dockerfile`, soit consigné
  ici comme « écart connu » avec sa justification. _Aucun écart confirmé à ce
  jour._

## Migrations post-déploiement

Les migrations sont appliquées via `POST /ops/migrate`, protégé par HMAC-SHA256.

Après chaque déploiement applicatif, une étape post-deploy doit appeler cette route pour appliquer les migrations SQL en attente sur la MariaDB managée Ouvaton déjà configurée. Voir `docs/migration-runner.md` pour le contrat complet (en-têtes, payload signé, codes de réponse, verrouillage).

Le secret `OPS_MIGRATION_HMAC_SECRET` doit être configuré dans l'environnement GitHub `production`.

Si le certificat de production est temporairement invalide, définir la variable d'environnement GitHub `KERMESSE_ALLOW_INSECURE_TLS=true` pour que le `curl` post-déploiement utilise `--insecure`. Ce mode conserve HTTPS et la signature HMAC, mais désactive la validation du certificat : il doit rester strictement temporaire.

Les fichiers SQL de migrations sont inclus dans l'artefact (`database/migrations_sql/`).
