---
title: "Matrice de couverture des tests — Kermesse"
version: "1.1"
created: "2026-06-18"
updated: "2026-06-19"
story: "6.2"
scope: "Épics 1 à 5 (MVP livré)"
baseline_commit: "743ce563f95c6561cafbf5ca45460b7e008855df"
reproduced_on: "2026-06-19 — uv run php vendor/bin/phpunit (suite complète SQLite + groupe mariadb)"
---

# Matrice de couverture des tests — Kermesse

## 1. Méthodologie

### 1.1 Axes de la matrice

Chaque parcours est décrit selon la formule :

> **Surface × État × Rôle × Identité × Action → Résultat attendu**

| Axe | Valeurs possibles |
|-----|------------------|
| **Surface** | `public_page`, `signup_form`, `auth`, `connected_home`, `dashboard_modif`, `dashboard_inscrits`, `dashboard_equipe`, `dashboard_participations`, `profile`, `ops` |
| **État kermesse** | `preparation` · `open` · `closed` |
| **Rôle** | `Anonyme` · `Bénévole` · `Gestionnaire` · `Admin` · `Owner` |
| **Identité** | `session_active` · `session_absente` · `orphelin` (inscription sans `user_id`) |
| **Action** | verbe métier (créer, soumettre, annuler, inviter…) |
| **Résultat** | état final attendu (succès, refus, redirection, erreur métier) |

### 1.2 Niveaux de priorité

| Niveau | Définition |
|--------|------------|
| **P0** | Sécurité, autorisation, perte ou corruption de données — **aucune lacune tolérée avant production** |
| **P1** | Parcours métier indispensable ou régression visible par l'utilisateur |
| **P2** | Variante secondaire, cosmétique, ou cas marginal |

### 1.3 Statuts de couverture

| Statut | Signification |
|--------|--------------|
| `✅ couvert` | Test PHPUnit (feature/unit/database) existant et passant |
| `⚠️ partiel` | Couvert en isolation (SQLite/unit) mais pas en intégration MariaDB ou sans E2E |
| `❌ manquant` | Aucun test automatisé — lacune à combler |
| `🔵 manuel justifié` | Test automatisé non rentable ; validation manuelle documentée |
| `🔘 non applicable` | Le parcours ne peut pas se produire dans ce contexte |

### 1.4 Répartition des tests actuels

| Catégorie | Fichiers | Tests |
|-----------|----------|-------|
| Feature (SQLite) | 28 fichiers | ~323 |
| Unit (SQLite/mocks) | 19 fichiers | ~229 |
| Database/MariaDB | 7 fichiers | ~26 |
| **Total** | **54 fichiers** | **~578** |

> **Dette systémique documentée** : la majorité des fixtures de test utilisent des `CREATE TABLE` SQL bruts copiés manuellement plutôt que les migrations officielles ou le `Fabricator` CI4. Ce patron génère un risque de désynchronisation silencieuse entre les tests et le schéma réel de production. Voir `deferred-work.md` et la Story **6.6** pour la remédiation.

---

## 2. Parcours FR-1 : Page d'accueil (non connecté)

> **Périmètre** : `public_page` · tous états · `Anonyme` · `session_absente`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 1.1 | Afficher l'accueil → boutons "Créer" et "Connexion" visibles | P1 | ✅ couvert | `HomePublicPageTest` |
| 1.2 | Accéder à l'accueil connecté sans session → redirection vers accueil public | P0 | ✅ couvert | `ConnectedHomeTest::testUnauthenticated*` |
| 1.3 | Accéder à l'accueil avec session active → accueil connecté affiché | P1 | ✅ couvert | `ConnectedHomeTest` |

---

## 3. Parcours FR-2 : Connexion par Magic Link

> **Périmètre** : `auth` · tous états · `Anonyme` / `Bénévole` · `session_absente`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 2.1 | Demander un Magic Link avec un email valide → email envoyé, token créé hashé | P0 | ✅ couvert | `MagicLinkRequestTest` (12 tests), `TokenServiceTest` (49 tests) |
| 2.2 | Valider un token valide → session ouverte, redirection accueil connecté | P0 | ✅ couvert | `MagicLinkVerifyTest::testValidTokenEstablishesSession` |
| 2.3 | Email inconnu → `findOrCreateByEmail()` crée un compte lors de la validation | P0 | ✅ couvert | `MagicLinkVerifyTest::testValidTokenCreatesUserWhenUnknown` |
| 2.4 | Email connu → compte réutilisé sans duplication | P0 | ✅ couvert | `MagicLinkVerifyTest::testValidTokenReusesExistingUser` |
| 2.5 | Token expiré → message neutre sans révéler l'existence du compte | P0 | ✅ couvert | `MagicLinkVerifyTest::testExpiredTokenShowsNeutralError` |
| 2.6 | Token déjà utilisé → message neutre | P0 | ✅ couvert | `MagicLinkVerifyTest::testUsedTokenShowsNeutralError` |
| 2.7 | Token révoqué → message neutre | P0 | ✅ couvert | `MagicLinkVerifyTest::testRevokedTokenShowsNeutralError` |
| 2.8 | Token invalide/inconnu → message neutre | P0 | ✅ couvert | `MagicLinkVerifyTest::testUnknownTokenShowsNeutralError` |
| 2.9 | Validation avec `kermesse_intent` → redirection vers dashboard kermesse | P1 | ✅ couvert | `MagicLinkVerifyTest::testValidTokenWithKermesseIntentRedirectsToDashboard` |
| 2.10 | Token réutilisé après usage → refusé | P0 | ✅ couvert | `MagicLinkVerifyTest::testUsedTokenCannotBeReusedForLogin` |
| 2.11 | Token stocké haché en base (jamais en clair) | P0 | ✅ couvert | `TokenServiceTest::testRawTokenIsNeverStoredInInsertData` |
| 2.12 | Flux complet Magic Link en MariaDB réel | P0 | ❌ manquant | → Story **6.6** (validation schéma FK tokens/users) |
| 2.13 | Déconnexion POST `/auth/logout` avec session active → session détruite, redirection accueil public (CSRF obligatoire) | P0 | ✅ couvert | `ConnectedHomeTest::testLogoutRedirectsToRoot`, `testLogoutWithoutSessionStillRedirectsToRoot` |
| 2.14 | **[Session expirée — GET]** Accès à une route authentifiée avec session expirée → redirection vers `/auth/request?redirect=<url>`, puis retour sur la page après connexion | P1 | ✅ couvert | `SessionExpirationTest::testExpiredSessionOnGetRouteRedirectsToLoginWithRedirectParam`, `testExpiredSessionReturnUrlIsPreservedAfterLogin` |
| 2.15 | **[Session expirée — POST]** Soumission d'un formulaire POST (ex. inviter un admin, accepter une inscription) avec session expirée → CodeIgniter lève `SecurityException` (CSRF hash perdu avec la session) avant que le filtre d'auth s'exécute → gestionnaire d'exceptions intercepte et redirige vers `/auth/request` avec flash, sans page d'erreur 403 | P1 | ✅ couvert | `SessionExpirationTest::testExpiredSessionOnPostRouteShowsFlashAndRedirectsToLogin`, `testExpiredSessionPostDoesNotReturn403OrPhpError` |
| 2.16 | **[Open redirect]** Paramètre `redirect` après reconnexion validé same-origin → URL externe rejetée | P0 | ✅ couvert | `SessionExpirationTest::testOpenRedirectIsRejectedForExternalUrl`, `testOpenRedirectIsRejectedForAbsoluteExternalUrl` |

