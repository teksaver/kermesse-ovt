# Audit des specs infra Ouvaton du 2026-06-26

## Decision

Les deux specs historiques trouvees par le reviewer ne sont plus des chantiers actifs a traiter avant l'EPIC 7 :

- `_bmad-output/implementation-artifacts/spec-fix-cicd-deployments.md` : statut corrige localement en `done`.
- `_bmad-output/implementation-artifacts/spec-ouvaton-bootstrap-activation.md` : statut corrige localement en `done`.

Ces fichiers vivent dans `_bmad-output/`, dossier ignore par Git. Ce document est donc la trace versionnee a donner au reviewer.

## Mini-audit CI/CD Ouvaton

Le correctif CI/CD Ouvaton est implemente :

- `scripts/deploy-httpdocs.sh` centralise le deploiement du shim `httpdocs/index.php`, de `.htaccess`, du bootstrap temporaire et des assets publics.
- Le script valide les chemins distants avant connexion SFTP et refuse les chemins vides, absolus, avec `..` ou caracteres non autorises.
- La creation des dossiers `shared/writable/{cache,logs,session,uploads}` se fait avec `set cmd:fail-exit yes` et verification par `cd`, sans fallback silencieux.
- `.github/workflows/deploy-ouvaton.yml` appelle le script versionne au lieu de porter la logique httpdocs inline.
- `docs/deployment-ouvaton.md` documente le flux archive, bootstrap autonome, migration ops et preservation du `.env`.

Patch d'audit applique :

- le cleanup du bootstrap distant utilise maintenant `set cmd:fail-exit yes`;
- la suppression reste idempotente via `rm -f`;
- `tests/shell/deploy-ouvaton-workflow.test.sh` verifie explicitement l'absence de `set cmd:fail-exit no`.

## Mini-audit bootstrap activation

Le bootstrap d'activation autonome n'est plus seulement un sujet draft : il est livre.

Evidence :

- `deploy/ops-bootstrap-activate.tpl.php` active une release sans charger CodeIgniter ni `vendor/autoload.php`.
- Le workflow genere un token aleatoire `openssl rand -hex 32`, le masque, injecte le template, deploie `ops-bootstrap-activate.php`, l'appelle en `POST`, puis le supprime du web root.
- Le script autonome verifie le checksum SHA-256, valide les entrees TAR, extrait via `PharData`, valide `app/`, `vendor/`, `public/` et `database/migrations_sql`, bascule `CURRENT_RELEASE`/`current`, puis applique la retention des releases.
- `scripts/deploy-httpdocs.sh` deploie le bootstrap temporaire lorsqu'il est present dans `deploy-staging/public`.

## Verification locale

Commandes executees :

- `bash tests/shell/deploy-httpdocs.test.sh` : OK.
- `bash tests/shell/ops-bootstrap-activate.test.sh` : OK.
- `bash tests/shell/deploy-ouvaton-workflow.test.sh` : OK.
- `vendor/bin/phpunit tests/unit/ReleaseActivationServiceTest.php` : OK, 17 tests, 53 assertions.
- `vendor/bin/phpunit tests/database/OpsActivateEndpointMariaDBTest.php` : OK avec 5 skips, cible MariaDB non active dans cet environnement.
- `vendor/bin/phpunit` : OK, 631 tests, 1591 assertions, 67 skips.
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/deploy-ouvaton.yml")'` : OK.
- `git diff --check` : OK.

## Conclusion

`spec-ouvaton-bootstrap-activation.md` et `spec-fix-cicd-deployments.md` etaient des specs stale, pas des besoins encore ouverts. Le bootstrap activation et le nettoyage CI/CD doivent etre consideres comme `done`, avec ce document comme trace versionnee pour review.
