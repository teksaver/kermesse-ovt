# Runner de migrations — Contrat ops

## Présentation

Le runner de migrations applique les fichiers SQL situés dans `database/migrations_sql/` en ordre lexicographique via une route ops sécurisée : `POST /ops/migrate`.

Ce mécanisme remplace `php spark migrate` car le serveur Ouvaton ne dispose pas d'accès CLI.

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
- `routePath` : `ops/migrate` (toujours stable, pas de base URL)
- `sha256(rawBody)` : SHA-256 hex du corps brut de la requête

Le secret partagé est la valeur de `kermesse.opsMigrationHmacSecret` dans le `.env` de production.

### Exemple bash (GitHub Actions)

```bash
TIMESTAMP=$(date +%s)
NONCE=$(uuidgen)
BODY='{}' # ou vide
BODY_HASH=$(echo -n "$BODY" | sha256sum | cut -d' ' -f1)
PAYLOAD="${TIMESTAMP}\n${NONCE}\nPOST\nops/migrate\n${BODY_HASH}"
SIGNATURE=$(echo -ne "$PAYLOAD" | openssl dgst -sha256 -hmac "$OPS_MIGRATION_HMAC_SECRET" | cut -d' ' -f2)

curl -X POST "${BASE_URL}/ops/migrate" \
  -H "Content-Type: application/json" \
  -H "X-Kermesse-Timestamp: ${TIMESTAMP}" \
  -H "X-Kermesse-Nonce: ${NONCE}" \
  -H "X-Kermesse-Signature: ${SIGNATURE}" \
  -d "$BODY"
```

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

### Endpoint migrate

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

Après déploiement de l'artefact applicatif, ajouter une étape post-deploy qui appelle `POST /ops/migrate` avec les en-têtes HMAC.

Le secret `OPS_MIGRATION_HMAC_SECRET` doit être configuré dans l'environnement GitHub `production`.

**Ne jamais** :
- Logger le secret HMAC, la signature, le nonce brut ou le token
- Exposer le détail d'un refus HMAC dans une réponse HTTP
- Appeler `/ops/migrate` sans HTTPS en production