---

## 4. Parcours FR-3 : Accueil connecté

> **Périmètre** : `connected_home` · tous états · tous rôles · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 3.1 | Afficher les kermesses de l'utilisateur avec son rôle | P1 | ✅ couvert | `ConnectedHomeTest` (12 tests) |
| 3.2 | Kermesse `preparation` → badge état visible | P1 | ✅ couvert | `ConnectedHomeTest` |
| 3.3 | Bouton "Créer une nouvelle kermesse" visible | P1 | ✅ couvert | `ConnectedHomeTest` |
| 3.4 | Bouton "Quitter" absent pour Owner | P1 | ✅ couvert | `LeaveKermesseTest::testLeaveButtonNotShownForOwnerOnConnectedHome` |
| 3.5 | Bouton "Quitter" absent si inscription active | P1 | ✅ couvert | `LeaveKermesseTest::testLeaveButtonNotShownOnConnectedHomeWhenActiveSignup` |

---

## 5. Parcours FR-4 : Créer une kermesse

> **Périmètre** : `public_page` / `connected_home` · tous états · `Anonyme` / connecté · `session_absente` / `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 4.1 | Visiteur crée une kermesse → email Owner envoyé, token Owner créé | P0 | ✅ couvert | `CreateKermesseTest` (25 tests) |
| 4.2 | Owner valide son email → rôle Owner persisté, session ouverte | P0 | ✅ couvert | `CreateKermesseTest` |
| 4.3 | Utilisateur connecté crée une kermesse (formulaire allégé) | P1 | ✅ couvert | `CreateKermesseTest` |
| 4.4 | Doublon d'email lors de la création → réutilise le compte existant | P0 | ✅ couvert | `CreateKermesseTest` |
| 4.5 | Token Owner expiré → renvoi possible | P1 | ✅ couvert | `CreateKermesseTest` |

---

## 6. Parcours FR-5 : Gérer stands et créneaux

> **Périmètre** : `dashboard_modif` · `preparation` / `open` / `closed` · `Admin` / `Owner` · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 5.1 | Ajouter un stand → visible dans la liste | P1 | ✅ couvert | `ManageStandsTest` (24 tests) |
| 5.2 | Modifier un stand → changements persistés | P1 | ✅ couvert | `ManageStandsTest` |
| 5.3 | Supprimer stand sans inscriptions → supprimé | P1 | ✅ couvert | `ManageStandsDeleteTest` (11 tests) |
| 5.4 | Supprimer stand avec inscriptions actives → confirmation `SUPPRIMER` requise | P0 | ✅ couvert | `ManageStandsDeleteTest`, `StandDeletionServiceTest` |
| 5.5 | Bénévole tente de supprimer un stand → refus (403 / filtre rôle) | P0 | ⚠️ partiel | `ManageStandsTest` (SQLite) → Story **6.5** |
| 5.6 | Ajouter un créneau sur kermesse `preparation` → OK | P1 | ✅ couvert | `ManageSlotsTest` (25 tests) |
| 5.7 | **[Story 6.1 — non-régression]** Owner / Admin ajoutent un créneau sur kermesse `open` → création réussit (schéma MariaDB ENUM + FK validés) | P1 | ✅ couvert | `ManageSlotsMariaDBTest::testCreateSlotOnOpenKermesseWithRealSchema` |
| 5.8 | Owner / Admin ajoutent un créneau sur kermesse `open` (SQLite) → création réussit | P1 | ✅ couvert | `ManageSlotsTest::testOwnerCanAddSlotOnOpenKermesse`, `testAdminCanAddSlotOnOpenKermesse` |
| 5.9 | Modifier un créneau (capacité, horaire) → persisté | P1 | ✅ couvert | `ManageSlotsTest` |
| 5.10 | Réduire la capacité en dessous des inscrits actifs → refusé | P0 | ❌ manquant | → Story **6.5** |
| 5.11 | Créneaux qui se chevauchent (slots overlap) → non prévenu côté admin | P2 | ❌ manquant | → Story **6.5** (issu de `deferred-work.md`) |
| 5.12 | Dupliquer un stand → copie créée sans inscriptions | P2 | ✅ couvert | `StandDuplicationServiceTest` (5 tests) |
| 5.13 | Gestionnaire tente d'ajouter un stand → refus | P0 | ⚠️ partiel | `ManageStandsTest` (SQLite) → Story **6.5** |
| 5.14 | Gestionnaire / Bénévole / non-authentifié tente d'ajouter un créneau sur kermesse `open` par POST direct → refus rôle, aucune écriture | P0 | ⚠️ partiel | `ManageSlotsTest::testGestionnaireCannotAddSlotOnOpenKermesse`, `testBenevoleCannotAddSlotOnOpenKermesse`, `testUnauthenticatedCannotAddSlotOnOpenKermesse` (SQLite) → Story **6.5** MariaDB |
| 5.15 | Stand appartenant à une autre kermesse dans le POST → 404, aucune écriture inter-kermesse | P0 | ✅ couvert | `ManageSlotsTest` (Story 6.1 AC6) |
| 5.16 | Ajouter / modifier un créneau sur kermesse `closed` → refusé | P0 | ❌ manquant | → Story **6.5** |

---

## 7. Parcours FR-6 : Ouvrir et fermer les inscriptions

> **Périmètre** : `dashboard_modif` · `preparation` / `open` / `closed` · `Admin` / `Owner` · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 6.1 | Ouvrir les inscriptions depuis `preparation` → état `open` | P1 | ✅ couvert | `ManageKermesseLifecycleTest` (13 tests) |
| 6.2 | Fermer les inscriptions depuis `open` → état `closed` | P1 | ✅ couvert | `ManageKermesseLifecycleTest` |
| 6.3 | Transition `preparation` → `open` persiste en MariaDB | P1 | ⚠️ partiel | Indirectement via `ManageSlotsMariaDBTest` ; pas de test dédié lifecycle → Story **6.6** |
| 6.4 | Bénévole tente d'ouvrir les inscriptions → refus | P0 | ⚠️ partiel | `ManageKermesseLifecycleTest` (SQLite) → Story **6.5** |

---

## 8. Parcours FR-7 : Page bénévole publique

> **Périmètre** : `public_page` · tous états · `Anonyme` / connecté · `session_absente` / `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 7.1 | État `preparation` → message "pas encore ouvert", pas de stands | P1 | ✅ couvert | `PublicVolunteerPageTest::testPreparationShowsBaseInfoAndNotOpenMessageWithoutPlanning` |
| 7.2 | État `open` → stands, créneaux, places restantes affichés | P1 | ✅ couvert | `PublicVolunteerPageTest::testOpenShowsStandsFirstWithSlotTimeRemainingAndCapacity` |
| 7.3 | Créneau complet → désactivé avec label "Complet" | P1 | ✅ couvert | `PublicVolunteerPageTest::testFullSlotStaysVisibleAndDisabledWithCompletLabel` |
| 7.4 | État `closed` → message "inscriptions fermées", pas de formulaire | P1 | ✅ couvert | `PublicVolunteerPageTest::testClosedShowsClosedMessageWithoutPlanningOrSignup` |
| 7.5 | Stand/créneau inactif → non affiché | P1 | ✅ couvert | `PublicVolunteerPageTest::testOpenDoesNotExposeInactiveStandsOrInactiveSlots` |
| 7.6 | **[PII Privacy]** La page publique ne contient aucun email, nom, Magic Link | P0 | ✅ couvert | `PublicVolunteerPageTest::testPublicPageDoesNotLeakUserOrAdminData` |
| 7.7 | Inscription annulée (soft-delete) non comptée dans les places restantes | P0 | ✅ couvert | `PublicVolunteerPageTest::testOpenRemainingIgnoresSoftDeletedSignups` |
| 7.8 | Slug inconnu → 404 neutre | P1 | ✅ couvert | `PublicVolunteerPageTest::testUnknownSlugReturnsNeutral404` |
| 7.9 | Encart "Déjà inscrit ? Connectez-vous" visible pour Anonyme / masqué pour utilisateur connecté | P2 | ✅ couvert | `PublicVolunteerPageTest::testOpenWithNoSlotsShowsLoginAffordanceForAnonymousUser` (preuve positive), `testLoginAffordanceHiddenWhenAlreadyAuthenticated` (preuve négative) |
| 7.10 | **[E2E]** Places restantes mises à jour après inscription sans rechargement manuel | P1 | ❌ manquant | → Story **6.4** (Playwright) |

