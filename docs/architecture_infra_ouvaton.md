---
title: "Architecture (ADR) : Chantier infrastructure — parité locale Ouvaton & déploiement atomique"
status: accepted
created: 2026-06-06
updated: 2026-06-06
decision: "Réutilisation du socle ops HMAC existant. X-Deploy-Token, runner spark $migrations->latest() et workflow deploy.yml parallèle écartés (moins fiables / second mécanisme)."
owner: Sylvain
architect: Winston
relates_to:
  - docs/prd_infra_ouvaton.md
  - docs/deployment-ouvaton.md
  - docs/migration-runner.md
  - docs/local-orbstack.md
---

# Architecture (ADR) : Chantier infrastructure — parité locale Ouvaton & déploiement atomique

## 0. Objet du document

Ce document traduit le PRD `docs/prd_infra_ouvaton.md` en **décisions techniques exécutables**. Il couvre :

1. Les modifications exactes de `Dockerfile`, `docker-compose.yml` et d'un `php.ini` versionné (alignement Ouvaton).
2. La structure de l'endpoint de migration sécurisé déclenché par la CI.
3. Le schéma logique du workflow GitHub Actions de déploiement (Build → `.tar.gz` → upload FTPS/SFTP → activation par webhook → migration par webhook).
4. Le traitement de **tous** les points fonctionnels (FR), non-fonctionnels (NFR) et risques (R) du PRD.

Il ne réécrit pas le runner de migrations déjà livré (`docs/migration-runner.md`) ; il l'**orchestre, l'étend et le sécurise** pour les nouvelles routes ops.

> **Décision tranchée (validée par Sylvain le 2026-06-06)** : tout passe par le **socle ops HMAC existant** (`OpsAuthFilter` + `POST /ops/migrate` + `MigrationRunnerService`). Les approches alternatives évaluées sont **définitivement écartées**, sans option de repli :
> - ❌ Endpoint `App\Controllers\Deploy::migrate()` protégé par un token statique `X-Deploy-Token` — rejeu permanent, non lié à la requête, contraire à `claude.md` et FR-11.
> - ❌ Runner spark `$migrations->latest()` (classes PHP + table `migrations`) — second mécanisme concurrent du runner SQL en place.
> - ❌ Workflow `deploy.yml` parallèle — doublerait le déclencheur `workflow_run`.
>
> Ces alternatives ne sont conservées dans ce document **qu'à titre de justification de la décision**, jamais comme chemin retenu.

> **Convention de codes d'erreur** : codes/logs techniques en anglais, messages opérateur en français (règle projet `claude.md`). Nouveaux codes ops introduits ici : `archive_missing`, `checksum_mismatch`, `extract_failed`, `activation_locked`, `probe_disabled`, `release_invalid`.

---

## 1. Décisions structurantes (synthèse ADR)

| # | Décision | Statut | FR/NFR | §  |
|---|----------|--------|--------|----|
| **D-1** | **Authentification des routes ops : HMAC-SHA256 (`OpsAuthFilter`) uniquement.** Aucun token statique `X-Deploy-Token`. | ✅ **Tranché** | NFR-1, NFR-5, FR-11 | §6 |
| **D-2** | Une seule famille de routes ops, toutes derrière `OpsAuthFilter` : `ops/migrate`, `ops/migrate/status`, `ops/activate`, `ops/probe`. | ✅ Tranché | FR-9, FR-11, FR-14 | §4, §6 |
| **D-3** | `OpsAuthFilter` calcule le `routePath` signé **dynamiquement** depuis l'URI (au lieu de le coder en dur sur `ops/migrate`) pour sécuriser plusieurs routes sans dupliquer le filtre. | ✅ Tranché (requis par D-2) | FR-9, FR-11 | §4.2 |
| **D-4** | Runner de migration **inchangé** : fichiers SQL `database/migrations_sql/` + `schema_versions`. `$migrations->latest()` (runner spark/PHP) **interdit**. | ✅ Tranché | FR-11, FR-13 | §6.3 |
| **D-5** | Parité runtime via un `php.ini` versionné monté en `conf.d`, **calibré sur la sonde** `/ops/probe`, pas deviné. | ✅ Tranché | FR-1→5, NFR-4 | §2 |
| **D-6** | Déploiement atomique « Capistrano-like » : `releases/<horodatage-sha>/` + pointeur `current` + `shared/` (`.env`, `writable/`), bascule atomique par `/ops/activate`. | ✅ Tranché | FR-9, FR-10, NFR-2, NFR-3 | §3 |
| **D-7** | Transfert d'un **unique** `.tar.gz` + sidecar `.sha256` par `put` (et non `mirror` fichier-par-fichier). | ✅ Tranché | FR-6, FR-7, FR-8 | §3.3, §7 |
| **D-8** | Dry-run migration exposé comme **route dédiée** `POST /ops/migrate/status` (lecture seule), tranche R-3. | ✅ Tranché | FR-14 | §4.3 |
| **D-9** | Répétition locale = **mêmes scripts** que la CI, paramétrés par variables d'env ; cible locale Ouvaton-like via service SFTP + volume partagé. | ✅ Tranché | FR-16→20, NFR-7 | §5 |
| **D-10** | Pipeline : faire évoluer **`deploy-ouvaton.yml` en place**. Aucun second workflow `deploy.yml` (doublerait les déclencheurs). | ✅ Tranché | R-5 | §7 |

