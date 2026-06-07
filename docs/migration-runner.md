# Runner de migrations — Contrat ops

## Présentation

Le runner de migrations applique les fichiers SQL situés dans `database/migrations_sql/` en ordre lexicographique via une route ops sécurisée : `POST /ops/migrate`.

Ce mécanisme remplace `php spark migrate` car le serveur Ouvaton ne dispose pas d'accès CLI.

En production, le runner s'exécute dans l'application CodeIgniter déjà déployée. Il utilise la connexion `database.default.*` du `.env` de production pour atteindre la MariaDB managée Ouvaton existante. Il ne démarre pas Docker, ne crée pas la base de données et n'utilise pas de client `mysql` côté serveur.

## Contrat d'authentification HMAC

Chaque appel à `/ops/migrate` doit fournir trois en-têtes obligatoires :

- `X-Kermesse-Timestamp` — Unix timestamp en secondes
- `X-Kermesse-Nonce` — Valeur opaque unique (UUID v4 recommandé)
- `X-Kermesse-Signature` — Signature HMAC-SHA256 hexadécimale

### Calcul de la signature

La signature est calculée avec HMAC-SHA256 sur le payload suivant :

```
timestamp\nnonce\nmethod\nroutePath\nsha256(rawBody)
```

Où :
- `timestamp` : la valeur de `X-Kermesse-Timestamp` (chaîne)
- `nonce` : la valeur de `X-Kermesse-Nonce` (chaîne)
- `method` : `POST` (majuscules)
- `routePath` : le chemin de la route ops appelée, normalisé sans slash de tête ni base URL (`ops/migrate`, et à terme `ops/activate`, `ops/migrate/status`, `ops/probe`). Le filtre le dérive de l'URI de la requête et retire un éventuel segment `index.php/` de tête, de sorte que chaque route ops signe son propre chemin et qu'un message signé pour une route ne puisse être rejoué sur une autre.
- `sha256(rawBody)` : SHA-256 hex du corps brut de la requête

Le secret partagé est la valeur de `kermesse.opsMigrationHmacSecret` dans le `.env` de production.

### Exemple bash (GitHub Actions)

Le champ `routePath` dans le payload HMAC est **le chemin de la route ops appelée**, normalisé sans slash de tête ni base URL. Chaque route signe son propre chemin, ce qui empêche le rejeu d'un message signé pour une route sur une autre route.

| Route appelée | `routePath` à utiliser dans le payload |
|---------------|----------------------------------------|
| `POST /ops/migrate` | `ops/migrate` |
| `POST /ops/migrate/status` | `ops/migrate/status` |
| `POST /ops/activate` | `ops/activate` |
| `POST /ops/probe` | `ops/probe` |

Exemple pour `POST /ops/migrate` :

```bash
ROUTE_PATH="ops/migrate"   # adapter selon la route appelée
TIMESTAMP=$(date +%s)
NONCE=$(uuidgen)
BODY='{}' # ou vide
BODY_HASH=$(echo -n "$BODY" | sha256sum | cut -d' ' -f1)
PAYLOAD="${TIMESTAMP}\n${NONCE}\nPOST\n${ROUTE_PATH}\n${BODY_HASH}"
SIGNATURE=$(echo -ne "$PAYLOAD" | openssl dgst -sha256 -hmac "$OPS_MIGRATION_HMAC_SECRET" | cut -d' ' -f2)

curl -X POST "${BASE_URL}/${ROUTE_PATH}" \
  -H "Content-Type: application/json" \
  -H "X-Kermesse-Timestamp: ${TIMESTAMP}" \
  -H "X-Kermesse-Nonce: ${NONCE}" \
  -H "X-Kermesse-Signature: ${SIGNATURE}" \
  -d "$BODY"
```

> Sonde runtime `ops/probe` : la route de mesure `POST /ops/probe` passe le même `OpsAuthFilter` et signe son propre `routePath` (`ops/probe`). Elle reste désactivable via le drapeau `kermesse.opsProbeEnabled` (défaut `false`) ; même avec un HMAC valide, elle répond `403 {"error":"probe_disabled"}` tant que le drapeau n'est pas activé.

## Validations de sécurité

L'`OpsAuthFilter` vérifie dans cet ordre :

1. **Méthode HTTP** — seul `POST` est accepté
2. **Environnement** — si `kermesse.opsMigrationProductionOnly=true`, seul `CI_ENVIRONMENT=production` est accepté
3. **Secret configuré** — rejeté si le secret HMAC est vide
4. **En-têtes présents** — les trois en-têtes doivent être non vides
5. **Fraîcheur du timestamp** — l'écart avec l'horloge serveur doit être ≤ `kermesse.opsMigrationAllowedTimestampSkew` secondes
6. **Nonce unique** — le hash SHA-256 du nonce est stocké en base ; un nonce déjà utilisé est rejeté (anti-rejeu). Les nonces expirent après `kermesse.opsMigrationNonceTTL` secondes
7. **Signature HMAC** — comparaison en temps constant via `hash_equals()`

