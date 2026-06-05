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

### Étape 3 : Packaging et déploiement applicatif (manuel)

Le workflow `.github/workflows/deploy-ouvaton.yml` se déclenche via `workflow_dispatch` :

1. Checkout, setup PHP, validation Composer, tests
2. Exécution de `scripts/package-deploy-artifact.sh`
3. Publication de l'archive `kermesse-deploy.zip` comme artefact GitHub (14 jours)
4. Transfert vers Ouvaton via le protocole confirmé, quand le job `deploy` sera activé
5. Appel post-déploiement de `POST /ops/migrate` via HTTPS/HMAC pour appliquer les migrations en utilisant la connexion MariaDB configurée dans le `.env` de production

Ce workflow ne déclare pas de service MariaDB Docker : la production Ouvaton utilise la base MariaDB managée déjà fournie par l'hébergeur.

## Contenu de l'artefact

## Document root Ouvaton

Le document root public doit pointer vers le dossier `public/` de l'artefact, et non vers la racine de l'artefact.

La racine de l'artefact contient aussi `app/`, `vendor/`, `writable/`, `database/` et `docs/`. Ces dossiers sont nécessaires au runtime CodeIgniter, mais ils ne doivent pas être exposés directement par le serveur web. Si le compte Ouvaton impose un chemin web unique, le transfert réel devra préserver cette séparation en plaçant le contenu public dans le webroot et les autres dossiers hors exposition directe, ou en configurant le webroot vers `public/` avant activation du déploiement.

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

## Secrets GitHub Actions

Configurer ces secrets dans l'environnement GitHub `production` :

| Secret | Description |
|--------|-------------|
| `OUVATON_DEPLOY_PROTOCOL` | Protocole de transfert pour secrets : `ftps` ou `sftp`. `ftp` est refusé pour le `.env` |
| `OUVATON_DEPLOY_HOST` | Nom d'hôte du serveur Ouvaton |
| `OUVATON_DEPLOY_USERNAME` | Nom d'utilisateur du compte Ouvaton |
| `OUVATON_DEPLOY_PASSWORD` | Mot de passe du compte Ouvaton. Les clés SSH privées ne sont pas prises en charge par ce workflow |
| `OUVATON_DEPLOY_REMOTE_PATH` | Chemin distant contenant le `.env` CodeIgniter (ex. `/www/kermesse`) |
| `OUVATON_SFTP_KNOWN_HOSTS` | Requis si `OUVATON_DEPLOY_PROTOCOL=sftp` ; ligne `known_hosts` attendue pour vérifier l'hôte SSH |
| `KERMESSE_PUBLIC_BASE_URL` | URL publique canonique de l'application |
| `KERMESSE_APP_TIMEZONE` | Optionnel ; timezone applicative. Défaut workflow : `Europe/Paris` |
| `KERMESSE_SESSION_SAVE_PATH` | Chemin absolu du dossier de sessions sur Ouvaton |
| `KERMESSE_DATABASE_HOSTNAME` | Hôte MariaDB Ouvaton |
| `KERMESSE_DATABASE_DATABASE` | Nom de la base MariaDB |
| `KERMESSE_DATABASE_USERNAME` | Utilisateur MariaDB |
| `KERMESSE_DATABASE_PASSWORD` | Mot de passe MariaDB |
| `KERMESSE_DATABASE_PORT` | Port MariaDB |
| `KERMESSE_EMAIL_SMTP_HOST` | Hôte SMTP |
| `KERMESSE_EMAIL_SMTP_USER` | Utilisateur SMTP |
| `KERMESSE_EMAIL_SMTP_PASS` | Mot de passe SMTP |
| `KERMESSE_EMAIL_SMTP_PORT` | Port SMTP |
| `KERMESSE_EMAIL_SMTP_CRYPTO` | Chiffrement SMTP (`tls`, `ssl` ou valeur attendue par CodeIgniter) |
| `KERMESSE_EMAIL_FROM_EMAIL` | Adresse expéditrice |
| `KERMESSE_EMAIL_FROM_NAME` | Nom expéditeur |
| `KERMESSE_TOKEN_SECRET` | Secret applicatif de 32 octets minimum |
| `OPS_MIGRATION_HMAC_SECRET` | Secret HMAC du runner de migrations ops |

## Configurer les secrets GitHub — guide pas-à-pas

Tous les secrets listés ci-dessus sont à saisir dans l'**environnement GitHub `production`** (et non dans les secrets de dépôt ou d'organisation). Procédure :

1. Ouvrir le dépôt sur GitHub → **Settings** → **Environments**
2. Créer l'environnement `production` s'il n'existe pas encore
3. Dans l'environnement `production`, cliquer **Add secret** pour chaque ligne de la table ci-dessus

Ordre recommandé pour une première installation :

**Secrets Ouvaton (déploiement et accès serveur)**

| Priorité | Secret | Exemple / format |
|----------|--------|-----------------|
| 1 | `OUVATON_DEPLOY_PROTOCOL` | `ftps` ou `sftp` |
| 2 | `OUVATON_DEPLOY_HOST` | `ftp.ouvaton.coop` |
| 3 | `OUVATON_DEPLOY_USERNAME` | `moncompte` |
| 4 | `OUVATON_DEPLOY_PASSWORD` | mot de passe FTP/SFTP Ouvaton |
| 5 | `OUVATON_DEPLOY_REMOTE_PATH` | `/www/kermesse` |
| 6 | `OUVATON_SFTP_KNOWN_HOSTS` | Uniquement si `sftp` — ligne `known_hosts` au format `host ssh-rsa AAAA…` |