Toutes les décisions sont tranchées. Les seules confirmations restantes sont **dépendantes de la mesure sonde** (version PHP/MariaDB exacte, support `symlink()` Ouvaton) — voir §11.

---

## 2. Objectif 1 — Parité runtime locale ⇄ Ouvaton

> Réalise PJ-2. Couvre FR-1 à FR-5, NFR-4.

### 2.1 Principe : mesurer d'abord, brider ensuite

On ne devine pas les limites Ouvaton. La séquence est :

1. Déployer la sonde (`/ops/probe`, §2.5) en production une fois.
2. Récupérer le JSON de configuration réelle.
3. Reporter ces valeurs dans `docker/app/php.ini`, l'`ARG PHP_VERSION` du `Dockerfile` et l'image MariaDB du `docker-compose.yml`.
4. Désactiver/retirer la sonde.

Tant que la mesure n'est pas faite, les valeurs ci-dessous sont les **points de départ** du PRD (`memory_limit=128M`, `max_execution_time=30`), explicitement marqués comme provisoires.

### 2.2 Nouveau fichier `docker/app/php.ini` (drop-in `conf.d`)

Centralise les limites Ouvaton. Le préfixe `zz-` garantit qu'il est chargé en dernier et écrase les défauts de l'image.

```ini
; docker/app/php.ini — limites alignées sur Ouvaton (mesurées via /ops/probe).
; Valeurs de départ PRD ; à remplacer par la sortie de la sonde (FR-3).
memory_limit = 128M
max_execution_time = 30
post_max_size = 8M
upload_max_filesize = 8M
max_input_time = 30
; date.timezone aligné sur l'app pour éviter les warnings runtime.
date.timezone = Europe/Paris
```

### 2.3 Modifications exactes du `Dockerfile`

```diff
- FROM php:8.3-apache
+ # PHP_VERSION_OUVATON : version exacte mesurée par /ops/probe. 8.3 = point de départ.
+ ARG PHP_VERSION_OUVATON=8.3
+ FROM php:${PHP_VERSION_OUVATON}-apache

  ARG COMPOSER_VERSION=2.8.12

  ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
      COMPOSER_ALLOW_SUPERUSER=1 \
      COMPOSER_HOME=/tmp/composer

  RUN apt-get update \
      && apt-get install -y --no-install-recommends \
          git \
          libicu-dev \
          libzip-dev \
          unzip \
          zip \
+         # `tar`/`gzip` sont présents dans l'image de base ; on s'en assure pour le packaging local.
+         tar \
+         gzip \
      && docker-php-ext-install intl mysqli pdo_mysql zip \
      && a2enmod rewrite \
      && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
      && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/../!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
      && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
      && php /tmp/composer-setup.php --version="${COMPOSER_VERSION}" --install-dir=/usr/local/bin --filename=composer \
      && rm -f /tmp/composer-setup.php \
      && apt-get clean \
      && rm -rf /var/lib/apt/lists/*

+ # Limites runtime alignées Ouvaton (FR-3). Chargé en dernier via le préfixe zz-.
+ COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-kermesse-ouvaton.ini

  WORKDIR /var/www/html

  COPY docker/app/entrypoint.sh /usr/local/bin/kermesse-entrypoint

  ENTRYPOINT ["kermesse-entrypoint"]
  CMD ["apache2-foreground"]
```