---

## 9. Parcours FR-8 : Créer une inscription (publique)

> **Périmètre** : `signup_form` · `open` · `Anonyme` / `Bénévole` · `session_absente` / `session_active`

| # | Scénario × Action → Résultat | P | Couverture | Référence |
|---|------------------------------|---|------------|-----------|
| 8.1 | Visiteur non connecté, email inconnu → inscription créée (`user_id=NULL`, `created_by=NULL`) | P0 | ✅ couvert | `SignupServiceTest::testNewEmailDoesNotCreateUserAndReturnsSuccessWithNullUserId` |
| 8.2 | Visiteur non connecté, email connu → `user_id` renseigné, `created_by=NULL` | P0 | ✅ couvert | `SignupServiceTest::testExistingEmailReusesUserWithoutCreating` |
| 8.3 | Utilisateur connecté → `user_id` + `created_by` + `accepted_at` renseignés | P0 | ✅ couvert | `SignupServiceTest::testSignupInsertedWithCorrectUserIdAndSlotId` |
| 8.4 | Email non créé en BDD lors d'une inscription publique (email inconnu) | P0 | ✅ couvert | `PublicSignupFormTest::testValidSubmitDoesNotCreateUserRowInDb` |
| 8.5 | Kermesse `preparation` → formulaire retourne 404 neutre | P1 | ✅ couvert | `PublicSignupFormTest::testNonOpenKermesseReturnsNeutral404OnFormUrl` |
| 8.6 | Kermesse `closed` → formulaire retourne 404 neutre | P1 | ✅ couvert | `PublicSignupFormTest` (testNonOpenKermesse) |
| 8.7 | Formulaire invalide → valeurs préservées, erreurs affichées | P1 | ✅ couvert | `PublicSignupFormTest::testPostMissingRequiredFieldsPreservesValuesAndShowsErrors` |
| 8.8 | Email normalisé (majuscules → minuscules) | P1 | ✅ couvert | `SignupServiceTest::testEmailIsNormalizedBeforeLookup` |
| 8.9 | Succès → redirection vers page de confirmation | P1 | ✅ couvert | `PublicSignupFormTest::testSuccessfulSubmitRedirectsToConfirmationPage` |
| 8.10 | Page de confirmation sans flash → redirection vers kermesse | P1 | ✅ couvert | `PublicSignupFormTest::testConfirmationPageWithoutFlashRedirectsToKermesse` |
| 8.11 | Confirmation email envoyé (event `email_events` enregistré) | P1 | ✅ couvert | `PublicSignupFormTest::testValidSubmitRecordsSignupConfirmationEmailEvent` |
| 8.12 | **[getStatus() — machine à états]** Statut calculé : `unconfirmed` si pas de `accepted_at` ni `rejected_at` | P0 | ⚠️ partiel | `SignupModelTest`, `ConfirmSignupTest` ; pas de test MariaDB dédié → Story **6.6** |
| 8.13 | **[getStatus()]** Statut `certified` si `accepted_at != NULL` | P0 | ✅ couvert | `ConfirmSignupTest::testBenevoleAcceptsUnconfirmedSignupSetsAcceptedAt` |
| 8.14 | **[getStatus()]** Statut `refused` si `rejected_at != NULL` | P0 | ✅ couvert | `ConfirmSignupTest::testBenevoleRejectsUnconfirmedSignupFreesSlot` |
| 8.15 | **[getStatus()]** Statut `cancelled` si `canceled_by == user_id` | P0 | ✅ couvert | `CancelSignupTest` |
| 8.16 | **[getStatus()]** Statut `removed` si `canceled_by != user_id` | P0 | ✅ couvert | `AdminSignupActionsTest::testAdminCancelSetsStatusToRemoved` |
| 8.17 | **[E2E]** Flux complet inscription → confirmation email → lien dans email | P1 | ❌ manquant | → Story **6.4** (Playwright) |

