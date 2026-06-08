# Environnement local OrbStack

Ce guide lance Kermesse dans des conteneurs locaux avec Docker Compose v2 via OrbStack.

## Prerequis

- OrbStack installe et demarre.
- Le depot Kermesse clone localement.
- Aucun PHP, Composer ou MariaDB hote n'est requis pour lancer l'application.

## Demarrer

Depuis la racine du depot :

```bash
docker compose up --build
```

Au premier demarrage, le conteneur `app` installe les dependances Composer dans un volume Docker nomme. L'application est ensuite disponible sur :

```text
http://localhost:8080/
```

Le service web utilise Apache avec `public/` comme document root, comme attendu pour l'artefact Ouvaton.

Le port HTTP hote est configurable si `8080` est deja occupe :

```bash
KERMESSE_HTTP_PORT=8081 docker compose up --build
```

## Appliquer les migrations

Au premier demarrage, la base MariaDB `kermesse` est vide. L'application demarre mais les tables n'existent pas encore, ce qui provoque des erreurs de type `Table 'kermesse.owners' doesn't exist` a la premiere requete.

Il faut appliquer les migrations une fois les conteneurs demarres.

### Via l'endpoint ops/migrate (methode recommandee)

Le secret HMAC et la desactivation de la protection production-only sont pre-configures dans `docker-compose.yml` :

```bash
HMAC_SECRET="local_dev_ops_secret_32_bytes_minimum"
BASE_URL="http://localhost:8080"

TIMESTAMP=$(date +%s)
NONCE=$(php -r "echo bin2hex(random_bytes(16));")
BODY='{}'
BODY_HASH=$(printf "%s" "$BODY" | sha256sum | cut -d' ' -f1)
PAYLOAD="${TIMESTAMP}\n${NONCE}\nPOST\nops/migrate\n${BODY_HASH}"
SIGNATURE=$(printf "%b" "$PAYLOAD" | openssl dgst -sha256 -hmac "$HMAC_SECRET" | cut -d' ' -f2)

curl -s -X POST "${BASE_URL}/ops/migrate" \
  -H "Content-Type: application/json" \
  -H "X-Kermesse-Timestamp: ${TIMESTAMP}" \
  -H "X-Kermesse-Nonce: ${NONCE}" \
  -H "X-Kermesse-Signature: ${SIGNATURE}" \
  -d "$BODY"
```

Reponse attendue : `{"ok":true,"applied":1,"skipped":0,"failed":0}`.
Relancer la commande apres ajout d'une migration : le runner applique uniquement les migrations absentes ou precedemment echouees.

Si le port HTTP est different de 8080, adapter `BASE_URL` en consequence.

### Mesurer le runtime via ops/probe

La sonde `POST /ops/probe` renvoie les faits de configuration runtime du conteneur (version PHP, limites `ini`, extensions chargées, version MariaDB). Elle sert a calibrer l'environnement local sur la cible Ouvaton. Elle est activee uniquement en local : `kermesse.opsProbeEnabled: "true"` est positionne dans `docker-compose.yml` et reste `false` partout ailleurs.

Elle signe son propre `routePath` (`ops/probe`) avec le meme secret de dev que `ops/migrate` :

```bash
HMAC_SECRET="local_dev_ops_secret_32_bytes_minimum"
BASE_URL="http://localhost:8080"

TIMESTAMP=$(date +%s)
NONCE=$(php -r "echo bin2hex(random_bytes(16));")
BODY=''
BODY_HASH=$(printf "%s" "$BODY" | sha256sum | cut -d' ' -f1)
PAYLOAD="${TIMESTAMP}\n${NONCE}\nPOST\nops/probe\n${BODY_HASH}"
SIGNATURE=$(printf "%b" "$PAYLOAD" | openssl dgst -sha256 -hmac "$HMAC_SECRET" | cut -d' ' -f2)

curl -s -X POST "${BASE_URL}/ops/probe" \
  -H "Content-Type: application/json" \
  -H "X-Kermesse-Timestamp: ${TIMESTAMP}" \
  -H "X-Kermesse-Nonce: ${NONCE}" \
  -H "X-Kermesse-Signature: ${SIGNATURE}" \
  -d "$BODY"
```

