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

## Services

| Service | Role | Acces local |
|---------|------|-------------|
| `app` | PHP 8.3, Apache, Composer, CodeIgniter | `http://localhost:8080/` |
| `db` | MariaDB 11.4 locale | `127.0.0.1:3307` par defaut |

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