---

## 10. Parcours FR-9 : Éviter les conflits de planning

> **Périmètre** : `signup_form` · `open` · `Anonyme` / `Bénévole` · `session_absente` / `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 9.1 | Inscription double sur même créneau → `duplicate_signup` | P0 | ✅ couvert | `SignupServiceTest::testSignupRefusedWhenDuplicateExists` |
| 9.2 | Inscriptions chevauchantes → `overlap_conflict` | P0 | ✅ couvert | `SignupServiceTest::testSignupRefusedWhenOverlapConflict` |
| 9.3 | Contexte du chevauchement transmis à la vue | P1 | ✅ couvert | `SignupServiceTest::testOverlapContextCarriesConflictingTimes` |
| 9.4 | Capacité vérifiée avant doublon (ordre des guards) | P0 | ✅ couvert | `SignupServiceTest::testCapacityCheckedBeforeDuplicate` |
| 9.5 | Créneau complet → `slot_full` | P0 | ✅ couvert | `SignupServiceTest::testSignupRefusedWhenSlotFull` |
| 9.6 | **[Race condition]** Deux inscriptions simultanées sur dernier créneau → une seule acceptée | P0 | ❌ manquant | Test concurrent MariaDB absent → Story **6.6** |
| 9.7 | Stale availability : créneau plein entre affichage et submit → `slot_full`, valeurs préservées | P0 | ⚠️ partiel | `SignupServiceTest` (unit) ; pas de test feature E2E → Story **6.4** |

---

## 11. Parcours FR-10 : Accès au tableau de bord

> **Périmètre** : `dashboard_*` · tous états · tous rôles connectés · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 10.1 | Owner voit 4 onglets (Modif, Inscrits, Équipe, Participations) | P1 | ✅ couvert | `DashboardRoleSectionsTest`, `DashboardTabNavigationTest` |
| 10.2 | Admin voit 4 onglets | P1 | ✅ couvert | `DashboardRoleSectionsTest` |
| 10.3 | Gestionnaire voit 2 onglets (Inscrits, Participations) | P1 | ✅ couvert | `DashboardRoleSectionsTest` |
| 10.4 | Bénévole voit 1 onglet (Participations) | P1 | ✅ couvert | `DashboardRoleSectionsTest` |
| 10.5 | Bénévole ne voit pas les données PII des autres bénévoles | P0 | ✅ couvert | `ManageParticipantsTest::testBenevoleSeesNeitherSectionNorOtherVolunteersPII` |
| 10.6 | Utilisateur sans rôle tente d'accéder au dashboard → refus | P0 | ⚠️ partiel | `DashboardRoleSectionsTest` (SQLite) → Story **6.5** |
| 10.7 | **[E2E]** Navigation entre onglets via JavaScript | P1 | ✅ couvert | `e2e/tests/participations.spec.ts`, `team.spec.ts`, `add-slot.spec.ts` (Story 6.3) |
| 10.8 | Dernier accès par kermesse tracé (`last_accessed_at`) | P2 | ✅ couvert | `DashboardTabNavigationTest` |

---

## 12. Parcours FR-11 : Annuler une inscription (Bénévole)

> **Périmètre** : `dashboard_participations` · `open` / `closed` · `Bénévole` / Admin · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 11.1 | Bénévole annule son inscription active → place libérée | P1 | ✅ couvert | `CancelSignupTest` (6 tests) |
| 11.2 | Annulation tracée avec `canceled_at` et `canceled_by = user_id` | P0 | ✅ couvert | `CancelSignupTest` |
| 11.3 | Inscription annulée non comptée comme active | P0 | ✅ couvert | `ManageParticipantsTest::testCancelledSignupVolunteerIsNotCountedButAppearsInHistory` |
| 11.4 | Kermesse `closed` → bénévole ne peut pas annuler (guard serveur) | P0 | ⚠️ partiel | `SignupServiceTest` (unit) ; pas de test feature → Story **6.5** |
| 11.5 | Bénévole tente d'annuler l'inscription d'un autre → refus | P0 | ✅ couvert | `ConfirmSignupTest::testAnotherBenevoleCannotRejectSomeoneElsesSignup` |

---

## 13. Parcours FR-12 : Voir les inscrits (Admin/Gestionnaire)

> **Périmètre** : `dashboard_inscrits` · tous états · `Admin` / `Gestionnaire` / `Owner` · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 12.1 | Admin voit les inscrits avec identité et contact | P1 | ✅ couvert | `ManageParticipantsTest::testAdminSeesParticipantsWithIdentityAndContact` |
| 12.2 | Gestionnaire voit les inscrits | P1 | ✅ couvert | `ManageParticipantsTest::testGestionnaireSeesParticipants` |
| 12.3 | Comptages (occupés / restants) exacts | P0 | ✅ couvert | `ManageParticipantsTest::testOccupiedAndRemainingCountsMatchActiveSignups` |
| 12.4 | Inscriptions orphelines (`user_id=NULL`) visibles via LEFT JOIN | P0 | ⚠️ partiel | `ManageParticipantsTest` (SQLite) ; requête LEFT JOIN non vérifiée MariaDB → Story **6.6** |
| 12.5 | Section historique : annulé (bénévole) vs supprimé (admin) distincts | P1 | ✅ couvert | `ManageParticipantsTest::testHistoricalSectionShowsRemovedVolunteerWithAdminBadge` |
| 12.6 | Badge "modifié par" affiché si `last_modified_by` renseigné | P2 | ✅ couvert | `ManageParticipantsTest::testAdminSeesModificationBadgeWhenSignupWasModified` |

---

## 14. Parcours FR-13 : Flux de confirmation d'identité

> **Périmètre** : `auth` + `dashboard_participations` · tous états · `Bénévole` · `orphelin` → `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 13.1 | Connexion avec orphelins → `resolveOrphanSignups()` déclenche le rattachement | P0 | ⚠️ partiel | Flux implicite dans `MagicLinkVerifyTest` — aucun test unitaire direct de `resolveOrphanSignups()` ; cas limites non couverts → Story **6.4** / **6.6** |
| 13.2 | Inscription orpheline affiche boutons "Accepter" / "Refuser" | P1 | ✅ couvert | `ConfirmSignupTest::testUnconfirmedSignupShowsConfirmationButtons` |
| 13.3 | Inscription créée par soi-même → boutons absents | P1 | ✅ couvert | `ConfirmSignupTest::testSelfCreatedSignupDoesNotShowConfirmationButtons` |
| 13.4 | Bénévole accepte → `accepted_at` renseigné | P0 | ✅ couvert | `ConfirmSignupTest::testBenevoleAcceptsUnconfirmedSignupSetsAcceptedAt` |
| 13.5 | Inscription acceptée compte dans la capacité | P0 | ✅ couvert | `ConfirmSignupTest::testAcceptedSignupCountsTowardCapacity` |
| 13.6 | Bénévole refuse → `rejected_at` renseigné, place libérée | P0 | ✅ couvert | `ConfirmSignupTest::testBenevoleRejectsUnconfirmedSignupFreesSlot` |
| 13.7 | Autre bénévole ne peut pas accepter/refuser à sa place | P0 | ✅ couvert | `ConfirmSignupTest::testAnotherBenevoleCannotAcceptSomeoneElsesSignup` |
| 13.8 | `viewed_at` renseigné à la connexion pour toute inscription avec `created_by != user_id` | P1 | ⚠️ partiel | Non isolé explicitement dans les tests → Story **6.4** |
| 13.9 | **[Régression identité]** Orphelin avec email modifié par admin puis connexion → réassignment correct | P0 | ❌ manquant | → Story **6.4** (Playwright) ou **6.6** (MariaDB) |
| 13.10 | Annulation admin tracée : `canceled_at` + `canceled_by` obligatoirement renseignés | P0 | ✅ couvert | `SignupServiceAdminTest::testAdminCancelStampsModificationColumns` |

