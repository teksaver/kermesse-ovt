# Déploiement Ouvaton — Guide de production

## Contrainte fondamentale : runtime-only

Ouvaton est un hébergement mutualisé. **Le serveur de production ne doit jamais exécuter :**

- `composer install` ou `composer update`
- `npm install` ou tout outil Node.js
- `phpunit` ou tout framework de test
- `php spark migrate` ou toute commande CodeIgniter CLI
- Compilation d'assets (Vite, Tailwind, Webpack…)
- Opérations de cache warmup

Tout est préparé par GitHub Actions et livré en artefact prêt à exécuter.

## Pipeline de déploiement

### Étape 1 : CI (automatique)

Le workflow `.github/workflows/ci.yml` s'exécute sur chaque `push` et `pull_request` vers `main` et `develop`. Il valide `composer.json`, installe les dépendances et exécute les tests.

### Étape 2 : Packaging et déploiement (manuel)

Le workflow `.github/workflows/deploy-ouvaton.yml` se déclenche via `workflow_dispatch` :

1. Checkout, setup PHP, validation Composer, tests
2. Exécution de `scripts/package-deploy-artifact.sh`
3. Publication de l'archive `kermesse-deploy.zip` comme artefact GitHub (14 jours)
4. (Futur) Transfert vers Ouvaton via le protocole confirmé

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
- `.env`, `.env.local`, `.env.*.local`
- `auth.json`, `*.key`, `*.pem`
- Caches locaux (`writable/cache/*`, `writable/logs/*`…)

Le script de packaging vérifie automatiquement l'absence de fichiers interdits et échoue si l'un est détecté.

## Secrets GitHub Actions

Configurer ces secrets dans l'environnement GitHub `production` :

| Secret | Description |
|--------|-------------|
| `OUVATON_DEPLOY_PROTOCOL` | Protocole de transfert : `ftp`, `ftps` ou `sftp` |
| `OUVATON_DEPLOY_HOST` | Nom d'hôte du serveur Ouvaton |
| `OUVATON_DEPLOY_USERNAME` | Nom d'utilisateur du compte Ouvaton |
| `OUVATON_DEPLOY_PASSWORD` | Mot de passe ou clé SSH |
| `OUVATON_DEPLOY_REMOTE_PATH` | Chemin distant (ex. `/www/kermesse`) |
| `KERMESSE_PUBLIC_BASE_URL` | URL publique canonique de l'application |
| `OPS_MIGRATION_HMAC_SECRET` | (Futur, Story 1.3) Secret HMAC pour le runner de migrations |

## Variables `.env` de production

Le fichier `.env` de production doit être créé et maintenu **manuellement** sur le serveur Ouvaton.
Voir `.env.example` pour la liste complète des variables.

Variables critiques à configurer :

- `CI_ENVIRONMENT = production`
- `app.baseURL` — URL publique réelle
- `database.default.*` — identifiants MariaDB Ouvaton
- `session.savePath` — chemin absolu sur le serveur Ouvaton (dépend de l'arborescence)
- `email.*` — configuration SMTP
- `kermesse.tokenSecret` — secret de 32 octets minimum
- `kermesse.opsMigrationHmacSecret` — (Story 1.3) secret du runner ops

## Préservation du `.env` de production

**Règle absolue :** le déploiement ne doit **jamais** envoyer, écraser ou supprimer le fichier `.env` de production.

- L'artefact n'inclut pas de fichier `.env` (seulement `.env.example`)
- Le script de déploiement futur devra exclure `.env` de la synchronisation
- Le `.env` de production est posé manuellement lors de l'installation initiale

## Dossiers `writable/`

L'artefact inclut les placeholders de structure (`index.html`, `.htaccess`) mais **pas** les fichiers générés en runtime (logs, cache, sessions, uploads).

Sur le serveur, les sous-dossiers `writable/` doivent être **accessibles en écriture** par PHP :

- `writable/cache/`
- `writable/debugbar/` (développement uniquement)
- `writable/logs/`
- `writable/session/`
- `writable/uploads/`

## Protocole de transfert

**⚠️ Le protocole exact (FTP, FTPS ou SFTP) dépend du compte Ouvaton et reste à confirmer.**

Le workflow de déploiement est structuré avec un job `deploy` désactivé (`if: false`). Pour l'activer :

1. Confirmer le protocole disponible sur le compte Ouvaton
2. Configurer les secrets GitHub Actions correspondants
3. Retirer la condition `if: false` du job `deploy`
4. Adapter l'étape de transfert selon le protocole :
   - FTP/FTPS : `SamKirkland/FTP-Deploy-Action` ou `lftp`
   - SFTP : `rsync` via SSH ou `scp`

## Version PHP

Le projet requiert PHP `^8.2` (CodeIgniter 4.7.x). La CI utilise PHP 8.3.
**La version PHP exacte sur Ouvaton reste à confirmer** avant d'activer le déploiement réel.

## Migrations post-déploiement

Les migrations de base de données seront gérées par un appel sécurisé à `POST /ops/migrate` avec validation HMAC, fraîcheur de timestamp, protection anti-rejeu et verrouillage base de données. Ce mécanisme sera implémenté dans la **Story 1.3**.

En attendant, les fichiers SQL de migrations sont inclus dans l'artefact (`database/migrations_sql/`) pour référence.