## Codes de réponse

### Filtre d'authentification

| Code | Corps JSON | Signification |
|------|-----------|---------------|
| 403  | `{"error": "ops_unauthorized"}` | Authentification refusée (toutes les causes) |

Le filtre ne distingue jamais publiquement la cause exacte du refus.

### Endpoint migrate (`POST /ops/migrate`)

| Code | Signification |
|------|---------------|
| 200  | Toutes les migrations ont réussi ou étaient déjà appliquées |
| 500  | Au moins une migration a échoué |

Réponse JSON :

```json
{
  "ok": true,
  "applied": 1,
  "skipped": 0,
  "failed": 0
}
```

Les valeurs `applied`, `skipped` et `failed` sont des compteurs. Aucun SQL brut, stack trace, variable d'environnement ou secret n'est exposé.

### Endpoint migrate/status (`POST /ops/migrate/status`)

Route en **lecture seule** : ne modifie rien, n'acquiert aucun verrou, ne crée pas de tables.
Elle permet de connaître l'état courant des migrations sans déclencher d'application.

| Code | Signification |
|------|---------------|
| 200  | Réponse toujours 200 (l'état « en attente » n'est pas une erreur) |
| 500  | Erreur interne lors de la lecture de `schema_versions` |

Réponse JSON :

```json
{
  "ok": true,
  "pending": ["20260607000000_add_volunteers"],
  "applied": ["20260602161500_initial_schema", "20260605180000_create_stands"],
  "failed":  []
}
```

- `pending` : migrations découvertes mais absentes de `schema_versions`, ou avec statut `pending` (état intermédiaire).
- `applied` : migrations présentes dans `schema_versions` avec statut `success`.
- `failed`  : migrations présentes dans `schema_versions` avec statut `failed`.

> **Note** : une liste `pending` non vide est un état normal, pas une erreur. Le code HTTP reste 200.

## Comportement du runner

1. **Bootstrap** — les tables `schema_versions` et `ops_nonces` sont créées si absentes (`CREATE TABLE IF NOT EXISTS`)
2. **Verrou** — un verrou nommé MariaDB (`GET_LOCK`) est acquis avant toute application. Le nom du verrou est configurable via `kermesse.opsMigrationLockName`. Le verrou est libéré explicitement dans un bloc `finally`
3. **Découverte** — les fichiers `database/migrations_sql/*.sql` sont triés par ordre lexical. La version est le nom de fichier sans extension
4. **Checksum** — le SHA-256 du contenu du fichier est calculé pour chaque migration
5. **Application** — seules les migrations absentes ou précédemment échouées sont appliquées. Une migration déjà réussie dont le checksum a changé est refusée (protection anti-dérive)
6. **Enregistrement** — chaque résultat est enregistré dans `schema_versions` avec version, checksum, statut, date, durée et erreur éventuelle (sanitisée)

## Variables de configuration

| Variable `.env` | Défaut | Description |
|----------------|--------|-------------|
| `kermesse.opsMigrationHmacSecret` | (vide) | Secret HMAC partagé — **obligatoire en production** |
| `kermesse.opsMigrationAllowedTimestampSkew` | 300 | Fenêtre de tolérance timestamp (secondes) |
| `kermesse.opsMigrationNonceTTL` | 600 | Durée de vie des nonces (secondes) |
| `kermesse.opsMigrationProductionOnly` | true | Restreindre à `CI_ENVIRONMENT=production` |
| `kermesse.opsMigrationLockName` | `kermesse_ops_migration_lock` | Nom du verrou MariaDB |

## Intégration GitHub Actions

Après déploiement de l'artefact applicatif, l'étape post-deploy appelle `POST /ops/migrate` avec les en-têtes HMAC. Cet appel vise l'URL publique Ouvaton de l'application ; les migrations s'appliquent via la connexion MariaDB configurée dans le `.env` de production.

Le secret `OPS_MIGRATION_HMAC_SECRET` doit être configuré dans l'environnement GitHub `production`.

En cas de certificat de production temporairement invalide, la variable GitHub `KERMESSE_ALLOW_INSECURE_TLS=true` autorise uniquement l'étape post-déploiement à appeler `/ops/migrate` avec `curl --insecure`. Retirer cette variable dès que le certificat couvre correctement l'hôte public.

**Ne jamais** :
- Logger le secret HMAC, la signature, le nonce brut ou le token
- Exposer le détail d'un refus HMAC dans une réponse HTTP
- Appeler `/ops/migrate` sans HTTPS en production
- Lancer Docker, `php spark migrate`, `mysql`, Composer ou PHPUnit sur le serveur Ouvaton