---

## 15. Parcours FR-14 : Inviter et attribuer des rôles

> **Périmètre** : `dashboard_equipe` · tous états · `Admin` / `Owner` · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 14.1 | Owner invite un email inconnu → compte créé, rôle assigné, email envoyé | P1 | ✅ couvert | `InviteRoleTest::testOwnerInvitesUnknownEmailCreatesUserAssignsRoleTokenAndEvent` |
| 14.2 | Inviter un email existant → rôle assigné sans duplication de compte | P0 | ✅ couvert | `InviteRoleTest::testInviteExistingUserAssignsRoleWithoutDuplicatingAccount` |
| 14.3 | Gestionnaire ne peut pas inviter → refus | P0 | ✅ couvert | `InviteRoleTest::testGestionnaireCannotSubmitInvitation` |
| 14.4 | Bénévole ne peut pas inviter → refus | P0 | ✅ couvert | `InviteRoleTest::testBenevoleCannotSubmitInvitation` |
| 14.5 | Ré-invitation → rôle mis à jour idempotent | P1 | ✅ couvert | `InviteRoleTest::testReInvitingMemberUpdatesRoleIdempotently` |
| 14.6 | Email d'invitation en échec → role quand même assigné, avertissement | P1 | ✅ couvert | `InviteRoleTest::testEmailDeliveryFailureWarnsButStillAssignsRole` |
| 14.7 | Owner ne peut pas être réassigné via invitation | P0 | ✅ couvert | `InviteRoleTest::testCannotReassignOwnerThroughInvitation` |
| 14.8 | Notification email au Owner lors de l'acceptation d'un nouveau membre | P1 | ⚠️ partiel | `TeamMembersTabTest` (partiel) ; pas de test sur l'email de notification Owner → Story **6.5** |
| 14.9 | Ligne "Vous" et badge visible sur la propre ligne dans l'onglet Équipe | P2 | ✅ couvert | `TeamMembersTabTest` |