Reponse attendue : un JSON `{"php_version":...,"memory_limit":...,"extensions":[...],"mariadb_version":...}`. La sonde ne renvoie aucun secret, credential ni variable `.env`. C'est une route ops temporaire de mesure, pas une API utilisateur ; ne pas l'exposer ni l'activer hors developpement local.

### Via SQL direct (alternative rapide)

```bash
for f in database/migrations_sql/*.sql; do
  docker compose exec -T db mysql -u kermesse_user -pkermesse_password kermesse < "$f"
done
```

Cette methode s'utilise aussi si l'application n'est pas encore accessible (service `app` pas encore pret).

## Services

| Service | Role | Acces local |
|---------|------|-------------|
| `app` | PHP 8.3, Apache, Composer, CodeIgniter | `http://localhost:8080/` |
| `db` | MariaDB 10.11 locale (alignee sur Ouvaton) | `127.0.0.1:3307` par defaut |
| `phpmyadmin` | Interface web d'inspection MariaDB (dev local uniquement) | `http://127.0.0.1:8082/` |

### phpMyAdmin

Disponible des le `docker compose up` standard (aucun profil requis) :

```text
http://127.0.0.1:8082/
```

Identifiants de connexion (identiques a ceux de la base locale) :

| Champ | Valeur |
|-------|--------|
| Serveur | `db` (pre-configure, champ non affiche) |
| Utilisateur | `kermesse_user` |
| Mot de passe | `kermesse_password` |

Connexion alternative avec les droits root :

| Champ | Valeur |
|-------|--------|
| Utilisateur | `root` |
| Mot de passe | `root_password` |

Le port hote est configurable si `8082` est deja occupe :

```bash
KERMESSE_PMA_PORT=8083 docker compose up
```

Ce service n'est jamais inclus dans l'artefact de deploiement ni accessible en production : Ouvaton ne fait pas tourner Docker.

Identifiants MariaDB locaux non secrets :

| Variable | Valeur |
|----------|--------|
| Database | `kermesse` |
| User | `kermesse_user` |
| Password | `kermesse_password` |
| Root password | `root_password` |

Ces valeurs sont uniquement destinees au developpement local. Elles ne doivent pas etre reutilisees en production.

Le port hote MariaDB est configurable si `3307` est deja occupe :

```bash
KERMESSE_DB_PORT=3310 docker compose up --build
```

Les volumes Docker nommes `vendor` et `writable` masquent volontairement les dossiers du depot dans le conteneur. Cela evite d'ecrire les dependances Composer et les fichiers runtime dans le checkout local.

Les ports sont lies a `127.0.0.1` pour eviter d'exposer l'application ou la base de donnees au reseau local.

## Commandes utiles

Lancer en arriere-plan :

```bash
docker compose up --build -d
```

Voir les logs :

```bash
docker compose logs -f app
```

Ouvrir un shell dans le conteneur app :

```bash
docker compose exec app bash
```

Executer les tests applicatifs :

```bash
docker compose run --rm app composer test
```

Executer les tests MariaDB et le runner de migrations contre la base locale Docker :

```bash
docker compose run --rm \
  -e "database.tests.hostname=db" \
  -e "database.tests.database=kermesse" \
  -e "database.tests.username=kermesse_user" \
  -e "database.tests.password=kermesse_password" \
  -e "database.tests.DBDriver=MySQLi" \
  -e "database.tests.DBPrefix=" \
  -e "database.tests.port=3306" \
  -e "database.tests.charset=utf8mb4" \
  -e "database.tests.DBCollat=utf8mb4_general_ci" \
  -e "kermesse.opsMigrationProductionOnly=false" \
  app vendor/bin/phpunit --testsuite App --group mariadb
```

