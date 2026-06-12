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
- Extraction shell (`tar`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`)

Tout est préparé par GitHub Actions et livré en artefact prêt à exécuter.

## Pipeline de déploiement

### Étape 1 : CI (automatique)

Le workflow `.github/workflows/ci.yml` s'exécute sur chaque `push` et `pull_request` vers `main` et `develop`. Il valide `composer.json`, installe les dépendances et exécute les tests.

Sur `push` vers `main` ou `pull_request` ciblant `main`, la CI produit aussi l'artefact `kermesse-deploy.zip` après validation. Les validations MariaDB de CI utilisent un service MariaDB GitHub Actions isolé ; elles ne représentent pas la base de production Ouvaton.

### Étape 2 : Déploiement et configuration (automatique)

Le workflow `.github/workflows/deploy-ouvaton.yml` se déclenche automatiquement quand le workflow CI termine avec succès sur `main`. Il peut aussi être lancé manuellement via `workflow_dispatch` (sans race condition : le déclenchement automatique attend que CI soit terminé avant de démarrer).

1. Checkout, setup PHP, validation Composer, tests
2. Exécution de `scripts/package-deploy-artifact.sh`
3. Publication de l'archive de déploiement comme artefact GitHub (14 jours)
4. Transfert de l'archive vers `OUVATON_DEPLOY_REMOTE_FOLDER/staging` via SFTP `put` (**code applicatif uniquement — jamais le `.env`**)
5. Déploiement du shim `httpdocs/index.php` et des assets publics via `scripts/deploy-httpdocs.sh`
6. Appel de `POST /ops/activate` via HTTPS/HMAC : le serveur vérifie l'archive, la décompresse avec PHP natif dans une release horodatée, puis bascule `current`
7. Appel post-déploiement de `POST /ops/migrate` via HTTPS/HMAC pour appliquer les migrations en utilisant la connexion MariaDB configurée dans le `.env` de production

> **Règle absolue (NFR-2) :** le déploiement de routine **ne génère ni ne transfère jamais** le `.env` de production. La configuration de production (`shared/.env`) est gérée par une opération séparée et manuelle — voir « Déploiement du `.env` de production » plus bas. _(Implémenté par la Story 5.4.)_

Ce workflow ne déclare pas de service MariaDB Docker : la production Ouvaton utilise la base MariaDB managée déjà fournie par l'hébergeur.

## Contenu de l'artefact

## Document root Ouvaton

Le document root Ouvaton est fixé à `httpdocs/`. Le workflow de déploiement gère automatiquement la séparation en deux emplacements distincts :

| Variable | Valeur typique | Contenu déployé |
|----------|---------------|-----------------|
| `OUVATON_DEPLOY_REMOTE_FOLDER` | `kermesse` | `app/`, `vendor/`, `writable/`, `database/`, `public/` — le `.env` vit dans `shared/.env`, géré séparément (voir plus bas), jamais dans ce transfert |
| `OUVATON_HTTPDOCS_FOLDER` | `httpdocs` | `index.php` (shim), `.htaccess`, `robots.txt`, `assets/` |

`OUVATON_DEPLOY_REMOTE_FOLDER` et `OUVATON_HTTPDOCS_FOLDER` sont des **noms de dossier**, passés tels quels à `lftp cd`. Le FTP Ouvaton est chroot dans le home du compte — pas de chemin absolu du filesystem.

Le workflow n'utilise pas de mirror applicatif : l'archive applicative est transférée telle quelle en staging, puis l'endpoint `/ops/activate` la décompresse côté serveur dans `releases/` et met à jour le lien `current`. L'extraction est réalisée par PHP (`PharData`) après validation des entrées TAR : chemins absolus, `..`, liens symboliques, liens durs et types spéciaux sont rejetés avant toute bascule. Seul `public/assets/` est synchronisé en mirror dans `httpdocs/assets/`, car ce dossier ne contient que des fichiers statiques publics.

`KERMESSE_OUVATON_ROOT` contient le chemin absolu filesystem du home Ouvaton (ex. `/var/www/vhosts/monsite.fr`). Il n'est pas utilisé par lftp mais permet de générer explicitement les chemins runtime dans `shared/.env` : `session.savePath=${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}/shared/writable/session` et `kermesse.opsActivateBasePath=${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}`.

Le `index.php` déposé dans `httpdocs/` est un shim généré par `scripts/deploy-httpdocs.sh`. Il définit `FCPATH=httpdocs/`, résout l'application via `../${OUVATON_DEPLOY_REMOTE_FOLDER}/current`, et force les chemins persistants vers `shared/.env` et `shared/writable`. `app/`, `vendor/` et `.env` restent hors du web root et ne sont pas accessibles par URL.

### Inclus

- `app/` — code applicatif CodeIgniter
- `public/` — point d'entrée, `.htaccess`, assets statiques (`css/app.css`, `js/app.js`)
- `vendor/` — dépendances de production (Composer `--no-dev`)
- `writable/` — placeholders uniquement (`index.html`, `.htaccess` par sous-dossier)
- `database/schema/` — réservé (le schéma de référence est désormais la migration baseline dans `database/migrations_sql/`)
- `database/migrations_sql/` — fichiers SQL de migrations (baseline greenfield + incréments par EPIC)
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
| `OUVATON_DEPLOY_HOST` | Nom d'hôte du serveur Ouvaton (SFTP) |
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
| `OUVATON_SFTP_KNOWN_HOST` | Entrée known_hosts SSH Ouvaton — générer avec `ssh-keyscan -p 115 <OUVATON_DEPLOY_HOST>` |
| `KERMESSE_DATABASE_PASSWORD` | Mot de passe MariaDB |
| `KERMESSE_EMAIL_SMTP_PASS` | Mot de passe SMTP |
| `KERMESSE_TOKEN_SECRET` | Clé applicative — générer avec `openssl rand -hex 32` |
| `OPS_MIGRATION_HMAC_SECRET` | Clé HMAC ops — générer avec `openssl rand -hex 32` |

Valeurs fixées par le workflow (rien à configurer) : protocole SFTP port 115, port MariaDB `3306`, nom expéditeur `Kermesse`.

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
| 1 | `OUVATON_DEPLOY_PASSWORD` | mot de passe SFTP Ouvaton |
| 2 | `OUVATON_SFTP_KNOWN_HOST` | `ssh-keyscan -p 115 <OUVATON_DEPLOY_HOST>` (copier la ligne complète) |
| 3 | `KERMESSE_DATABASE_PASSWORD` | mot de passe MariaDB Ouvaton |
| 4 | `KERMESSE_EMAIL_SMTP_PASS` | mot de passe SMTP |
| 5 | `KERMESSE_TOKEN_SECRET` | `openssl rand -hex 32` |
| 6 | `OPS_MIGRATION_HMAC_SECRET` | `openssl rand -hex 32` |

```bash
# Générer les deux clés cryptographiques
echo "KERMESSE_TOKEN_SECRET=$(openssl rand -hex 32)"
echo "OPS_MIGRATION_HMAC_SECRET=$(openssl rand -hex 32)"
```

**Vérification** : dans **Settings → Environments → production**, s'assurer que 12 variables et 6 secrets sont listés (+ les 4 optionnels si nécessaire : `KERMESSE_APP_TIMEZONE`, `KERMESSE_EMAIL_FROM_NAME`, `KERMESSE_EMAIL_SMTP_PORT`, `KERMESSE_EMAIL_SMTP_CRYPTO`). Aucun ne doit être vide.

Déclencher ensuite `.github/workflows/sync-production-env.yml` en cochant `confirm_first_install_env` pour la première installation.

## Variables `.env` de production

Le fichier `.env` de production est généré et déployé **uniquement** par le workflow manuel dédié `sync-production-env.yml` (jamais par le déploiement de routine), depuis les secrets GitHub `production`.
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

L'application CodeIgniter se connecte uniquement à cette base via les variables `database.default.hostname`, `database.default.database`, `database.default.username`, `database.default.password` et `database.default.port` écrites dans `shared/.env` par le workflow `sync-production-env.yml`.

Les changements de schéma sont appliqués après déploiement par l'endpoint applicatif `POST /ops/migrate`. Cet endpoint utilise la connexion DB de l'application déjà configurée ; il ne nécessite ni client `mysql`, ni accès CLI serveur.

## Déploiement du `.env` de production

**Règle absolue (NFR-2, ADR §3.1) :** le déploiement de routine (livraison de code) n'écrit, n'écrase ni ne supprime **jamais** le `.env` de production. Le `.env` vit dans `shared/.env` (hors des releases) ; chaque release le référence par chemin stable et `/ops/activate` n'y touche jamais.

La configuration de production est gérée par une **opération explicite et séparée** :

- **Workflow dédié `sync-production-env.yml`** (déclenchement **manuel** `workflow_dispatch`, environnement GitHub `production`) : génère `.env.next` depuis les secrets, sauvegarde le `shared/.env` distant en `shared/.env.backup-<timestamp>`, puis remplace atomiquement `shared/.env`. Aucune valeur secrète n'apparaît dans les logs.
- Le déploiement de routine (`deploy-ouvaton.yml`) **ne génère pas** de `.env` et **ne le transfère pas** : un `git push` ne peut jamais corrompre les secrets de prod.
- L'artefact (`package-deploy-artifact.sh`) refuse tout `.env` / `.env.next` / secret (FR-7, NFR-1).
- **Première installation :** exécuter `sync-production-env.yml` une fois (sans backup possible) pour amorcer `shared/.env`.

> Cette séparation est conforme à la spec gelée `spec-github-actions-production-env-ouvaton.md` et à l'ADR. Elle a été restaurée par la **Story 5.4** après la régression du commit `a8238d6` (qui avait fondu la génération du `.env` dans chaque déploiement).

### Configuration `.env` en local

En **développement local**, l'application ne lit **pas** de `.env` : sa configuration vient des **variables d'environnement** déclarées dans `docker-compose.yml` (services `app`, `deploy-web`). Aucun `.env` réel n'est créé ni requis. Pour la cible de répétition (`shared/.env` du profil `rehearsal`), un `.env` de dev non sensible peut être amorcé pour la parité, mais aucun secret de production n'y figure jamais.

## Rotation des secrets de production

La rotation est une **opération délibérée**, distincte du déploiement de code.

Processus standard :

1. Mettre à jour les secrets concernés dans l'environnement GitHub `production`
2. Déclencher manuellement `.github/workflows/sync-production-env.yml`
3. Vérifier que le workflow termine avec succès et mentionne le backup créé (`shared/.env.backup-<timestamp>`), sauf première installation explicitement confirmée
4. Contrôler l'application en production sans afficher le contenu du `.env`
5. Conserver le backup jusqu'à validation fonctionnelle

Rollback :

1. Identifier le backup indiqué par le workflow, par exemple `shared/.env.backup-20260608T143000Z`
2. Restaurer ce backup comme `shared/.env` côté Ouvaton via le protocole d'administration disponible
3. Corriger les secrets GitHub `production`
4. Relancer `.github/workflows/sync-production-env.yml`

Ne jamais coller le contenu du `.env` ou d'un backup dans une issue, un log, un commentaire de PR, ou un artefact GitHub.

## Dossiers persistants

L'artefact inclut des placeholders de structure (`index.html`, `.htaccess`) mais **pas** les fichiers générés en runtime (logs, cache, sessions, uploads).

Sur le serveur, les sous-dossiers persistants vivent dans `shared/writable/` et doivent être **accessibles en écriture** par PHP :

- `shared/writable/cache/`
- `shared/writable/logs/`
- `shared/writable/session/`
- `shared/writable/uploads/`

## Protocole de transfert

Le workflow utilise **SFTP** (SSH File Transfer Protocol) sur le port 115 via `lftp`, disponible sur tous les comptes Ouvaton mutualisés. Le protocole est fixé en dur — aucun secret `OUVATON_DEPLOY_PROTOCOL` n'est nécessaire.

Le transfert utilise `lftp` en deux zones distinctes :

1. **Archive applicative** : `scripts/transfer-archive.sh` transfère `build/kermesse-deploy.tar.gz` et son checksum vers `OUVATON_DEPLOY_REMOTE_FOLDER/staging` avec `put`. Le code applicatif n'est jamais synchronisé par mirror.
2. **Web root public** : `scripts/deploy-httpdocs.sh` dépose le shim `index.php`, `.htaccess`, `robots.txt` si présent, puis synchronise uniquement `public/assets/` vers `httpdocs/assets/` avec `mirror --reverse --delete`.

Avant de déposer le shim, `scripts/deploy-httpdocs.sh` crée et vérifie les dossiers `shared/writable/*` avec `cmd:fail-exit yes`. Si un chemin, une permission ou une connexion est incorrecte, le déploiement échoue immédiatement au lieu de continuer avec un état partiel.

Le `.env` de production n'est jamais inclus dans le transfert de routine ni dans l'artefact ; il est géré exclusivement par le workflow manuel `sync-production-env.yml`.

## Récupération des logs applicatifs

Les logs applicatifs de production vivent dans `kermesse/shared/writable/logs/`. Ils ne doivent pas être exposés par `httpdocs/` et aucun endpoint web de lecture des logs ne doit être ajouté.

Pour diagnostiquer un incident, lancer manuellement le workflow `.github/workflows/fetch-ouvaton-logs.yml` depuis GitHub Actions :

1. Choisir l'environnement `production`
2. Renseigner `log_date` au format `YYYY-MM-DD` si le jour courant UTC ne convient pas
3. Ajuster `tail_lines` si nécessaire, entre `1` et `2000`
4. Télécharger l'artefact `ouvaton-application-log`

Le workflow utilise SFTP avec `OUVATON_SFTP_KNOWN_HOST`, récupère uniquement `shared/writable/logs/log-YYYY-MM-DD.php`, n'affiche pas le contenu du log en console et publie un artefact à rétention d'un jour. En cas de fichier absent, chemin incorrect ou erreur SFTP, il échoue explicitement.

Le workflow vérifie qu'un run CI réussi existe pour le SHA déployé, et refuse les refs autres que `main`. Pour un déclenchement manuel (`workflow_dispatch`), ce contrôle est actif. Pour le déclenchement automatique, la conclusion du CI garantit déjà la validité.

## Version PHP

Le projet requiert PHP `^8.2` (CodeIgniter 4.7.x). La CI utilise PHP 8.3.
**La version PHP exacte sur Ouvaton reste à confirmer** avant d'activer le déploiement réel.

La version PHP du conteneur local est paramétrée par l'argument de build
`PHP_VERSION_OUVATON` (défaut `8.3`), défini dans le `Dockerfile` et propagé par
`docker-compose.yml` (`app.build.args`). Une fois la version Ouvaton mesurée via
`/ops/probe`, ajuster la valeur par défaut du `Dockerfile` et, pour le service
Compose local, la valeur transmise dans `app.build.args`.

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
| `post_max_size` | `8M` | Valeur prudente shared hosting, à confirmer ; limite POST complète, enveloppe multipart incluse |
| `upload_max_filesize` | `8M` | Valeur prudente shared hosting, à confirmer |
| `date.timezone` | `Europe/Paris` | Aligné sur `app.appTimezone` |

Avec `post_max_size` et `upload_max_filesize` tous deux à `8M`, la taille utile
d'un fichier envoyé en multipart/form-data est légèrement inférieure à `8M`,
car `post_max_size` couvre aussi l'enveloppe multipart et les autres champs du
formulaire. Si la mesure Ouvaton autorise un plafond POST supérieur, augmenter
`post_max_size` pour rendre un upload proche de `8M` réellement possible.

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

#### Écarts spécifiques au profil `rehearsal` (R-4, NFR-4)

Ces divergences sont propres à la cible locale (`docker compose --profile rehearsal`) et n'affectent pas la comparaison runtime PHP ci-dessus. Elles documenten les simplifications incontournables pour reproduire Ouvaton localement.

- **Structure du home SFTP** : sur Ouvaton, le dossier applicatif est
  `{home}/{OUVATON_DEPLOY_REMOTE_FOLDER}/` (ex. `kermesse/`) et l'archive
  atterrit dans `kermesse/staging/`. En local (profil `rehearsal`), la racine
  du home SFTP est directement la base de déploiement : `staging/`, `releases/`,
  `current`, `shared/`, `httpdocs/` sont à la racine du volume. Le script
  d'orchestration (epic 4) doit donc utiliser `REMOTE_STAGING=staging/` (et non
  `kermesse/staging/`) pour la répétition locale.

- **Shim `httpdocs/index.php`** : l'entrée web Ouvaton est générée par le
  workflow GitHub Actions et référence `realpath('../kermesse')` (le dossier
  applicatif Ouvaton). En local, le shim de bootstrap (créé par
  `docker/deploy-web/entrypoint.sh` au premier démarrage) pointe vers
  `/var/www/html` (le code source monté). Après activation d'une première
  release, l'orchestrateur de répétition (story 4-2) doit déposer un shim
  pointant vers `../current/` pour que `deploy-web` serve réellement la release
  activée.

- **`kermesse.opsActivateBasePath`** : cette variable doit être fixée
  explicitement dans l'environnement runtime. En local, elle vaut
  `/srv/deploy-data` dans le service `deploy-web`. Sur Ouvaton, le workflow
  `sync-production-env.yml` l'écrit dans `shared/.env` avec
  `${KERMESSE_OUVATON_ROOT}/${OUVATON_DEPLOY_REMOTE_FOLDER}`. Ne pas dépendre de
  `dirname(ROOTPATH)` : PHP résout le symlink `current/` vers `releases/<id>/`,
  ce qui peut faire chercher `staging/` au mauvais niveau.

- **Permissions et propriétaire (`chroot` SFTP)** : le chroot SFTP exige que le
  répertoire racine (`/home/deploy`) soit détenu par `root:root` avec permissions
  `755`. Les sous-dossiers (`staging/`, `releases/`, etc.) sont détenus par
  l'utilisateur `deploy`. Sur Ouvaton, les permissions sont gérées par
  l'hébergeur ; en local elles sont initialisées par
  `docker/deploy-target/init-dirs.sh`.

- **`symlink()` / `rename()` atomiques** : `ReleaseActivationService` crée
  le symlink `current` via `symlink()` + `rename()` pour une bascule atomique.
  Sur Linux (conteneur), `rename()` sur un lien symbolique est atomique. Sur
  Ouvaton (hébergement mutualisé), cette atomicité n'est pas garantie si le
  système de fichiers ou le noyau ne la supporte pas — c'est une limitation
  connue documentée ici. Le fichier de secours `CURRENT_RELEASE` assure la
  résilience dans les deux cas.

- **Document root et teardown** : pour remettre le profil `rehearsal` à zéro,
  utiliser `docker compose --profile rehearsal down -v` (supprime le volume
  `deploy-target-data`). Sans cette option, le volume et son contenu (releases,
  `httpdocs/index.php` bootstrap) persistent entre les redémarrages.

## Migrations post-déploiement

Les migrations sont appliquées via `POST /ops/migrate`, protégé par HMAC-SHA256.

Après chaque déploiement applicatif, une étape post-deploy doit appeler cette route pour appliquer les migrations SQL en attente sur la MariaDB managée Ouvaton déjà configurée. Voir `docs/migration-runner.md` pour le contrat complet (en-têtes, payload signé, codes de réponse, verrouillage).

Le secret `OPS_MIGRATION_HMAC_SECRET` doit être configuré dans l'environnement GitHub `production`.

Si le certificat de production est temporairement invalide, définir la variable d'environnement GitHub `KERMESSE_ALLOW_INSECURE_TLS=true` pour que le `curl` post-déploiement utilise `--insecure`. Ce mode conserve HTTPS et la signature HMAC, mais désactive la validation du certificat : il doit rester strictement temporaire.

Les fichiers SQL de migrations sont inclus dans l'artefact (`database/migrations_sql/`).