---

## 16. Parcours FR-15 : Gérer les inscriptions (Admin)

> **Périmètre** : `dashboard_inscrits` · tous états · `Admin` / `Gestionnaire` / `Owner` · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 15.1 | Admin ajoute une inscription manuellement (kermesse `closed` possible) | P1 | ✅ couvert | `AdminSignupActionsTest::testAdminAddSignupWorksWhenKermesseClosed` + `SignupServiceAdminTest` |
| 15.2 | Admin corrige les coordonnées → `signups` modifié, jamais `users` | P0 | ✅ couvert | `AdminSignupActionsTest::testAdminEditNeverMutatesUsersTable` |
| 15.3 | Admin annule une inscription → `canceled_at`/`canceled_by` renseignés | P0 | ✅ couvert | `AdminSignupActionsTest::testAdminCancelStampsLastModifiedByUserId` |
| 15.4 | Email corrigé correspond à un profil existant → réassignment `user_id` | P0 | ⚠️ partiel | `SignupServiceAdminTest::testAdminEditSucceedsWhenFirstAccessAtIsNull` ; réassignment `user_id` non couvert → Story **6.5** |
| 15.5 | Admin déplace une inscription vers un autre créneau | P1 | ⚠️ partiel | Non couvert dans les feature tests (seulement service unit) → Story **6.5** |
| 15.6 | Créneau plein → ajout admin refusé | P0 | ✅ couvert | `AdminSignupActionsTest::testAdminAddSignupRejectsFullSlot` |
| 15.7 | Gestionnaire ne peut pas ajouter sans autorisation | P0 | ⚠️ partiel | Filtre rôle non explicitement testé pour /admin-add → Story **6.5** |

---

## 17. Parcours FR-16 : Révoquer un rôle

> **Périmètre** : `dashboard_equipe` · tous états · `Admin` / `Owner` · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 16.1 | Révoquer un Admin actif sans inscription → rétrogradé Bénévole | P1 | ✅ couvert | `InviteRoleTest::testRemoveActiveMemberWithoutSignupsDowngradesToBenevole` |
| 16.2 | Révoquer un Admin avec inscriptions → rétrogradé Bénévole (inscriptions conservées) | P1 | ✅ couvert | `InviteRoleTest::testRemoveAdminWithActiveSignupsDowngradesToBenevole` |
| 16.3 | Le rôle Owner est non révocable | P0 | ✅ couvert | `RoleServiceRemoveRoleTest::testRemoveRoleOwnerIsNoOp` |
| 16.4 | Auto-révocation via endpoint → non bloqué (bug connu) | P0 | ❌ manquant | **Bug documenté** `deferred-work.md` : Admin peut soumettre son propre `userId` → Story **6.5** |
| 16.5 | Notification email au Owner après révocation | P1 | ⚠️ partiel | Non explicitement testé → Story **6.5** |

---

## 18. Parcours FR-17 : Quitter une kermesse

> **Périmètre** : `connected_home` / `dashboard_participations` / `dashboard_equipe` · tous états · tous rôles (sauf Owner) · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 17.1 | Bénévole sans inscription active peut quitter | P1 | ✅ couvert | `LeaveKermesseTest::testLeaveKermesseSuccessFlashAndRedirect` |
| 17.2 | Bénévole avec inscription active → bouton absent, refus serveur | P0 | ✅ couvert | `LeaveKermesseTest::testLeaveRejectedServerSideWhenActiveSignupExists` |
| 17.3 | Owner → bouton absent, refus serveur | P0 | ✅ couvert | `LeaveKermesseTest::testLeaveRejectedForOwner` |
| 17.4 | Kermesse retirée de l'accueil connecté après départ | P1 | ✅ couvert | `LeaveKermesseTest::testLeaveKermesseRemovesKermesseFromConnectedHome` |
| 17.5 | Notification email au Owner après départ | P1 | ⚠️ partiel | Non explicitement testé → Story **6.5** |
| 17.6 | Bouton "Quitter" visible dans l'onglet Équipe sur la propre ligne | P2 | ✅ couvert | `TeamMembersTabTest` / `LeaveKermesseTest` |

---

## 19. Parcours FR-18 : Gérer son profil