Pour obtenir la ligne `OUVATON_SFTP_KNOWN_HOSTS`, exécuter depuis un poste local :
```bash
ssh-keyscan -H <nom-hote-ouvaton>
```
Copier la ligne complète dans le secret.

**Secrets applicatifs (configuration `.env` production)**

| Priorité | Secret | Exemple / format |
|----------|--------|-----------------|
| 7 | `KERMESSE_PUBLIC_BASE_URL` | `https://kermesse.monasso.fr/` |
| 8 | `KERMESSE_SESSION_SAVE_PATH` | `/home/moncompte/kermesse/writable/session` |
| 9 | `KERMESSE_DATABASE_HOSTNAME` | fourni par Ouvaton dans l'espace client |
| 10 | `KERMESSE_DATABASE_DATABASE` | nom de la base MariaDB Ouvaton |
| 11 | `KERMESSE_DATABASE_USERNAME` | utilisateur MariaDB Ouvaton |
| 12 | `KERMESSE_DATABASE_PASSWORD` | mot de passe MariaDB Ouvaton |
| 13 | `KERMESSE_DATABASE_PORT` | `3306` |
| 14 | `KERMESSE_EMAIL_SMTP_HOST` | hôte SMTP de votre fournisseur d'email |
| 15 | `KERMESSE_EMAIL_SMTP_USER` | identifiant SMTP |
| 16 | `KERMESSE_EMAIL_SMTP_PASS` | mot de passe SMTP |
| 17 | `KERMESSE_EMAIL_SMTP_PORT` | `587` (TLS) ou `465` (SSL) |
| 18 | `KERMESSE_EMAIL_SMTP_CRYPTO` | `tls` ou `ssl` |
| 19 | `KERMESSE_EMAIL_FROM_EMAIL` | `no-reply@monasso.fr` |
| 20 | `KERMESSE_EMAIL_FROM_NAME` | `Kermesse` |
| 21 | `KERMESSE_TOKEN_SECRET` | chaîne aléatoire de 32 octets minimum — générer avec `openssl rand -hex 32` |
| 22 | `OPS_MIGRATION_HMAC_SECRET` | chaîne aléatoire de 32 octets minimum — générer avec `openssl rand -hex 32` |
| 23 | `KERMESSE_APP_TIMEZONE` | `Europe/Paris` (optionnel, c'est le défaut) |

Générer les deux secrets aléatoires en une commande :
```bash
echo "KERMESSE_TOKEN_SECRET=$(openssl rand -hex 32)"
echo "OPS_MIGRATION_HMAC_SECRET=$(openssl rand -hex 32)"
```

**Vérification**

Après saisie de tous les secrets, ouvrir **Settings → Environments → production** et vérifier que les 22 secrets (ou 23 avec `KERMESSE_APP_TIMEZONE`) sont listés. Aucun secret ne doit afficher une valeur vide.

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

**⚠️ Le protocole exact dépend du compte Ouvaton et reste à confirmer.**

Le workflow de synchronisation du `.env` utilise `lftp`, mais il refuse FTP car le fichier contient des secrets. La valeur `OUVATON_DEPLOY_PROTOCOL` doit être `ftps` ou `sftp`.

Pour SFTP, configurer `OUVATON_SFTP_KNOWN_HOSTS` avec la ligne `known_hosts` exacte du serveur Ouvaton. Le workflow n'accepte pas automatiquement les clés hôtes inconnues.

Le workflow de déploiement est structuré avec un job `deploy` désactivé (`if: false`). Pour l'activer :

1. Confirmer le protocole disponible sur le compte Ouvaton
2. Configurer les secrets GitHub Actions correspondants
3. Remplacer le placeholder `Deploy to Ouvaton` par une vraie étape de transfert qui échoue en cas d'erreur
4. Retirer la condition `if: false` du job `deploy`
5. Adapter l'étape de transfert selon le protocole :
   - FTPS : `lftp` ou une action de transfert fichier compatible
   - SFTP : transfert fichier SFTP uniquement, sans shell distant ni `rsync`

Le workflow de déploiement refuse les refs autres que `main` et vérifie qu'un run CI réussi existe pour le SHA à déployer. Cela évite de packager ou migrer la production depuis une branche de travail.

## Version PHP

Le projet requiert PHP `^8.2` (CodeIgniter 4.7.x). La CI utilise PHP 8.3.
**La version PHP exacte sur Ouvaton reste à confirmer** avant d'activer le déploiement réel.

## Migrations post-déploiement

Les migrations sont appliquées via `POST /ops/migrate`, protégé par HMAC-SHA256.

Après chaque déploiement applicatif, une étape post-deploy doit appeler cette route pour appliquer les migrations SQL en attente sur la MariaDB managée Ouvaton déjà configurée. Voir `docs/migration-runner.md` pour le contrat complet (en-têtes, payload signé, codes de réponse, verrouillage).

Le secret `OPS_MIGRATION_HMAC_SECRET` doit être configuré dans l'environnement GitHub `production`.

Les fichiers SQL de migrations sont inclus dans l'artefact (`database/migrations_sql/`).
