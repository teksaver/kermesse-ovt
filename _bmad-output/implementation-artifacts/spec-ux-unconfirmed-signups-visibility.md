---
title: 'Visibilité des inscriptions — résumé bénévole + auto-confirmation'
type: 'ux-story'
created: '2026-06-26'
status: 'ready'
context:
  - '{project-root}/project-context.md'
  - '{project-root}/docs/ux-cleaning-pre-epic7.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Deux problèmes :**

1. Sur la page d'accueil, la carte kermesse d'un bénévole n'affiche aucun résumé de ses inscriptions alors que la page indique désormais les inscriptions en attente.
2. Sur la page de gestion des inscrits (vue admin), le badge textuel "Confirmé / À confirmer" ne distingue pas visuellement les deux états et n'explique pas pourquoi une inscription est en attente.

**Décisions de conception :**

- **Auto-confirmation au login :** dès qu'un bénévole se connecte via magic link, toutes ses inscriptions `unconfirmed` créées avant ce login sont automatiquement acceptées (`accepted_at` stampé dans `MagicLinkController`, après `last_login_at`). La condition est : `created_at < NOW()` — forcément vrai juste après le login.
- **Auto-inscription :** `created_by = user_id` → statut `active` dès la création, pas concerné.
- **Vue admin — deux emojis, quatre tooltips, sans badge texte :**
  - ✅ `active` → "Inscription authentifiée" (auto-inscription connectée)
  - ✅ `certified` → "Inscription vue par l'utilisateur" (confirmée au login)
  - ⏳ `unconfirmed` + `created_by IS NOT NULL` → "Inscription par un tiers"
  - ⏳ `unconfirmed` + `created_by IS NULL` → "Inscription en mode visiteur"
- **Page d'accueil bénévole :** résumé "Vous êtes inscrit(e) à X créneau(x)" avec les deux actions.

## Boundaries & Constraints

**Always :**
- L'auto-confirmation passe par `SlotSignupService::autoAcceptUnconfirmedAfterLogin(int $userId)`, appelé depuis `MagicLinkController` — jamais depuis une vue ou un modèle.
- La méthode doit être idempotente (`WHERE accepted_at IS NULL` garantit le no-op).
- Le résumé bénévole sur la page d'accueil utilise uniquement les créneaux `active` + `certified` (pas les annulés ni les historiques).
- La requête de comptage est agrégée (une seule requête pour tous les kermesses).

**Never :**
- Ne pas modifier `computeStatus()` ni `ACTIVE_CONDITION`.
- Ne pas toucher aux routes, au schéma SQL, ni aux invariants métier.
- Ne pas afficher le résumé bénévole pour les rôles owner/admin/gestionnaire.
- Ne pas exposer `last_login_at` brut dans les vues.
- Ne pas introduire de badge texte pour le statut dans la vue admin — uniquement les deux emojis.

## États et transitions

```
Auto-inscription connectée (created_by = user_id)
  → statut 'active'
  → Admin voit ✅ "Inscription authentifiée"

Signup créé par un tiers identifié (created_by = admin/gestionnaire, accepted_at IS NULL)
  → Admin voit ⏳ "Inscription par un tiers"

Signup créé en mode visiteur (created_by IS NULL, accepted_at IS NULL)
  → Admin voit ⏳ "Inscription en mode visiteur"

Bénévole se connecte via magic link
  → MagicLinkController stampe last_login_at = NOW()
  → SlotSignupService::autoAcceptUnconfirmedAfterLogin($userId)
     UPDATE slot_signups SET accepted_at = NOW()
     WHERE user_id = $userId AND accepted_at IS NULL AND created_by != $userId
  → computeStatus() retourne 'certified'
  → Admin voit ✅ "Inscription vue par l'utilisateur"
```

## I/O & Edge-Case Matrix

| Scénario | État | Tooltip |
|----------|------|---------|
| Auto-inscription connectée | `active` | ✅ "Inscription authentifiée" |
| Tiers-signup + bénévole connecté après | `certified` | ✅ "Inscription vue par l'utilisateur" |
| Tiers-signup, bénévole pas encore connecté | `unconfirmed`, `created_by NOT NULL` | ⏳ "Inscription par un tiers" |
| Visiteur-signup, bénévole pas encore connecté | `unconfirmed`, `created_by IS NULL` | ⏳ "Inscription en mode visiteur" |
| Signup créé après dernier login | `unconfirmed` | ⏳ selon `created_by` |
| Double login | `accepted_at` déjà non-null → UPDATE no-op | ✅ inchangé |
| Bénévole sans inscription | Aucun signup → autoAccept no-op | — |
| **Page d'accueil** | | |
| Bénévole, 2 créneaux actifs | active/certified | "Vous êtes inscrit(e) à 2 créneaux." |
| Bénévole, 0 créneau | aucun | "Vous n'avez pas encore d'inscription." |
| Rôle admin sur la carte | owner/admin/gest. | Résumé absent |

</frozen-after-approval>

## Tasks

### A — Auto-confirmation au login

- [ ] **A1 — `SlotSignupService::autoAcceptUnconfirmedAfterLogin(int $userId): void`**
  - Fichier : `app/Services/SlotSignupService.php`
  - Requête :
    ```sql
    UPDATE slot_signups
    SET accepted_at = NOW()
    WHERE user_id = ?
      AND accepted_at IS NULL
      AND (created_by IS NULL OR created_by != ?)
    ```
  - Paramètres : `[$userId, $userId]`
  - Idempotent par construction (`WHERE accepted_at IS NULL`)