> **Périmètre** : `profile` · tous états · tous rôles connectés · `session_active`

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 18.1 | Modifier prénom/nom/email/téléphone → persisté dans `users` | P1 | ✅ couvert | `ProfileUpdateTest` (7 tests), `ProfileUpdateServiceTest` (4 tests) |
| 18.2 | Email modifié → hash mis à jour | P0 | ✅ couvert | `ProfileUpdateServiceTest` |
| 18.3 | Modification ne touche jamais le snapshot `signups` | P0 | ✅ couvert | `ProfileUpdateServiceTest` |
| 18.4 | Accès sans session → redirection login | P0 | ⚠️ partiel | `ProfileUpdateTest` (SQLite) → Story **6.5** |
| 18.5 | Vérification email après modification (absence d'email confirmation) | P0 | ❌ manquant | **Bug documenté** `deferred-work.md` : pas de vérification email → Story **6.7** |

---

## 20. Parcours NFR — Migrations et déploiement

> **Périmètre** : `ops` · hors session utilisateur

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 20.1 | `POST /ops/migrate` avec HMAC valide → migrations exécutées | P0 | ✅ couvert | `OpsMigrateEndpointTest` (4), `OpsMigrateEndpointMariaDBTest` (3) |
| 20.2 | `POST /ops/migrate` sans HMAC → 403 | P0 | ✅ couvert | `OpsAuthFilterTest` (12 tests) |
| 20.3 | `POST /ops/migrate` avec timestamp périmé → 403 (anti-replay) | P0 | ✅ couvert | `OpsAuthFilterTest` |
| 20.4 | `POST /ops/migrate` avec nonce rejoué → 403 | P0 | ✅ couvert | `OpsAuthFilterTest` |
| 20.5 | Activation atomique `POST /ops/activate` | P0 | ✅ couvert | `OpsActivateEndpointTest` (3), `OpsActivateEndpointMariaDBTest` (5) |
| 20.6 | `POST /ops/probe` retourne l'état runtime | P1 | ✅ couvert | `OpsProbeEndpointTest` (3), `OpsProbeEndpointMariaDBTest` (2) |
| 20.7 | `POST /ops/migrate/status` → état des migrations sans mutation | P1 | ✅ couvert | `OpsMigrateStatusEndpointTest` (4), `MigrationRunnerServiceStatusTest` (8) |
| 20.8 | Migrations idempotentes (ré-application = no-op) | P0 | ✅ couvert | `MigrationRunnerMariaDBTest` (8 tests) |
| 20.9 | FK `signups/volunteers` vérifiées en MariaDB | P0 | ❌ manquant | **Dette documentée** `deferred-work.md` : test FK manquant → Story **6.6** |
| 20.10 | Teardown dynamique (SHOW TABLES) plutôt que liste statique fragile | P1 | ❌ manquant | **Dette documentée** `deferred-work.md` → Story **6.6** |
| 20.11 | Schema CI MariaDB vérifié indépendamment des tests destructifs | P1 | ❌ manquant | **Dette documentée** `deferred-work.md` → Story **6.6** |

---

## 21. Parcours NFR — Confidentialité (PII)

| # | Action → Résultat | P | Couverture | Référence |
|---|-------------------|---|------------|-----------|
| 21.1 | Page publique ne contient aucun email, nom, Magic Link | P0 | ✅ couvert | `PublicVolunteerPageTest::testPublicPageDoesNotLeakUserOrAdminData` |
| 21.2 | Page publique ne contient pas de données de stands inactifs | P0 | ✅ couvert | `PublicVolunteerPageTest::testOpenDoesNotExposeInactiveStandsOrInactiveSlots` |
| 21.3 | Formulaire d'inscription ne fuite pas les ID internes | P0 | ✅ couvert | `PublicSignupFormTest::testSignupFormDoesNotLeakInternalData` |
| 21.4 | Page de confirmation sans ID ni données BDD exposés | P0 | ✅ couvert | `PublicSignupFormTest::testConfirmationPageDoesNotExposeInternalIds` |
| 21.5 | Bénévole ne voit pas les données des autres dans son dashboard | P0 | ✅ couvert | `ManageParticipantsTest::testBenevoleSeesNeitherSectionNorOtherVolunteersPII` |

---

## 22. Régressions connues

### 22.1 [P1] Régression 6.1 — Blocage incorrect : créneau sur kermesse `open`

- **Mécanisme** : Le contrôleur `SlotController::store()` ne possédait pas de frontière métier côté serveur. Seul le JavaScript désactivait le bouton en UI — un Owner / Admin ne pouvait donc pas ajouter de créneau par POST sans contourner le JavaScript. Le comportement attendu (création réussit) nécessitait un refactoring vers un Service avec DTO.
- **Découverte** : Les tests SQLite initiaux ciblaient uniquement l'état `preparation`, masquant l'absence de frontière d'écriture sur `open`.
- **Résolution** : Story 6.1 (corrigée en 2026-06-18) — création du `SlotService`, DTO `readonly`, tests SQLite + MariaDB.
- **Test de non-régression** : `ManageSlotsMariaDBTest::testCreateSlotOnOpenKermesseWithRealSchema` ✅

### 22.2 [P1] Régression JavaScript — Navigation onglets et modales

- **Mécanisme** : La navigation entre onglets du dashboard et les modales de confirmation (suppression de stand, annulation par admin) reposent sur JavaScript. Les tests PHPUnit n'exécutent pas de JavaScript — ils ne peuvent pas détecter une régression JS.
- **Surface concernée** : `dashboard_*` · tous états · tous rôles connectés
- **Impact** : Un bug JavaScript peut rendre une fonctionnalité entière inaccessible sans qu'aucun test n'échoue.
- **Test de non-régression** : ❌ Manquant → Story **6.3** (infrastructure Playwright + Docker) + Story **6.5** (parcours organisateurs)

### 22.3 [P0] Régression identité — Orphelins et réconciliation à la connexion

- **Mécanisme** : Le flux `resolveOrphanSignups()` est appelé à la validation du Magic Link mais les cas limites ne sont pas tous couverts : orphelin avec email modifié par admin avant connexion, orphelin sans snapshot de nom (admin sans prénom/nom), ordre de rattachement si plusieurs orphelins pour des kermesses différentes.
- **Surface concernée** : `auth` + `dashboard_participations` · tous états · `session_absente` → `session_active` · `orphelin`
- **Impact** : Une inscription orpheline peut rester non rattachée ou rattachée au mauvais compte.
- **Test de non-régression** : ❌ Manquant → Story **6.4** (parcours bénévole Playwright) ou **6.6** (MariaDB transactionnel)

---

## 23. Récapitulatif des lacunes P0/P1 et plan d'action

| ID | Parcours | P | Statut | Story cible | Propriétaire | Niveau de test cible |
|----|----------|---|--------|-------------|--------------|----------------------|
| G01 | Race condition capacité créneaux (MariaDB concurrent) | P0 | ❌ manquant | **6.6** | Story 6.6 | Test concurrent MariaDB |
| G02 | Flux Magic Link complet en MariaDB réel | P0 | ❌ manquant | **6.6** | Story 6.6 | Test feature MariaDB |
| G03 | Machine à états `getStatus()` en MariaDB | P0 | ⚠️ partiel | **6.6** | Story 6.6 | Test database MariaDB |
| G04 | FK `signups/volunteers` validées en MariaDB | P0 | ❌ manquant | **6.6** | Story 6.6 | Test database MariaDB |
| G05 | Orphelin email modifié par admin → réconciliation correcte | P0 | ❌ manquant | **6.4** / **6.6** | Story 6.4 | Test E2E Playwright / MariaDB |
| G06 | Auto-révocation Admin (bug authz) | P0 | ❌ manquant | **6.5** | Story 6.5 | Test feature PHPUnit |
| G07 | Vérification email après modification profil | P0 | ❌ manquant | **6.7** | Story 6.7 | Test feature PHPUnit |
| G08 | Filtre rôle Bénévole/Gestionnaire sur routes Admin (MariaDB) | P0 | ⚠️ partiel | **6.5** | Story 6.5 | Test feature MariaDB |
| G09 | Navigation onglets JavaScript (E2E) | P1 | ✅ couvert | **6.3** | Story 6.3 | `e2e/tests/participations.spec.ts`, `team.spec.ts`, `add-slot.spec.ts` — 18/18 Chromium desktop+mobile |
| G10 | Flux complet inscription bénévole (E2E navigateur) | P1 | ❌ manquant | **6.4** | Story 6.4 | Test E2E Playwright |
| G11 | Annulation / confirmation identité (E2E navigateur) | P1 | ❌ manquant | **6.4** | Story 6.4 | Test E2E Playwright |
| G12 | Réduire capacité < inscrits actifs → refusé | P0 | ❌ manquant | **6.5** | Story 6.5 | Test feature PHPUnit |
| G13 | Déplacement d'inscription (feature test) | P1 | ⚠️ partiel | **6.5** | Story 6.5 | Test feature PHPUnit |
| G14 | Notification email Owner (équipe / départ) | P1 | ⚠️ partiel | **6.5** | Story 6.5 | Test feature PHPUnit |
| G15 | Schema CI MariaDB indépendant des tests destructifs | P1 | ❌ manquant | **6.6** | Story 6.6 | Test CI MariaDB |
| G16 | Teardown MariaDB dynamique (pas de liste statique) | P1 | ❌ manquant | **6.6** | Story 6.6 | Test database MariaDB |
| G17 | `viewed_at` explicitement testé à la connexion | P1 | ⚠️ partiel | **6.4** | Story 6.4 | Test feature PHPUnit |
| G18 | Transition lifecycle `preparation → open` persistée en MariaDB | P1 | ⚠️ partiel | **6.6** | Story 6.6 | Test database MariaDB |
| G19 | Stale availability E2E (créneau plein entre affichage et submit) | P0 | ⚠️ partiel | **6.4** | Story 6.4 | Test E2E Playwright |
| G20 | Annulation par bénévole sur kermesse `closed` bloquée côté serveur | P0 | ⚠️ partiel | **6.5** | Story 6.5 | Test feature PHPUnit |
| G21 | LEFT JOIN inscriptions orphelines (`user_id=NULL`) validé en MariaDB | P0 | ⚠️ partiel | **6.6** | Story 6.6 | Test database MariaDB |
| G22 | Réassignment `user_id` lors d'une correction admin d'email | P0 | ⚠️ partiel | **6.5** | Story 6.5 | Test feature PHPUnit |
| G23 | Accès au profil sans session → redirection login (MariaDB) | P0 | ⚠️ partiel | **6.5** | Story 6.5 | Test feature MariaDB |
| G24 | Session expirée sur route GET → redirect `?redirect=` + retour post-login | P1 | ✅ couvert | **6.8** | `SessionExpirationTest` (2 tests) | Test feature PHPUnit |
| G25 | Session expirée sur route POST (action dashboard) → flash + redirect login, pas d'erreur PHP | P1 | ✅ couvert | **6.8** | `SessionExpirationTest` (2 tests) | Test feature PHPUnit |
| G26 | Paramètre `redirect` validé same-origin (open redirect bloqué) | P0 | ✅ couvert | **6.8** | `SessionExpirationTest` (2 tests) | Test feature PHPUnit |

---

## 24. Dette technique systémique (à traiter Story 6.6)

La dette la plus critique identifiée dans `deferred-work.md` et confirmée par analyse des tests :

> **Duplication de schéma SQL brut dans les fixtures** : plus de 11 fichiers de test dupliquent des `CREATE TABLE` SQL manuels au lieu de s'appuyer sur les migrations officielles ou le `Fabricator` CI4. Toute modification de schéma (nouvelle colonne, FK) doit être répercutée manuellement dans chaque copie — source de désynchronisation silencieuse. Ce patron est la principale raison pour laquelle les régressions de schéma MariaDB ne sont pas détectées en tests SQLite.

**Propriétaire :** Story **6.6** — Fiabiliser les preuves MariaDB sur les parcours critiques.

---

## 25. Guide de maintenabilité

Pour ajouter une **nouvelle régression découverte** à cette matrice :

1. Identifier la section FR concernée (FR-1 à FR-18) ou NFR.
2. Ajouter une ligne dans la section correspondante selon le statut réel :
   - Si le test est absent → `❌ manquant`
   - Si le test existe mais ne couvre qu'une partie (SQLite sans MariaDB, unit sans feature) → `⚠️ partiel`
   - Si la régression est déjà couverte (test existant + passant) → `✅ couvert` avec la référence exacte
3. Ajouter une entrée dans la **Section 22 — Régressions connues** si la régression est avérée en production ou découverte en revue, avec le mécanisme, la surface et le test de non-régression.
4. Si le statut est `❌ manquant` ou `⚠️ partiel`, ajouter une ligne dans le tableau **Section 23 — Récapitulatif lacunes** avec la Story cible, le propriétaire et le niveau de test cible.
5. Mettre à jour le statut (`⚠️ partiel` → `✅ couvert`, ou `❌ manquant` → `✅ couvert`) dès que le test de non-régression est fusionné ; mettre aussi à jour la Section 23.

La structure `surface × état × rôle × identité × action → résultat` permet d'ajouter une ligne sans restructurer le document.