Ces commandes testent l'application avec la MariaDB locale du service `db`. Elles ne touchent jamais la base Ouvaton.

Valider la configuration Compose :

```bash
docker compose config
```

Arreter les conteneurs en gardant les donnees locales :

```bash
docker compose down
```

Repartir de zero en supprimant les volumes MariaDB, Composer, `vendor` et `writable` :

```bash
docker compose down --volumes
```

## Repetition de deploiement (client dockerise)

La repetition de deploiement (packaging, transfert SFTP, appels ops signes) s'execute
desormais ENTIEREMENT dans un conteneur dedie `deploy-client`, sur le reseau Docker.
Cote hote, **seul Docker est requis** : plus besoin de `lftp`, `openssl`, `ssh-keyscan`,
`composer` ni d'un client `mysql` installes localement. macOS bash 3.2 n'est plus un
facteur (le conteneur tourne sous bash >= 5).

Demarrer la cible locale puis lancer la repetition :

```bash
docker compose --profile rehearsal up -d --build
bash scripts/deploy-rehearsal.sh
```

`scripts/deploy-rehearsal.sh` detecte qu'il tourne sur l'hote et se relance
automatiquement dans `deploy-client` via `docker compose --profile rehearsal run --rm`.
Les memes `scripts/*.sh` que la CI y sont montes (bind du depot), sans fork. La cible
SFTP est `deploy-target:22` et l'application `http://deploy-web` — par nom de service
sur le reseau Docker, sans port publie ni `127.0.0.1`/`::1`.

L'artefact reste produit sur le bind de l'hote : `build/kermesse-deploy.tar.gz`.

Remise a zero de la cible et injection d'echecs passent aussi par le conteneur :

```bash
bash scripts/deploy-rehearsal.sh --reset
bash scripts/deploy-rehearsal.sh --inject truncated-transfer
bash scripts/deploy-rehearsal.sh --inject bad-checksum
bash scripts/deploy-rehearsal.sh --inject failing-migration
```

Usage direct du conteneur (equivalent, utile pour debug) :

```bash
docker compose --profile rehearsal run --rm deploy-client bash scripts/deploy-rehearsal.sh
```

| Service | Role | Reseau Docker |
|---------|------|---------------|
| `deploy-client` | Client de repetition (packaging + transfert + ops) | one-shot `run --rm` |
| `deploy-target` | Cible SFTP Ouvaton-like (atmoz/sftp) | `deploy-target:22` |
| `deploy-web` | Application deployee (Apache + PHP) | `http://deploy-web` |

## Configuration locale

Le fichier `.env` n'est pas cree automatiquement et n'est pas requis pour demarrer cet environnement. Les variables de developpement sont fournies par `docker-compose.yml`.

Si un `.env` existe deja dans le depot, CodeIgniter peut le charger parce que le projet est monte dans le conteneur. Ce fichier doit rester local, non commite et syntaxiquement valide.

La commande standard `composer test` garde la configuration PHPUnit par defaut et peut skipper les tests MariaDB. Pour exercer MariaDB localement, utiliser explicitement la commande `--group mariadb` documentee plus haut avec les variables `database.tests.*` vers le service `db`.

## Separation avec Ouvaton

Cet environnement local sert au developpement et aux tests manuels. Il ne modifie pas la strategie de production :

- Ouvaton reste runtime-only.
- Ouvaton n'utilise pas Docker, Docker Compose, Composer, PHPUnit ou CLI serveur.
- La base Ouvaton est une MariaDB managée existante ; l'application s'y connecte via les credentials du `.env` de production.
- Le packaging de production reste gere par GitHub Actions.
- Les migrations de production passent par `POST /ops/migrate` sur l'application deployee, pas par un client `mysql` local ou distant.
- Aucun vrai `.env` ou secret de production ne doit etre ajoute au depot.