- [ ] **A2 — Appel dans `MagicLinkController` après login réussi**
  - Fichier : `app/Controllers/Auth/MagicLinkController.php`
  - Localiser l'endroit où `last_login_at` est stampé sur l'utilisateur
  - Ajouter immédiatement après :
    ```php
    (new SlotSignupService(model(UserModel::class), model(SlotSignupModel::class)))
        ->autoAcceptUnconfirmedAfterLogin($userId);
    ```

### B — Résumé bénévole sur la page d'accueil

- [ ] **B1 — `SlotSignupModel::countActiveByUserAndKermesses(int $userId, array $kermesseIds): array<int,int>`**
  - Fichier : `app/Models/SlotSignupModel.php`
  - Retourne `[kermesse_id => count]` — une requête agrégée, pas de N+1
  - Compte uniquement les signups `active` + `certified` (utiliser `ACTIVE_CONDITION` + filtre statuts)
  - `$kermesseIds` vide → retourne `[]` sans requête

- [ ] **B2 — `HomeController::index()` : passer les compteurs**
  - Fichier : `app/Controllers/Home/HomeController.php`
  - Après la boucle `foreach ($kermesses)` : collecter les IDs des kermesses où `$k['role'] === 'benevole'`
  - Appeler `SlotSignupModel::countActiveByUserAndKermesses($userId, $benevoleIds)`
  - Dans la boucle, ajouter `$k['active_signup_count'] = $counts[$k['id']] ?? null` (null = pas bénévole)

- [ ] **B3 — Vue page d'accueil**
  - Fichier : `app/Views/home/connected.php`
  - Dans `.kermesse-card`, juste avant `.kermesse-card__actions`, conditionnel sur `$k['role'] === 'benevole'` :
    ```php
    <?php if ($k['role'] === 'benevole'): ?>
    <p class="kermesse-card__signup-summary">
        <?php if (($k['active_signup_count'] ?? 0) > 0): ?>
            Vous êtes inscrit(e) à <?= (int)$k['active_signup_count'] ?> créneau<?= $k['active_signup_count'] > 1 ? 'x' : '' ?>.
            Cliquez sur <strong>Mes inscriptions</strong> pour modifier ou <strong>Nouvelle inscription</strong> pour ajouter.
        <?php else: ?>
            Vous n'avez pas encore d'inscription pour cette kermesse.
        <?php endif; ?>
    </p>
    <?php endif; ?>
    ```

- [ ] **B4 — CSS `.kermesse-card__signup-summary`**
  - Fichier : `public/assets/css/app.css`
  - ```css
    .kermesse-card__signup-summary {
        font-size: 13px;
        color: var(--color-text-muted, #6c757d);
        margin: 6px 0 4px;
    }
    ```

### C — Vue admin : ✅ / ⏳ à la place des badges texte

- [ ] **C1 — `KermesseAdminController` : remplacer `status_badge_label/class` par `status_icon`**
  - Fichier : `app/Controllers/Kermesse/Dashboard/KermesseAdminController.php`
  - Dans `buildParticipantStandsData()`, remplacer la construction du `$statusBadge` par :
    ```php
    if ($computedStatus === 'active') {
        $statusIcon = ['emoji' => '✅', 'label' => 'Inscription authentifiée'];
    } elseif ($computedStatus === 'certified') {
        $statusIcon = ['emoji' => '✅', 'label' => 'Inscription vue par l\'utilisateur'];
    } elseif ($p['created_by'] !== null) {
        $statusIcon = ['emoji' => '⏳', 'label' => 'Inscription par un tiers'];
    } else {
        $statusIcon = ['emoji' => '⏳', 'label' => 'Inscription en mode visiteur'];
    }
    ```
  - Dans le tableau participant, remplacer `status_badge_label`/`status_badge_class` par :
    ```php
    'status_icon'  => $statusIcon['emoji'],
    'status_label' => $statusIcon['label'],
    ```

- [ ] **C2 — Vue gestion des inscrits : rendu de l'icône**
  - Fichier : `app/Views/kermesse/dashboard.php`
  - Remplacer le `<span class="badge ...">` du statut par :
    ```php
    <span role="img"
          title="<?= esc($vol['status_label']) ?>"
          aria-label="Statut : <?= esc($vol['status_label']) ?>"
          style="font-size:18px; line-height:1; cursor:default;"
    ><?= $vol['status_icon'] ?></span>
    ```
  - Supprimer les classes CSS `badge--signup-confirmed` et `badge--signup-pending` de la vue (garder si utilisées ailleurs)

## Verification

- [ ] Admin-signup + bénévole se connecte → ⏳ devient ✅ sans rechargement manuel
- [ ] Auto-inscription → ✅ immédiat
- [ ] Admin-signup + bénévole jamais connecté → ⏳ avec tooltip lisible
- [ ] Admin-signup + login antérieur au signup → ⏳ (pas encore connecté depuis)
- [ ] Homepage bénévole 2 créneaux → "inscrit(e) à 2 créneaux"
- [ ] Homepage bénévole 0 créneau → "pas encore d'inscription"
- [ ] Homepage rôle admin → bloc absent
- [ ] PHPStan niveau 7 : 0 erreurs
- [ ] Suite SQLite : verte