> **FR-4 (extensions)** : la liste `intl mysqli pdo_mysql zip` doit être confrontée à `extensions` renvoyé par la sonde. Toute extension qu'Ouvaton charge et que le conteneur n'a pas (ou l'inverse) est un écart à corriger ou à documenter (NFR-4).

### 2.4 Modifications exactes du `docker-compose.yml`

Trois changements : alignement MariaDB, exposition des nouveaux secrets ops locaux, et passage de l'`ARG` de version PHP.

```diff
  services:
    app:
      build:
        context: .
+       args:
+         # Version mesurée par la sonde ; 8.3 = point de départ.
+         PHP_VERSION_OUVATON: "8.3"
      ports:
        - "127.0.0.1:${KERMESSE_HTTP_PORT:-8080}:80"
      environment:
        CI_ENVIRONMENT: development
        app.baseURL: "http://localhost:${KERMESSE_HTTP_PORT:-8080}/"
        app.appTimezone: "Europe/Paris"
        app.forceGlobalSecureRequests: "false"
        app.CSPEnabled: "false"
        logger.threshold: "4"
        database.default.hostname: db
        database.default.database: kermesse
        database.default.username: kermesse_user
        database.default.password: kermesse_password
        database.default.DBDriver: MySQLi
        database.default.DBPrefix: ""
        database.default.port: "3306"
        database.default.charset: utf8mb4
        database.default.DBCollat: utf8mb4_general_ci
        session.savePath: /var/www/html/writable/session
        kermesse.publicBaseURL: "http://localhost:${KERMESSE_HTTP_PORT:-8080}/"
        kermesse.tokenSecret: local_dev_token_secret_32_bytes_minimum
        kermesse.opsMigrationHmacSecret: local_dev_ops_secret_32_bytes_minimum
        kermesse.opsMigrationProductionOnly: "false"
+       # Sonde de configuration : activée uniquement en local pour la mesure (FR-2).
+       kermesse.opsProbeEnabled: "true"
      volumes:
        - .:/var/www/html
        - composer-cache:/tmp/composer
        - vendor:/var/www/html/vendor
        - writable:/var/www/html/writable
      depends_on:
        db:
          condition: service_healthy

    db:
-     image: mariadb:10.11
+     # MariaDB 10.11 = version managée Ouvaton (confirmée manuellement par
+     # Sylvain le 2026-06-06 ; la sonde /ops/probe la confirmera définitivement).
+     image: mariadb:${KERMESSE_MARIADB_VERSION:-10.11}
      environment:
        MARIADB_ROOT_PASSWORD: root_password
        MARIADB_DATABASE: kermesse
        MARIADB_USER: kermesse_user
        MARIADB_PASSWORD: kermesse_password
      ports:
        - "127.0.0.1:${KERMESSE_DB_PORT:-3307}:3306"
      volumes:
        - mariadb-data:/var/lib/mysql
      healthcheck:
        test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
        interval: 5s
        timeout: 5s
        retries: 20
        start_period: 20s
```

> Le service `deploy-target` (cible locale Ouvaton-like) et l'orchestrateur de répétition sont ajoutés au même fichier sous un **profil Compose** dédié — voir §5.2, pour ne pas alourdir le `docker compose up` quotidien.

### 2.5 FR-1/FR-2 — Sonde `/ops/probe`

Nouveau contrôleur `App\Controllers\Ops\ProbeController::probe()`, derrière `OpsAuthFilter`.

- **Ne renvoie aucun secret** : ni valeur `.env`, ni credentials. Uniquement des faits runtime.
- Sortie JSON :

```json
{
  "php_version": "8.3.x",
  "memory_limit": "128M",
  "max_execution_time": 30,
  "post_max_size": "8M",
  "upload_max_filesize": "8M",
  "extensions": ["intl", "mysqli", "pdo_mysql", "zip", "..."],
  "mariadb_version": "10.11.x"
}
```

- `php_version` via `PHP_VERSION` ; limites via `ini_get()` ; `extensions` via `get_loaded_extensions()` ; `mariadb_version` via `SELECT VERSION()` sur la connexion `database.default.*`.
- **Garde-fou de cycle de vie (FR-2)** : la route ne répond que si `kermesse.opsProbeEnabled === true`. Défaut **`false`** dans `Config\Kermesse`. Désactivée = `403 {"error":"probe_disabled"}` (via le contrôleur, après l'auth HMAC). On l'active le temps d'une mesure, puis on remet `false` côté prod (ou on retire la route).

### 2.6 FR-5 — Rendre les limites vérifiables

Script `scripts/show-runtime-config.sh` exécuté dans le conteneur :

```bash
docker compose exec app php -r '
  echo "php=".PHP_VERSION.PHP_EOL;
  foreach (["memory_limit","max_execution_time","post_max_size","upload_max_filesize"] as $k)
    echo $k."=".ini_get($k).PHP_EOL;
  echo "extensions=".implode(",", get_loaded_extensions()).PHP_EOL;'
docker compose exec db mariadb --version
```

Comparable ligne à ligne à la sortie de la sonde : c'est l'outil de vérification de la parité (NFR-4).

---

## 3. Objectif 2 — Déploiement atomique par archive

> Réalise PJ-3. Couvre FR-6 à FR-10, NFR-2, NFR-3. Tranche R-1.

### 3.1 Layout cible côté serveur (releases / shared / current)

On remplace le dossier applicatif plat `kermesse/` par une structure à bascule atomique, inspirée de Capistrano mais 100 % runtime-only (aucun CLI serveur) :

```
<home Ouvaton chroot>/
├── httpdocs/                     # web root FIXE Ouvaton
│   ├── index.php                 # shim : lit le pointeur 'current' → ROOTPATH
│   ├── .htaccess
│   └── assets/                   # statiques de la release active
└── kermesse/
    ├── staging/                  # zone de réception du .tar.gz (hors web root)
    ├── shared/
    │   ├── .env                  # JAMAIS écrasé (NFR-2) — géré par sync-production-env.yml
    │   └── writable/             # logs/session/cache/uploads — JAMAIS écrasés (NFR-2)
    ├── releases/
    │   ├── 20260606T101500Z-<sha>/
    │   └── 20260606T120000Z-<sha>/
    └── current -> releases/20260606T120000Z-<sha>/   # pointeur atomique
```

Principes :

- **`.env` et `writable/` vivent dans `shared/`**, hors des releases. Chaque release les référence par chemin stable (le shim définit `WRITEPATH` → `../shared/writable/`, et CodeIgniter lit le `.env` depuis `shared/`). L'activation ne les touche **jamais** (NFR-2, FR-10).
- **Bascule atomique** : `current` est un lien symbolique mis à jour par `rename()` (opération atomique POSIX). Le shim `httpdocs/index.php` résout `ROOTPATH` via `current`. Tant que la nouvelle release n'est pas validée, `current` pointe l'ancienne → une extraction interrompue ne sert jamais un état mixte (NFR-3).
- **Fallback fidélité (R-4)** : si Ouvaton interdit `symlink()`/`rename()` sur lien (chroot/permissions), repli sur un **fichier pointeur** `kermesse/CURRENT_RELEASE` (contenant le nom du dossier) écrit en *write-temp + rename*, lu par le shim. À confirmer lors de la mesure sonde.

### 3.2 FR-9 — Route d'activation `POST /ops/activate`

Nouveau contrôleur `App\Controllers\Ops\ActivateController::activate()` + service `App\Services\ReleaseActivationService`, derrière `OpsAuthFilter` (`routePath = ops/activate`, voir D-3).

Séquence du service (verrou MariaDB nommé distinct de celui des migrations, ex. `kermesse_ops_activate_lock`) :

1. Localiser l'archive reçue dans `staging/` (nom passé dans le body signé, ex. `{"archive":"kermesse-deploy-<sha>.tar.gz"}`). Absente → `archive_missing`.
2. **Vérifier le checksum** : recalculer SHA-256 et comparer au sidecar `.sha256`. Écart → `checksum_mismatch`, archive rejetée, `current` intact.
3. Extraire dans `releases/<horodatage>-<sha>/` (jamais directement dans `current`).
4. **Valider la release** : présence de `app/`, `vendor/`, `public/`, `database/migrations_sql/`. Sinon → `release_invalid`, dossier supprimé.
5. **Bascule atomique** de `current` vers la nouvelle release (`rename` du symlink / pointeur).
6. **Rétention bornée (FR-10)** : conserver les `N` dernières releases (défaut `N=3`, `kermesse.releasesRetention`), purger au-delà.
7. Réponse JSON minimale, sans chemin absolu ni secret :

```json
{ "ok": true, "release": "20260606T120000Z-<sha>", "pruned": 1 }
```

Toute erreur avant l'étape 5 laisse la version précédente servie (NFR-3). Codes 200 (activé) / 409 (`activation_locked`) / 422 (`checksum_mismatch`/`release_invalid`) / 500.

### 3.3 FR-6/FR-7 — Packaging `.tar.gz` + checksum

Évolution de `scripts/package-deploy-artifact.sh` (mêmes inclusions/exclusions qu'aujourd'hui ; voir liste dans `docs/deployment-ouvaton.md`) :

- Même staging + même `composer install --no-dev` + **mêmes contrôles de fichiers interdits** (le bloc anti-`.env`/secret existant est conservé tel quel — c'est lui qui garantit FR-7/NFR-1).
- Remplacer la création `zip` par :

```bash
ARCHIVE="${PROJECT_ROOT}/build/kermesse-deploy.tar.gz"
tar -czf "${ARCHIVE}" -C "${STAGING_DIR}" .
sha256sum "${ARCHIVE}" | cut -d' ' -f1 > "${ARCHIVE}.sha256"
```

- La validation post-archive (re-scan du contenu pour fichiers interdits) est rejouée sur la liste `tar -tzf` au lieu de `unzip -Z -1`.

> **Format cible unique : `.tar.gz`.** Le `.zip` legacy n'est conservé que le temps strict de la story de bascule (§7.5), purement comme filet de rollback du pipeline, puis **supprimé**. L'état final ne produit et ne transfère qu'un seul format, `.tar.gz` + `.sha256`.

### 3.4 FR-8 — Transfert d'un seul bloc

Le `put` lftp envoie **un fichier** (`.tar.gz`) + son `.sha256` dans `staging/`, au lieu de `mirror --reverse --delete`. Le shim `httpdocs/index.php` (qui pointe vers `current`) est stable et n'est (re)déposé que s'il change. Détail dans le workflow §7.

---

## 4. Objectif 3 — Migrations automatiques et idempotentes

> Réalise PJ-4. Couvre FR-11 à FR-15. Tranche R-3 (→ D-8) et R-6 (documenté §9).

### 4.1 FR-11 — Réutilisation sans régression

Le moteur reste `MigrationRunnerService` + `POST /ops/migrate`, contrat `docs/migration-runner.md` inchangé (en-têtes, payload signé, 200/500, verrou, idempotence par checksum, anti-dérive). Aucune seconde mécanique. `$migrations->latest()` (runner spark) est **explicitement écarté** (voir §6.3 / D-4).

### 4.2 D-3 — `OpsAuthFilter` multi-routes (changement requis)

Aujourd'hui `OpsAuthFilter::isSignatureValid()` code en dur `$routePath = 'ops/migrate'`. Pour sécuriser `ops/activate`, `ops/probe`, `ops/migrate/status` avec le même filtre **sans réduire la sécurité**, le `routePath` doit être dérivé de la requête, de sorte que la signature soit **liée à la route appelée** (un message signé pour `ops/activate` ne peut pas être rejoué sur `ops/migrate`).

```diff
- $routePath = 'ops/migrate'; // Stable route path, independent of base URL
+ // routePath dérivé de l'URI, normalisé sans slash de tête ni base URL,
+ // afin que la signature soit liée à la route ops appelée (anti cross-route replay).
+ $routePath = trim($request->getUri()->getPath(), '/');
```

Conséquences :

- Les appelants (workflow, scripts locaux, doc) signent désormais le `routePath` réel (`ops/migrate`, `ops/activate`, …). Pour `ops/migrate`, le chemin normalisé reste `ops/migrate` → **compatibilité ascendante** préservée.
- `docs/migration-runner.md` est mis à jour : la ligne `routePath` du payload devient « le chemin de la route ops appelée », avec exemples pour chaque route.
- Le `before()` du filtre reste générique (POST-only, gate prod, secret, en-têtes, fraîcheur, signature, nonce) ; il s'applique déjà à n'importe quelle route via la config de route.

### 4.3 FR-14 / R-3 — Vérification d'état sans mutation → `POST /ops/migrate/status`

**Décision D-8 : route dédiée en lecture seule**, plutôt qu'un paramètre `dryRun` sur `/ops/migrate`. Justification (compromis) :

- *Pour la route dédiée* : séparation lecture/écriture nette (least privilege), logs et observabilité non ambigus, pas de risque qu'un body mal formé bascule un statut en exécution réelle, pas de verrou d'écriture nécessaire.
- *Contre* : une route de plus à câbler dans `OpsAuthFilter` — coût marginal nul depuis D-3.

Implémentation : méthode `MigrationRunnerService::status()` (lecture seule) qui réutilise `discoverMigrations()` + `getAppliedVersions()` **sans** `applyMigration()` ni verrou d'écriture. Contrôleur `MigrationController::status()`.

```json
{ "ok": true, "pending": ["003_add_x"], "applied": ["001_init","002_y"], "failed": [] }
```

Sert (a) la vérification de la répétition locale (§5) et (b) l'observabilité avant/après déploiement. Toujours 200 si la lecture réussit (l'état « en attente » n'est pas une erreur).

### 4.4 FR-12 / FR-15 — Déclenchement post-activation et propagation d'échec

- Étape **automatique finale** de chaque déploiement (réel et simulé) : après `/ops/activate` réussi, appel **inconditionnel** de `/ops/migrate` (c'est le runner idempotent qui décide s'il y a quelque chose à appliquer ; un déploiement sans nouvelle migration → `applied: 0`).
- Le runner lit les SQL depuis la release **activée** (`ROOTPATH` résolu via `current`), garantissant la cohérence code ⇄ migrations de cette release.
- **Succès du déploiement** ⟺ `/ops/migrate` renvoie `200` avec `failed = 0`. Tout `failed > 0` ou code ≠ 200 fait échouer l'étape de façon visible (`curl -f` côté CI).

---

## 5. Objectif 4 — Répétition complète du déploiement en local

> Réalise PJ-1 (crucial). Couvre FR-16 à FR-20, NFR-7.

### 5.1 FR-16/FR-18 — Orchestrateur à commande unique, mêmes scripts qu'en CI

Script `scripts/deploy-rehearsal.sh` (= **mêmes** briques que la CI, paramétrées par variables d'env ; pas de chemin local-only — NFR-7) enchaînant :

```
1. scripts/package-deploy-artifact.sh        # FR-6/7 : .tar.gz + .sha256
2. scripts/transfer-archive.sh               # FR-8  : put vers la cible (SFTP local ou prod)
3. curl POST /ops/activate                   # FR-9  : activation atomique
4. curl POST /ops/migrate                     # FR-12 : migration auto inconditionnelle
5. curl POST /ops/migrate/status              # FR-14/15 : vérification d'état finale
```

Variables d'env pilotant la cible (local vs CI/prod) : `TARGET_HOST`, `TARGET_PORT`, `TARGET_PROTO` (`sftp`/`ftps`), `BASE_URL`, `OPS_HMAC_SECRET`. En local elles pointent le service `deploy-target` ; en CI/prod, Ouvaton. **Le script ne sait pas s'il tourne en local ou en CI** : c'est l'environnement qui change, pas le code (FR-18 / « ça passe en local » prédit « ça passe en prod »).

Sortie : statut final non ambigu (`REHEARSAL OK` / `REHEARSAL FAILED: <étape>`), résumé lisible en français (NFR-6), code de sortie ≠ 0 à la première étape qui échoue.

### 5.2 FR-17 / R-4 — Cible locale Ouvaton-like

Ajout au `docker-compose.yml` sous **profil `rehearsal`** (`docker compose --profile rehearsal up`) :

- `deploy-target` : conteneur SFTP (ex. `atmoz/sftp`) chrooté, exposant un home qui reproduit le layout §3.1 (`staging/`, `shared/`, `releases/`, `current`, `httpdocs/`) sur un volume nommé `deploy-target-data`. Joue le rôle du compte Ouvaton.
- `deploy-web` : **même image** que `app`, document root = `httpdocs/`, app monté depuis le volume partagé, **mêmes limites `php.ini`** (§2). C'est ce service qui sert la release active et qui héberge `/ops/activate`, `/ops/migrate`, `/ops/probe`.

**Écarts connus à documenter (R-4, NFR-4)** : permissions/chroot, propriétaire des fichiers, comportement exact de `symlink()`/`rename()`, document root réel Ouvaton (`httpdocs/`). La cible locale **approche** Ouvaton mais ne le reproduit pas au bit près ; chaque écart relevé par la sonde est consigné dans une section « Écarts local ⇄ Ouvaton » de `docs/deployment-ouvaton.md`.

### 5.3 FR-19 — Injection d'échecs

L'orchestrateur accepte un mode `--inject <cas>` pour la répétition :

| Cas | Manipulation | Attendu |
|-----|--------------|---------|
| `truncated-transfer` | Tronquer le `.tar.gz` après `put` | `/ops/activate` → `checksum_mismatch`, `current` inchangé, ancienne version servie (NFR-3) |
| `bad-checksum` | Altérer le sidecar `.sha256` | `/ops/activate` → `checksum_mismatch`, rejet |
| `failing-migration` | Injecter un SQL invalide dans `database/migrations_sql/` | `/ops/migrate` → `failed > 0`, déploiement en échec visible (FR-15) |

### 5.4 FR-20 — Idempotence & reset

`scripts/deploy-rehearsal.sh --reset` : purge `staging/` et `releases/` de la cible locale, remet `current` à une release témoin (ou vide), tronque les tables techniques de test. Relancer à blanc ne laisse aucun état bloquant. Aucune dépendance réseau externe / Ouvaton.

---

## 6. Endpoint sécurisé de migration — décision tranchée (livrable n°2)

### 6.1 Décision : authentification HMAC uniquement (D-1)

**Toute exécution de migration (et toute route ops) passe par le socle HMAC existant** : `POST /ops/migrate`, `OpsAuthFilter` HMAC-SHA256 + fraîcheur timestamp + anti-rejeu nonce + verrou MariaDB + gate production-only + runner SQL idempotent à checksums. C'est la seule approche retenue ; il n'existe pas d'option de repli vers un token statique.

L'alternative `App\Controllers\Deploy::migrate()` protégée par un en-tête statique `X-Deploy-Token` est **écartée définitivement**. Justification (à valeur de trace de décision, pas d'option ouverte) :

| Critère | `X-Deploy-Token` statique — ❌ écarté | HMAC `OpsAuthFilter` — ✅ retenu |
|---------|---------------------------------------|----------------------------------|
| Rejeu | Vulnérable (token fixe rejouable si capté) | Protégé (nonce à usage unique) |
| Fenêtre d'exposition | Permanente tant que le token vit | Bornée (fraîcheur timestamp) |
| Liaison à la requête | Aucune (token porte tout) | Signature liée méthode+route+corps |
| Conformité `claude.md` | ❌ « never expose migration execution without HMAC, timestamp freshness, nonce replay, production-only, DB lock » | ✅ |
| Conformité PRD FR-11 | ❌ second mécanisme | ✅ réutilisation |
| État d'implémentation | À écrire | Déjà écrit, testé, documenté |

Le seul attrait du token statique (simplicité d'appel) est nul ici puisque le socle HMAC est déjà livré, et son coût en sécurité est réel. **Aucun `X-Deploy-Token` n'est introduit, en production comme hors-production.**

### 6.2 Forme retenue de l'endpoint

Pas de nouveau contrôleur `Deploy` : on **réutilise** `App\Controllers\Ops\MigrationController` et on **ajoute** les routes ops manquantes, toutes derrière `ops-auth` :

```php
// app/Config/Routes.php — bloc ops
$routes->post('ops/migrate',        '\App\Controllers\Ops\MigrationController::migrate', ['filter' => 'ops-auth']);
$routes->post('ops/migrate/status', '\App\Controllers\Ops\MigrationController::status',  ['filter' => 'ops-auth']); // D-8
$routes->post('ops/activate',       '\App\Controllers\Ops\ActivateController::activate', ['filter' => 'ops-auth']); // FR-9
$routes->post('ops/probe',          '\App\Controllers\Ops\ProbeController::probe',       ['filter' => 'ops-auth']); // FR-1/2
```

Penser à étendre l'exclusion CSRF (déjà `['except' => ['ops/migrate']]` dans `Config\Filters`) aux nouvelles routes ops :

```php
'csrf' => ['except' => ['ops/migrate', 'ops/migrate/status', 'ops/activate', 'ops/probe']],
```

### 6.3 D-4 — Pourquoi pas `$migrations->latest()`

`$migrations->latest()` est le runner *spark* de CodeIgniter : classes PHP `database/Migrations/`, table d'état `migrations`. Le projet a délibérément choisi un runner **SQL** (`database/migrations_sql/*.sql`, table `schema_versions`) parce que la production Ouvaton ne peut pas exécuter `php spark migrate`, et parce que ce runner ajoute checksums, anti-dérive et verrou. Utiliser `$migrations->latest()` créerait un **second mécanisme concurrent** (FR-11 l'interdit), avec deux tables d'état et deux conventions de fichiers. → On garde `MigrationRunnerService::run()`.

---

## 7. Schéma logique du workflow GitHub Actions (livrable n°3)

> Couvre FR-6→9, FR-12, FR-15, R-5. **Décision D-10 : on fait évoluer `.github/workflows/deploy-ouvaton.yml` en place.** Aucun second workflow `deploy.yml` n'est créé : il doublerait le déclencheur `workflow_run` et provoquerait des déploiements concurrents. Le schéma ci-dessous est la cible appliquée à ce fichier unique.

### 7.1 Pipeline cible (Build → Tar.gz → Upload → Activate → Migrate)

```
job build-and-package (inchangé sur le principe)
  1. checkout (head_sha du run CI)
  2. setup PHP (version = PHP_VERSION_OUVATON mesurée)
  3. composer validate + install (dev) + phpunit (--exclude-group mariadb)
  4. scripts/package-deploy-artifact.sh   →  build/kermesse-deploy.tar.gz (+ .sha256)   [FR-6/7]
  5. upload-artifact (tar.gz + sha256)

job deploy  (needs: build-and-package, environment: production)
  1. install lftp ; configure known_hosts SFTP
  2. download-artifact
  3. Upload « un bloc » : put .tar.gz + .sha256 → kermesse/staging/        [FR-8]
       (lftp put, PAS mirror)
  4. (si changé) put httpdocs/index.php (shim → current) + .htaccess
  5. Webhook ACTIVATION :
       curl -f POST {BASE_URL}/ops/activate   (HMAC, body={"archive":"...","sha256":"..."})
       → extraction + bascule atomique current  [FR-9]   ; échec ⇒ job rouge
  6. Webhook MIGRATION :
       curl -f POST {BASE_URL}/ops/migrate     (HMAC)     [FR-12]
       → succès ssi 200 & failed=0             [FR-15]    ; échec ⇒ job rouge
  7. (option observabilité) curl POST {BASE_URL}/ops/migrate/status  [FR-14]
```

### 7.2 Étape « upload un bloc » (remplace le `mirror`)

Le bloc `Transfer files to Ouvaton via SFTP` actuel (`mirror --reverse --delete`) est remplacé par un `put` de l'archive seule :

```bash
{
  printf 'set cmd:fail-exit yes\n'
  printf 'set sftp:known-hosts %s\n' "${HOME}/.ssh/known_hosts"
  printf 'open %s\n' "$(lftp_quote "sftp://${OUVATON_DEPLOY_HOST}:115")"
  printf 'user %s %s\n' "$(lftp_quote "${OUVATON_DEPLOY_USERNAME}")" "$(lftp_quote "${OUVATON_DEPLOY_PASSWORD}")"
  printf 'lcd build\n'
  printf 'cd %s/staging\n' "$(lftp_quote "${OUVATON_DEPLOY_REMOTE_FOLDER}")"
  printf 'put kermesse-deploy.tar.gz\n'
  printf 'put kermesse-deploy.tar.gz.sha256\n'
  printf 'bye\n'
} | lftp
```

`.env` (dans `shared/`) et `writable/` ne sont jamais dans le flux de transfert ni dans l'archive (NFR-2). La règle de préservation `.env` de `deploy-ouvaton.yml` reste valide *a fortiori*.

### 7.3 Étape « webhook activation » (nouvelle, avant la migration)

Calque la signature HMAC déjà utilisée pour `/ops/migrate`, **avec `routePath = ops/activate`** (D-3) :

```bash
BODY="$(printf '{"archive":"kermesse-deploy.tar.gz"}')"
TIMESTAMP="$(date +%s)"; NONCE="$(openssl rand -hex 16)"
BODY_HASH="$(printf '%s' "$BODY" | sha256sum | cut -d' ' -f1)"
PAYLOAD="$(printf '%s\n%s\nPOST\nops/activate\n%s' "$TIMESTAMP" "$NONCE" "$BODY_HASH")"
SIGNATURE="$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$OPS_MIGRATION_HMAC_SECRET" | cut -d' ' -f2)"

curl -fsS -X POST "${BASE_URL%/}/ops/activate" \
  -H "Content-Type: application/json" \
  -H "X-Kermesse-Timestamp: ${TIMESTAMP}" \
  -H "X-Kermesse-Nonce: ${NONCE}" \
  -H "X-Kermesse-Signature: ${SIGNATURE}" \
  -d "$BODY"
```

### 7.4 Étape « webhook migration » (existante, conservée)

Le bloc `Run post-deploy migrations` actuel est **réutilisé tel quel** (HMAC, garde `https://`, garde longueur secret ≥ 32, `KERMESSE_ALLOW_INSECURE_TLS` temporaire). Il s'exécute désormais **après** l'activation. `curl -f` propage l'échec (FR-15).

### 7.5 R-5 — Transition sans casse

- Étape 1 : ajouter le packaging `.tar.gz` à côté du `.zip` ; ajouter `/ops/activate` + `/ops/probe` + `/ops/migrate/status` (routes + filtre D-3) ; valider par la répétition locale (§5).
- Étape 2 : basculer `deploy-ouvaton.yml` : `put` tar.gz + webhook activate, **avant** le webhook migrate déjà présent ; supprimer le `mirror` applicatif.
- Étape 3 : retirer le `.zip` legacy une fois la chaîne validée en prod.
- Le déclencheur `workflow_run` reste unique (D-10) → pas de double déploiement.

---

## 8. Configuration & variables (`Config\Kermesse`, `.env.example`)

Nouvelles clés à ajouter à `Config\Kermesse` (et documenter dans `.env.example`) :

| Clé | Défaut | Rôle |
|-----|--------|------|
| `kermesse.opsProbeEnabled` | `false` | Active `/ops/probe` (FR-2). `true` seulement pour mesurer. |
| `kermesse.opsActivateLockName` | `kermesse_ops_activate_lock` | Verrou nommé distinct pour l'activation (FR-9). |
| `kermesse.releasesRetention` | `3` | Nombre de releases conservées (FR-10). |
| `kermesse.releasesBasePath` | (dérivé) | Base `kermesse/` côté serveur (releases/shared/current). |

Réutilisés sans changement : `opsMigrationHmacSecret`, `opsMigrationAllowedTimestampSkew`, `opsMigrationNonceTTL`, `opsMigrationProductionOnly`, `opsMigrationLockName`, `opsMigrationPath`. **Aucune** clé `X-Deploy-Token` / token statique n'est introduite (D-1) : l'unique secret d'authentification ops reste `opsMigrationHmacSecret`, partagé par les quatre routes ops.

Variables CI/CD : pas de nouveau secret obligatoire — `OPS_MIGRATION_HMAC_SECRET` couvre `activate`, `migrate`, `migrate/status`, `probe`. Ajout d'une variable d'env de cible pour la répétition locale (`TARGET_*`, non sensibles, fournies par `docker-compose.yml` profil `rehearsal`).

---

## 9. Risques & décisions (suivi PRD)

| R | Sujet | Traitement architecture |
|---|-------|-------------------------|
| **R-1** | Activation atomique sans CLI | **Tranché** : `/ops/activate` + layout releases/current (§3). |
| **R-2** | Mesure des limites réelles | **Tranché** : `/ops/probe` mesure, puis `php.ini`/`Dockerfile`/`compose` calibrés (§2). |
| **R-3** | Forme du dry-run migration | **Tranché → D-8** : route dédiée `POST /ops/migrate/status` lecture seule (§4.3). |
| **R-4** | Fidélité de la cible locale | **Atténué, ouvert** : cible `deploy-target`+`deploy-web` (§5.2) ; écarts (symlink, chroot, perms, httpdocs) consignés dans `deployment-ouvaton.md`. Confirmer `symlink()`/`rename()` Ouvaton (sinon fallback pointeur, §3.1). |
| **R-5** | Coexistence pipeline | **Plan §7.5** : tar.gz à côté du zip, ajout des routes, bascule en place de `deploy-ouvaton.yml`, retrait du legacy. |
| **R-6** | Fenêtre nouveau code / ancien schéma | **Documenté** : ordre activation→migration conservé (cohérent pipeline actuel). Fenêtre courte acceptée MVP mono-opérateur ; à réévaluer si une migration devient incompatible avec le code précédent (alors : maintenance page, ou migration expand/contract). |
| **R-7 (nouveau)** | `OpsAuthFilter` routePath dynamique | Le passage routePath codé en dur → dérivé URI (D-3) doit préserver `ops/migrate` à l'identique. Test de non-régression de signature obligatoire avant bascule. |
| **R-8 (nouveau)** | Sonde laissée active en prod | `opsProbeEnabled` défaut `false` + auth HMAC ; checklist de désactivation post-mesure. |

---

## 10. Découpage indicatif (handoff vers epics/stories)

Ordre conseillé pour `bmad-create-epics-and-stories` :

1. **Parité runtime** : `php.ini` + `Dockerfile`/`compose` + `show-runtime-config.sh` + `/ops/probe` (D-3 prérequis). → Objectif 1, FR-1→5.
2. **Filtre ops multi-routes** : D-3 + tests de non-régression signature + maj `migration-runner.md`. → socle FR-9/14.
3. **Dry-run** : `MigrationRunnerService::status()` + `/ops/migrate/status`. → FR-14.
4. **Activation atomique** : layout releases/shared/current + `ReleaseActivationService` + `/ops/activate`. → FR-9/10.
5. **Packaging tar.gz** : évolution `package-deploy-artifact.sh` + transfert `put`. → FR-6/7/8.
6. **Répétition locale** : `deploy-rehearsal.sh` + profil compose `rehearsal` + injections d'échec + reset. → FR-16→20.
7. **Bascule CI** : `deploy-ouvaton.yml` (tar.gz + activate + migrate). → FR-12/15, R-5.

Chaque story livrée avec ses tests (unitaires services, feature pour les routes ops, non-régression signature), conformément aux règles de test de `claude.md`. Rappel workflow projet : développer sur branche feature + PR vers `main`.

---

## 11. Décisions verrouillées & confirmations restantes

### 11.1 Décisions verrouillées (validées par Sylvain, 2026-06-06)

Aucune de ces décisions n'est rouverte ; elles cadrent le découpage en stories.

- **D-1** — Authentification ops : **HMAC `OpsAuthFilter` uniquement**. `X-Deploy-Token` écarté définitivement.
- **D-4** — Runner SQL existant (`MigrationRunnerService` + `schema_versions`). `$migrations->latest()` interdit.
- **D-10** — Pipeline : **`deploy-ouvaton.yml` fait évoluer en place**. Pas de `deploy.yml` parallèle.
- **D-3** — `OpsAuthFilter` à `routePath` dérivé de l'URI (sécurise les 4 routes ops). Test de non-régression de signature `ops/migrate` obligatoire (R-7).
- **D-6** — Activation atomique `releases/` + `current` + `shared/{.env,writable}` via `/ops/activate`.
- **D-7** — Format unique `.tar.gz` + `.sha256`, transféré par `put`. `.zip` retiré après la story de bascule.
- **D-8** — Dry-run = route dédiée `POST /ops/migrate/status` (lecture seule).

### 11.2 Confirmations restantes — dépendantes de la mesure sonde

Ce ne sont pas des arbitrages d'architecture, seulement des valeurs à relever via `/ops/probe` puis à reporter :

1. **Version PHP exacte Ouvaton** → `ARG PHP_VERSION_OUVATON` (point de départ `8.3`).
2. **Version MariaDB Ouvaton** → **`10.11`, confirmée manuellement par Sylvain le 2026-06-06** (la sonde ne fera que la reconfirmer). L'incohérence `11.4` de `docs/local-orbstack.md` est corrigée vers `10.11` ; le compose épingle `10.11`.
3. **Limites PHP réelles** (`memory_limit`, `max_execution_time`, tailles d'upload) → `docker/app/php.ini`.
4. **Liste d'extensions** réellement chargées → alignement `Dockerfile` (FR-4).
5. **Support `symlink()` / `rename()` sur lien côté Ouvaton** → confirme la bascule par symlink, sinon fallback fichier pointeur `CURRENT_RELEASE` (R-4). Tous deux déjà spécifiés en §3.1.

---

_Document rédigé par Winston (System Architect). Décisions d'architecture validées par Sylvain le 2026-06-06 ; prêt pour le découpage en epics/stories (`bmad-create-epics-and-stories`)._
