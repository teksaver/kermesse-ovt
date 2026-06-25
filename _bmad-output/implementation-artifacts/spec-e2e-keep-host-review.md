---
title: 'Rendre l’environnement E2E conservé accessible au navigateur hôte'
type: 'bugfix'
created: '2026-06-21'
status: 'done'
baseline_commit: 'c3041cee3409bff55f203f93c2a136bc2dbf6249'
context:
  - '{project-root}/project-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `scripts/e2e.sh --keep` conserve actuellement les conteneurs après Playwright, mais l’application continue de générer des URL `http://app/`, résolubles uniquement dans Docker. Le navigateur hôte ne peut donc pas examiner l’état laissé par la suite automatisée.

**Approach:** Exécuter Playwright avec le contrat Docker actuel, puis, uniquement avec `--keep`, recréer le seul conteneur applicatif avec des URL `localhost` et exposer des Magic Links de revue dédiés, non consommés par le `global-setup`.

## Boundaries & Constraints

**Always:** Préserver la base `kermesse_e2e` et son état post-tests ; conserver `http://app` pendant Playwright ; utiliser le port `KERMESSE_HTTP_PORT` dans les URL hôte ; stocker uniquement les hashes des jetons ; propager tout échec de bascule ; afficher les URL de connexion après une bascule réussie.

**Ask First:** Toute solution nécessitant une nouvelle route applicative, une désactivation de sécurité ou une modification du flux Magic Link de production.

**Never:** Réinitialiser ou reseeder la base après Playwright ; réutiliser en les réarmant les jetons consommés par `global-setup` ; exposer l’application sur une interface autre que `127.0.0.1` ; modifier le comportement sans `--keep` ou celui de la CI.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Exécution normale | `scripts/e2e.sh` | Playwright utilise `http://app`, puis les conteneurs sont arrêtés | Le code Playwright est propagé |
| Revue après succès | `scripts/e2e.sh --keep` | Base conservée, application accessible sur `localhost`, liens Owner/Admin/Gestionnaire affichés | Échec de bascule propagé |
| Diagnostic après échec | Playwright échoue avec `--keep` | Même bascule hôte, artefacts et état fautif conservés | Le code final reste non nul |
| Port personnalisé | `KERMESSE_HTTP_PORT=8085` et `--keep` | Liens et redirections utilisent `localhost:8085` | Configuration invalide signalée |

</frozen-after-approval>

## Code Map

- `scripts/e2e.sh` -- orchestration, bascule post-Playwright et sortie utilisateur
- `docker-compose.e2e.yml` -- baseURL paramétrable entre exécution Docker et revue hôte
- `e2e/fixtures/e2e-setup.sql` -- jetons Magic Link réservés à la revue manuelle
- `e2e/global-setup.ts` -- consommateur exclusif des jetons automatisés existants

## Tasks & Acceptance

**Execution:**
- [x] `docker-compose.e2e.yml` -- rendre les URL applicatives surchargeables tout en gardant `http://app/` par défaut.
- [x] `e2e/fixtures/e2e-setup.sql` -- ajouter des jetons de revue hashés distincts pour Owner, Admin et Gestionnaire.
- [x] `scripts/e2e.sh` -- après Playwright avec `--keep`, recréer seulement `app` avec l’URL hôte, attendre sa disponibilité et afficher les liens de connexion ; préserver le code d’échec Playwright.
- [x] `scripts/e2e.sh` et commentaires Compose -- documenter précisément le cycle automatisation puis revue.

**Acceptance Criteria:**
- Given une suite lancée sans `--keep`, when elle se termine, then le comportement actuel et le nettoyage restent inchangés.
- Given une suite lancée avec `--keep`, when Playwright termine, then le navigateur hôte peut suivre les formulaires et redirections sans rencontrer `http://app`.
- Given la bascule de revue, when un lien affiché est ouvert, then le rôle correspondant est authentifié sur l’état exact laissé par Playwright.
- Given un échec Playwright avec `--keep`, when la bascule réussit, then l’environnement reste inspectable et le script retourne un code non nul.

## Spec Change Log

## Design Notes

La même surcharge Compose doit servir aux deux phases : valeur par défaut interne pour Playwright, puis valeur hôte injectée lors du `--force-recreate` de `app`. Des jetons dédiés évitent de réarmer un credential déjà consommé et ne modifient aucune donnée métier après les tests.

## Verification

**Commands:**
- `bash -n scripts/e2e.sh` -- expected: syntaxe Bash valide
- `docker compose -f docker-compose.yml -f docker-compose.e2e.yml config` -- expected: URL interne par défaut
- `KERMESSE_HTTP_PORT=8085 bash scripts/e2e.sh --keep` -- expected: suite exécutée, application et Magic Links accessibles sur `localhost:8085`, état post-tests conservé

## Suggested Review Order

**Orchestration automatisation puis revue**

- Verrouille l’origine Docker pendant Playwright malgré l’environnement appelant.
  [`e2e.sh:28`](../../scripts/e2e.sh#L28)

- Bascule uniquement `app`, valide le port et conserve le code d’échec principal.
  [`e2e.sh:180`](../../scripts/e2e.sh#L180)

**Contrat de configuration**

- Rend la base URL surchargeable sans changer sa valeur Docker par défaut.
  [`docker-compose.e2e.yml:24`](../../docker-compose.e2e.yml#L24)

**Authentification de revue**

- Sépare les jetons humains des sessions consommées par le global setup.
  [`e2e-setup.sql:217`](../../e2e/fixtures/e2e-setup.sql#L217)
