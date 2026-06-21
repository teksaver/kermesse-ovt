---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - /Users/sylvaintenier/Documents/Kermesse/_bmad-output/planning-artifacts/prds/prd-Kermesse-2026-06-01/prd.md
  - /Users/sylvaintenier/Documents/Kermesse/_bmad-output/planning-artifacts/architecture.md
  - /Users/sylvaintenier/Documents/Kermesse/_bmad-output/planning-artifacts/ux-designs/ux-Kermesse-2026-06-01/DESIGN.md
  - /Users/sylvaintenier/Documents/Kermesse/_bmad-output/planning-artifacts/ux-designs/ux-Kermesse-2026-06-01/EXPERIENCE.md
---

# Kermesse - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for Kermesse, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: Page d'accueil (Non connecté) — deux actions : "Créer une kermesse" (infos perso organisateur + infos basiques kermesse) ou "Me connecter" (demande de Magic Link).
FR2: Connexion par Magic Link — l'utilisateur demande un Magic Link via son email ; le lien l'authentifie et le redirige vers son accueil connecté.
FR3: Page d'accueil (Connecté) — liste des kermesses de l'utilisateur avec son rôle pour chacune + bouton "Créer une nouvelle kermesse" (formulaire allégé, sans redemander les infos perso).
FR4: Créer une kermesse — un utilisateur (connecté ou non) crée une kermesse et obtient le rôle Owner ; si non connecté, l'action crée son compte Utilisateur.
FR5: Gérer les stands et créneaux — ajouter, modifier, supprimer des stands et des créneaux (capacité > 0) ; la suppression d'un stand avec inscriptions requiert une validation forte (`SUPPRIMER`).
FR6: Ouvrir et fermer les inscriptions — l'admin peut ouvrir ou fermer les inscriptions bénévoles.
FR7: Afficher la kermesse publique — vue publique listant stands/créneaux/places restantes ; encart "Déjà inscrit ? Connectez-vous" redirigeant vers l'authentification Magic Link.
FR8: Créer une inscription (Publique) — le bénévole s'inscrit par email. Trois scénarios : visiteur non connecté + email inconnu → `user_id = NULL`, aucun compte créé ; visiteur non connecté + email connu → `user_id` renseigné ; utilisateur connecté → `user_id` + `created_by` renseignés. Dans tous les cas, l'inscription réserve la place (capacité active). Divergences de profil : snapshot conservé dans `signups`, réconciliation stateless à la première connexion (Story 5.4).
FR9: Éviter les conflits de planning — empêche l'inscription double sur un même créneau ou sur des créneaux qui se chevauchent pour le même Utilisateur (identifié par email).
FR10: Accès au tableau de bord — vue unifiée avec jusqu'à 4 onglets selon le rôle : Modification (Admin/Owner), Gestion des inscrits (Owner/Admin/Gestionnaire), Équipe (Admin/Owner), Mes participations (tous rôles).
FR11: Supprimer une inscription (Bénévole) — dans "Mes participations", l'utilisateur supprime une de ses inscriptions actives, libérant la place.
FR12: Voir les inscrits (Admin/Gestionnaire) — dans "Gestion des inscrits", affichage des bénévoles inscrits par stand/créneau.
FR13: Confirmation des coordonnées + résolution de conflits de profil — confirmation obligatoire à la 1ʳᵉ connexion (quoi qu'il arrive) ; ensuite résolution des divergences uniquement si présentes, avant de continuer.
FR14: Inviter et attribuer des rôles — un Owner ou Admin invite d'autres utilisateurs par email en leur attribuant un rôle (Admin ou Gestionnaire) ; le système crée le compte si l'email est inconnu et notifie la personne par email.
FR15: Gérer les inscriptions (Admin/Gestionnaire) — ajouter, corriger, annuler, déplacer une inscription depuis "Gestion des inscrits" (corrections locales à `slot_signups`, verrouillées dès le premier accès du bénévole).
FR16: Révoquer un rôle — Owner/Admin rétrograde un Admin/Gestionnaire en Bénévole (Owner non révocable).
FR17: Quitter une kermesse — un utilisateur sans inscription active se retire lui-même (bouton absent si inscriptions actives ; Owner ne peut pas quitter).
FR18: Gérer son profil — page `/profile` pour auto-modifier ses coordonnées ; seule voie pour corriger des coordonnées verrouillées côté admin.

### NonFunctional Requirements

NFR1: Mobile-first — utilisable confortablement sur smartphone, notamment pour la page bénévole publique.
NFR2: Fluidité (Frictionless) — l'inscription publique ne doit pas obliger la création explicite d'un compte avec mot de passe au moment de l'action.
NFR3: Robustesse — prévention stricte des surcapacités côté serveur et des chevauchements horaires par Utilisateur.
NFR4: Sécurité — les Magic Links expirent après une durée courte (ex. 15 minutes) et sont à usage unique ; les routes de modification vérifient le rôle en base côté serveur.
NFR5: Confidentialité — la page publique de la kermesse n'expose aucune donnée personnelle des bénévoles (les noms sont visibles uniquement dans l'espace Admin/Gestionnaire connecté).
NFR6: Préparation à la production — chaque parcours critique P0/P1 doit disposer d'une preuve automatisée reproductible sur la couche pertinente (PHPUnit, MariaDB ou navigateur), et la release candidate doit être qualifiée à partir de l'artefact immuable réellement destiné au déploiement.

### Additional Requirements

- **Starter Template** : utiliser `codeigniter4/appstarter`. Impacte directement Epic 1 Story 1.
- **Greenfield reset (décision 2026-06-11)** : la production est vide ; on droppe l'ancien schéma et le code legacy (anciens modèles/services `Owner*`/`Volunteer*` issus de l'avant-pivot) plutôt que de migrer. Impacte Epic 1 (init + purge legacy).
- **Environnement** : runtime PHP sur Ouvaton avec MariaDB obligatoire ; production runtime-only (pas de Composer, NPM, tests, build d'assets, ni migrations `spark` sur le serveur).
- **Déploiement** : GitHub Actions construit, teste, package et déploie l'artefact complet (incluant `vendor/` et les assets statiques) ; protocole FTP/FTPS/SFTP selon le compte Ouvaton.
- **Migrations DB** : appliquées via une route sécurisée `POST /ops/migrate` (HMAC, fraîcheur du timestamp, anti-rejeu par nonce, verrou DB, exécution production-only) ; suivi dans `schema_versions`.
- **Identité** : l'Utilisateur est unique sur toute la plateforme et s'authentifie via email (Magic Link) ; session PHP globale côté serveur ; pas de JWT.
- **Déconnexion** : route `POST /auth/logout` pour détruire la session globale.
- **RBAC** : contrôle d'accès basé sur des rôles par kermesse (Owner, Admin, Gestionnaire, Bénévole), vérifiés en base côté serveur via filtres (`AuthFilter` session + `RoleFilter` rôle).
- **Schéma initial** : tables `users`, `access_tokens`, `kermesses`, `kermesse_user_roles`, `stands`, `slots`, `signups`, `email_events`, `schema_versions`. *(Note : `profile_divergences` n'est **pas** créée — la réconciliation de profil est stateless, voir Story 5.4.)*
- **Invariants service-owned** : `SlotSignupService` (capacité/doublon/chevauchement/annulation, transactionnel), `TokenService` (Magic Links hachés, usage unique, expiration), `EmailService` (+ `email_events`), `RoleService` (rôles + invitations), `KermesseLifecycleService` (préparation/ouvert/fermé).
- **Sécurité formulaires** : CSRF actif sur tous les formulaires modifiant l'état.
- **Codes d'erreur stables** : `slot_full`, `duplicate_signup`, `overlap_conflict`, `signups_not_open`, `invalid_token`, `expired_token`, `unauthorized_admin`, `unauthorized_role`.
- **RGPD** : suppression/archivage des comptes inactifs en post-MVP (le compte persiste par défaut).
- **Stabilisation avant production (décision 2026-06-18)** : gel fonctionnel pendant une Epic dédiée ; aucune nouvelle fonctionnalité métier n'entre dans la release candidate.
- **Régressions connues** : conserver un test automatisé permanent pour l'affichage des inscriptions du bénévole, la reconnaissance de l'utilisateur courant dans l'onglet Équipe et l'ajout d'un créneau à une kermesse ouverte.
- **Cartographie des parcours** : maintenir une matrice `état × rôle × identité × action × résultat`, classée P0/P1/P2 ; toutes les cellules P0/P1 doivent être reliées à une preuve avant le Go production.
- **Tests navigateur** : utiliser Playwright pour les parcours critiques dépendant du JavaScript et du rendu réel ; toute exception JavaScript, réponse HTTP inattendue ou perte de persistance après rechargement fait échouer le scénario.
- **Parité base de données** : valider sous MariaDB les migrations, contraintes, transactions, autorisations et transitions d'état sensibles ; SQLite reste la boucle rapide mais ne constitue pas seul une preuve de production.
- **Gates CI** : rendre bloquants PHPUnit, les scénarios MariaDB ciblés, les smoke tests Playwright critiques et la validation de l'artefact de déploiement.
- **Qualification de release** : tester l'artefact immuable sur un environnement représentatif, répéter migration et restauration, exécuter les smoke tests post-déploiement et formaliser la décision Go/No-Go.
- **Source de vérité signup** : le PRD mis à jour le 2026-06-17 (statut calculé depuis les timestamps) prévaut sur les passages historiques de l'architecture décrivant encore un statut stocké.

### UX Design Requirements

UX-DR1: Implémenter le système de tokens visuels (couleurs restreintes) : background `#F8FAFC`, surface `#FFFFFF`, surface-muted `#EEF2F7`, primary green `#166534`, warning amber `#B45309`, danger red `#B91C1C`, full gray `#374151`, focus blue `#2563EB`, et les variantes soft documentées. Aucune couleur d'accent par stand (l'identité d'un stand vient du nom, du groupement et de l'ordre).
UX-DR2: Typographie system-ui : page-title 24px/700, section-title 18px/700, body 16px/400, label 14px/600, meta 13px/400 ; pas de capitales, pas de type fluide au viewport, rien sous 13px.
UX-DR3: Layout mobile-first : colonne unique, marges latérales 16px, slot rows pleine largeur tappables, stand groups empilés verticalement ; largeurs lisibles max 720px (bénévole) et 960px (admin).
UX-DR4: Bordures et contraste de surface plutôt qu'ombres ; ombres réservées aux dialogs/sheets si besoin.
UX-DR5: Rayons cohérents : 8px pour inputs, slot rows, cards et boutons ; 10px uniquement pour les grands dialogs/panneaux de confirmation ; 4px pour les badges ; éviter les layouts "pill".
UX-DR6: Boutons primaire et secondaire avec une seule action primaire par surface/étape de formulaire.
UX-DR7: Inputs et textarea avec labels visibles, helper text sous le champ, surface blanche, bordure, rayon 8px et focus ring visible.
UX-DR8: Status badges pour les états draft, open, closed et full ; le badge informe mais ne remplace jamais le texte explicatif.
UX-DR9: Slot row comme composant bénévole central : row pleine largeur affichant plage horaire, places restantes, capacité totale et état ; rows disponibles tappables, rows complètes visibles mais désactivées.
UX-DR10: Stand group avec nom du stand, note optionnelle et slot rows ; stands vides → état "Aucun créneau pour le moment" dans le dashboard, masqués ou marqués indisponibles sur la page publique selon l'état d'ouverture.
UX-DR11: Confirmation panels (inscription, suppression, connexion) énonçant exactement ce qui s'est passé, le stand/créneau concerné et l'étape suivante.
UX-DR12: Confirmation destructive forte pour la suppression d'un stand avec inscriptions : saisie de `SUPPRIMER` requise, bouton désactivé jusqu'à correspondance exacte.
UX-DR13: Implémenter les surfaces d'IA de l'identité unifiée : Global Home (Créer une kermesse / Me connecter), Magic Link request, Magic Link sent, Profile resolution, Connected home, Create kermesse, Kermesse dashboard (4 onglets), Profile resolution / first-login confirmation, Profile page, Invite & assign role, Volunteer page, Signup form, Signup confirmation, Delete signup confirmation.
UX-DR14: La page bénévole n'a aucune navigation globale ni menu de compte.
UX-DR15: Les surfaces connectées (connected home, dashboard) utilisent un header minimal avec un retour vers la connected home ; changer de kermesse passe par la connected home.
UX-DR16: Le dashboard kermesse ne rend que les sections autorisées par le rôle (Modification, Gestion des participants, Mes participations), sur une seule page (blocs labellisés, pas des routes séparées) ; une section non autorisée est absente, pas seulement désactivée.
UX-DR17: Section Modification : actions admin via nom de la kermesse, badge de statut et actions `Prévisualiser`, `Copier le lien`, `Ouvrir/Fermer` ; feedback "Lien copié." ; le preview ouvre la page bénévole (pas un mode design séparé).
UX-DR18: Authentification Magic Link uniquement (aucun mot de passe nulle part) ; les surfaces "Magic Link request/sent" et "lien invalide/expiré" donnent le même résultat neutre, que l'email ait un compte ou non (confidentialité).
UX-DR19: Profile resolution : présenter les valeurs divergentes (stockées vs soumises) et exiger un choix explicite avant d'atteindre la connected home.
UX-DR20: Invite & role form : champ email + sélecteur de rôle (Admin/Gestionnaire) ; à la soumission, confirme l'envoi de l'invitation.
UX-DR21: Signup form : résumé du stand et du créneau sélectionnés en haut, puis prénom, nom, email, téléphone facultatif ; messages de validation inline ; bouton désactivé seulement pendant l'envoi.
UX-DR22: Signup confirmation : indique que le bénévole est inscrit, qu'un email a été envoyé, et qu'il peut se connecter par Magic Link pour retrouver ses participations ; si la livraison email est inconnue, l'inscription reste confirmée et l'UI explique quoi faire.
UX-DR23: "Mes participations" : liste uniquement les inscriptions actives du bénévole pour cette kermesse et retire les inscriptions annulées de la liste active après suppression ("La place est de nouveau disponible.").
UX-DR24: "Gestion des participants" : chaque stand avec ses créneaux, places occupées et restantes, et coordonnées des bénévoles, optimisé pour repérer les créneaux encore à pourvoir.
UX-DR25: Microcopy FR pratique et spécifique, incluant les messages équivalents à : "Il reste 2 places.", "Ce créneau est complet.", "Votre inscription est confirmée.", "Un lien de connexion vient d'être envoyé à votre adresse.", "Connectez-vous par lien pour retrouver vos inscriptions.", "Cette suppression libérera une place."
UX-DR26: Gérer tous les états : kermesse draft, sans stand, stand sans créneau, ouverture bloquée, inscriptions ouvertes, inscriptions fermées, slot complet, capacity race perdue, doublon, chevauchement, email envoyé, livraison email inconnue, Magic Link envoyé, divergence de profil à résoudre, Magic Link invalide/expiré, inscription supprimée, loading, erreur réseau/serveur.
UX-DR27: Loading via skeleton rows épousant le layout attendu (pas de spinner seul) ; les formulaires conservent les valeurs saisies après une erreur réseau/serveur et autorisent le retry.
UX-DR28: Primitives d'interaction : tap pour agir, row disponible entièrement tappable, submit via Enter, back sans perte des données confirmées, confirmation destructive admin, confirmation simple bénévole, entrée QR code menant directement à la page bénévole ; pas de drag-and-drop, carrousel, onboarding, confetti ni gamification.
UX-DR29: Plancher d'accessibilité WCAG 2.2 AA : cibles tap 44px, labels visibles, placeholders en exemples seulement, erreurs liées aux champs + résumé près du submit en cas d'erreurs multiples, ordre de focus suivant l'ordre visuel, feedback live accessible, slots complets lisibles, pas de scroll horizontal à 320px.
UX-DR30: Responsive : <640px colonne unique avec slots/forms pleine largeur et Gestion des participants en stand cards ; 640-1023px colonne unique avec marges plus larges et actions admin inline ; ≥1024px le dashboard peut utiliser une table compacte/deux colonnes tandis que la page bénévole reste étroite et lisible.
UX-DR31: Utiliser les mockups (`mockups/volunteer-page-mobile.html`, `mockups/admin-setup-mobile.html`) comme références de composition, `EXPERIENCE.md` prévalant en cas de conflit.

### FR Coverage Map

FR1: Epic 1 - Page d'accueil publique (Créer une kermesse / Me connecter)
FR2: Epic 1 - Connexion par Magic Link
FR3: Epic 1 - Accueil connecté (liste des kermesses + rôle)
FR4: Epic 2 - Création de la kermesse (rôle Owner)
FR5: Epic 2 - Gestion des stands et créneaux (suppression forte `SUPPRIMER`)
FR6: Epic 2 - Ouverture/fermeture des inscriptions
FR7: Epic 3 - Page publique de la kermesse
FR8: Epic 3 - Inscription publique et rattachement par email ; machine à états (statuts + created_by + timestamps) en Epic 5 (Story 5.14)
FR9: Epic 3 - Conflits de planning (doublon / chevauchement)
FR13: Epic 3 - Résolution des divergences à la connexion (MVP) ; confirmation 1ʳᵉ connexion en Epic 5 (Story 5.4) ; accept/reject + canceled_by + orphan LEFT JOIN en Epic 5 (Story 5.14)
FR10: Epic 4 - Tableau de bord unifié (MVP : 3 sections selon rôle) ; extension 4 onglets en Epic 5 (Story 5.2)
FR11: Epic 4 - Suppression d'inscription (autonomie bénévole)
FR12: Epic 4 - Vue des inscrits (Admin/Gestionnaire)
FR14: Epic 4 - Invitation et attribution de rôles
FR15: Epic 5 - Gestion des inscriptions par l'admin (ajouter/corriger/annuler/déplacer) — Stories 5.10/5.11/5.12
FR16: Epic 5 - Révoquer un rôle — Story 5.8
FR17: Epic 5 - Quitter une kermesse — Story 5.9
FR18: Epic 5 - Gérer son profil (page /profile) — Story 5.7
NFR6: Epic 6 - Stabilisation et préparation à la production — preuves automatisées P0/P1, parité MariaDB, tests navigateur, gates CI et qualification de la release candidate

## Epic List

### Epic 1 : Identité Unifiée et Tableau de Bord Global
Les utilisateurs (organisateurs comme bénévoles) s'identifient via un Magic Link sécurisé, se déconnectent, et voient un accueil connecté listant toutes leurs kermesses avec leur rôle — sur un socle CodeIgniter neuf (greenfield) prêt pour Ouvaton.
**FRs covered:** FR1, FR2, FR3

### Epic 2 : Organisation et Lancement de Kermesse
Un organisateur crée une kermesse, définit ses besoins (stands, créneaux, capacités) et la publie pour les bénévoles.
**FRs covered:** FR4, FR5, FR6

### Epic 3 : Inscription Publique et Rattachement Automatique
Les bénévoles consultent le planning public et s'inscrivent en quelques gestes ; leur inscription s'attache automatiquement à leur compte Utilisateur, et toute divergence de coordonnées est résolue à leur prochaine connexion.
**FRs covered:** FR7, FR8, FR9, FR13

### Epic 4 : Gestion Opérationnelle, Délégation et Autonomie Bénévole
Les bénévoles libèrent leur place depuis leur espace connecté, les administrateurs suivent le remplissage par stand/créneau, et un Owner/Admin peut inviter une équipe de gestion.
**FRs covered:** FR10, FR11, FR12, FR14

### Epic 5 : Post-MVP — Identité avancée et délégation
Vague d\'évolutions post-livraison : confirmation d\'identité à la 1ʳᵉ connexion, navigation 4 onglets, révocation, départ autonome, page profil, duplication de stand, gestion des inscriptions. [TERMINÉ]
**FRs covered:** FR10 (extension), FR13 (extension), FR15, FR16, FR17, FR18

### Epic 6 : Stabilisation et préparation à la production
Les organisateurs et bénévoles peuvent utiliser les parcours critiques de Kermesse sans régression connue ; l'équipe dispose de preuves automatisées reproductibles sur PHPUnit, MariaDB et navigateur réel, puis qualifie l'artefact immuable destiné à Ouvaton par une décision Go/No-Go fondée sur des preuves.
**Requirements covered:** NFR6 ; validation transversale des FR1 à FR18

### Epic 7 : Backlog Post-MVP — Invités
Les bénévoles pourront inscrire des tiers.
**FRs covered:** Exigences post-MVP.

## Epic 1 : Identité Unifiée et Tableau de Bord Global

Les utilisateurs (organisateurs comme bénévoles) s'identifient via un Magic Link sécurisé, se déconnectent, et voient un accueil connecté listant toutes leurs kermesses avec leur rôle — sur un socle CodeIgniter neuf (greenfield) prêt pour Ouvaton.

### Story 1.1 : Réinitialiser le socle en greenfield (purge legacy + init CodeIgniter + runner de migration)

> **Note de contexte** : La partie « purge legacy » de cette story (suppression des modèles/services `Owner*`/`Volunteer*` et des anciennes routes) est **uniquement pertinente en contexte brownfield** (projet existant à nettoyer). Pour un projet démarrant de zéro en greenfield, sauter l'AC de purge et implémenter directement l'initialisation CodeIgniter 4, le runner de migration, et les assets CSS.

As a développeur,
I want purger l'héritage pré-pivot et initialiser un socle CodeIgniter 4 propre avec le runner de migration sécurisé,
So that le code métier de l'identité unifiée parte d'une base saine et déployable sur Ouvaton.

**Acceptance Criteria:**

**Given** la branche contient encore du code et un schéma issus de l'avant-pivot (modèles/services `Owner*`/`Volunteer*`, anciennes routes de gestion par lien),
**When** la réinitialisation greenfield est appliquée,
**Then** le code et les artefacts legacy contredisant le modèle d'identité unifié sont supprimés,
**And** aucun ancien schéma n'est requis (la production est vide ; pas de migration de données).

**Given** un environnement de développement initialisé,
**When** l'application est déployée ou testée,
**Then** la structure suit l'architecture CodeIgniter 4 (`Controllers/Auth`, `Home`, `Kermesse`, `Ops` ; `Services`, `Models`, `Filters`, `Views`),
**And** le suivi de schéma `schema_versions` et la route `POST /ops/migrate` (HMAC, fraîcheur de timestamp, anti-rejeu nonce, verrou DB, production-only) sont fonctionnels,
**And** `.env.example` documente toutes les variables d'environnement requises et le pipeline GitHub Actions (build/test/package/deploy) est en place.

**Given** la base d'assets,
**When** le socle est établi,
**Then** `public/assets/css/app.css` porte les tokens visuels (UX-DR1, UX-DR2, UX-DR5) et la grille mobile-first (UX-DR3, UX-DR30) ainsi que les partials réutilisables (slot row, stand group, status badge, confirmation panel, form field/errors).

### Story 1.2 : Afficher la page d'accueil publique (non connecté)

As a visiteur non connecté,
I want voir une page d'accueil claire offrant l'option de créer une kermesse ou de me connecter,
So that je comprenne immédiatement comment démarrer sur la plateforme.

**Acceptance Criteria:**

**Given** un visiteur sans session active qui arrive sur la racine du site (`/`),
**When** la page se charge,
**Then** elle affiche les deux actions "Créer une kermesse" et "Me connecter" (UX-DR13),
**And** l'interface est mobile-first, accessible (labels visibles, cibles 44px, pas de scroll horizontal à 320px — UX-DR29) et sans navigation globale superflue.

### Story 1.3 : Demander un lien de connexion (Magic Link)

As a visiteur non connecté,
I want saisir mon email dans le formulaire "Me connecter" pour recevoir un Magic Link,
So that je puisse m'authentifier de manière sécurisée sans retenir de mot de passe.

**Acceptance Criteria:**

**Given** un visiteur sur la surface "Me connecter",
**When** il soumet une adresse email valide,
**Then** les tables `users` (colonnes : id, email, first_name, last_name, phone, **last_login_at NULLABLE**, created_at) et `access_tokens` étant disponibles, un token d'accès haché, à usage unique et expirant (ex. 15 min — NFR4) est généré via `TokenService`,
**And** un email contenant le Magic Link est envoyé via `EmailService` et journalisé dans `email_events` (table créée ici, au premier envoi d'email),
**And** un message neutre confirme l'envoi sans révéler si un compte existait ou non (NFR5, UX-DR18).

**Given** une soumission avec un email invalide,
**When** le formulaire est envoyé,
**Then** une erreur liée au champ s'affiche et les valeurs saisies sont conservées (UX-DR27).

### Story 1.4 : Valider le Magic Link et créer la session PHP globale

As an utilisateur ayant cliqué sur le Magic Link,
I want que mon clic valide le lien, m'identifie (ou crée mon compte) et démarre une session,
So that je sois connecté et prêt à naviguer sur l'application.

**Acceptance Criteria:**

**Given** un Magic Link valide reçu par email,
**When** l'utilisateur clique dessus,
**Then** `TokenService` valide le token, vérifie l'expiration et l'invalide pour empêcher toute réutilisation (usage unique — NFR4),
**And** le système crée l'utilisateur dans `users` si l'email est inconnu,
**And** `users.last_login_at` est mis à jour avec l'horodatage courant (ce champ distingue les comptes ayant validé au moins un Magic Link de ceux créés implicitement par une inscription publique),
**And** une session PHP globale est établie,
**And** l'utilisateur est redirigé vers la page d'accueil (`/`), sauf si le lien portait une intention de redirection spécifique (ex. retour vers une kermesse précise).

**Given** un Magic Link expiré, déjà utilisé ou invalide,
**When** l'utilisateur clique dessus,
**Then** un message neutre indique que le lien n'est plus valide et propose d'en redemander un (UX-DR18), sans révéler l'existence d'un compte.

### Story 1.5 : Afficher l'accueil connecté (tableau de bord global) et gérer la déconnexion

As an utilisateur connecté,
I want voir la liste de mes kermesses sur la page d'accueil (`/`) et disposer d'un bouton de déconnexion,
So that je puisse gérer mes événements ou clôturer ma session sur un appareil partagé.

**Acceptance Criteria:**

**Given** un utilisateur avec une session active qui arrive sur la racine du site (`/`),
**When** la page se charge,
**Then** les tables `kermesses` et `kermesse_user_roles` étant disponibles, l'accueil connecté s'affiche à la place de l'accueil public (UX-DR13, UX-DR15),
**And** il liste les kermesses liées à l'utilisateur avec son rôle pour chacune,
**And** un bouton "Créer une nouvelle kermesse" est accessible,
**And** s'il n'a encore aucune kermesse, un état vide explicite est montré (le remplissage réel intervient à l'Epic 2).

**Given** un utilisateur connecté,
**When** il clique sur "Se déconnecter" (`POST /auth/logout`),
**Then** sa session PHP globale est détruite,
**And** il est redirigé vers l'accueil public.

## Epic 2 : Organisation et Lancement de Kermesse

Un organisateur crée une kermesse, définit ses besoins (stands, créneaux, capacités) et la publie pour les bénévoles.

### Story 2.1 : Créer une nouvelle kermesse

As an utilisateur (connecté ou non),
I want créer une kermesse avec un nom, une date, un lieu et une description courte,
So that je puisse commencer à configurer mon événement et en devenir le propriétaire.

**Acceptance Criteria:**

**Given** le formulaire de création de kermesse soumis par un visiteur non connecté,
**When** le système le traite,
**Then** la kermesse est créée à l'état "Préparation" et écrite dans `kermesses`,
**And** un compte Utilisateur est créé (infos organisateur),
**And** le rôle `Owner` lui est attribué dans `kermesse_user_roles`,
**And** un Magic Link de validation (avec intention de redirection vers la kermesse) est envoyé.

**Given** le formulaire de création soumis par un utilisateur connecté,
**When** le système le traite,
**Then** la kermesse est créée, l'utilisateur obtient immédiatement le rôle `Owner`,
**And** il est redirigé vers la section Modification du tableau de bord de la nouvelle kermesse.

### Story 2.2 : Ajouter et modifier des stands

As an Owner/Admin,
I want ajouter ou renommer des stands dans la section Modification,
So that la structure de l'événement soit claire pour les bénévoles.

**Acceptance Criteria:**

**Given** un utilisateur dont le rôle sur la kermesse est `Owner` ou `Admin` (vérifié en base via `RoleFilter` — NFR4),
**When** il ajoute un stand avec un nom valide,
**Then** la table `stands` étant disponible, le stand apparaît dans la liste et est prêt à recevoir des créneaux,
**And** un utilisateur sans ce rôle reçoit une erreur `unauthorized_role`.

**Given** un stand existant,
**When** l'Owner/Admin modifie son nom,
**Then** le nouveau nom est enregistré et reflété partout (UX-DR10).

### Story 2.3 : Ajouter et modifier des créneaux avec capacité

As an Owner/Admin,
I want ajouter des créneaux avec heure de début, heure de fin et capacité,
So that je définisse précisément mes besoins de volontariat.

**Acceptance Criteria:**

**Given** un stand existant,
**When** l'Owner/Admin soumet un créneau avec des horaires cohérents et une capacité entière strictement positive,
**Then** la table `slots` étant disponible, le créneau est enregistré et ses places disponibles sont affichées (UX-DR9),
**And** les horaires sont stockés et comparés dans le fuseau de la kermesse.

**Given** des horaires invalides ou une capacité < 1,
**When** l'Owner/Admin soumet le formulaire,
**Then** une erreur claire liée au champ empêche la création et conserve les valeurs saisies.

### Story 2.4 : Supprimer un stand avec sécurité destructive

As an Owner/Admin,
I want supprimer un stand avec une confirmation stricte s'il a des inscrits,
So that je corrige le planning sans détruire accidentellement des inscriptions.

**Acceptance Criteria:**

**Given** un stand sans aucune inscription active,
**When** l'Owner/Admin demande sa suppression,
**Then** une confirmation simple est demandée et le stand est supprimé.

**Given** un stand contenant des inscriptions actives (définition d'activité portée par `SignupService` ; effective une fois les inscriptions introduites à l'Epic 3),
**When** l'Owner/Admin demande sa suppression,
**Then** une confirmation forte exige la saisie exacte de `SUPPRIMER`, bouton désactivé jusqu'à correspondance (UX-DR12),
**And** après saisie correcte, le stand est supprimé et les inscriptions liées sont rendues inactives (elles ne comptent plus comme capacité active).

_Note d'implémentation : le test end-to-end du chemin "suppression avec inscrits actifs" requiert des fixtures d'inscription (Epic 3). Ce comportement est couvert par les tests d'intégration de Story 3.4 (voir AC dédié). En Epic 2, seul le chemin "sans inscription active" doit être couvert par les tests unitaires._

### Story 2.5 : Gérer l'état d'ouverture de la kermesse

As an Owner/Admin,
I want basculer l'état de la kermesse entre "Préparation", "Ouvert" et "Fermé",
So that je contrôle le moment où le planning devient public.

**Acceptance Criteria:**

**Given** une kermesse sans aucun stand pourvu d'au moins un créneau,
**When** l'Owner/Admin tente de passer à "Ouvert",
**Then** `KermesseLifecycleService` bloque l'action avec un message "Ajoutez au moins un stand avec un créneau avant d'ouvrir les inscriptions." (UX-DR26).

**Given** une kermesse avec au moins un stand et un créneau,
**When** l'Owner/Admin l'ouvre,
**Then** le statut passe à "Ouvert", le badge de statut est mis à jour et le planning devient visible pour le public,
**And** la fermeture rend le planning non interactif (ni inscription, ni désistement).

**Given** la section Modification du tableau de bord,
**When** l'Owner/Admin consulte le header de la kermesse (nom + badge de statut),
**Then** les actions `Prévisualiser` (ouvre la page bénévole) et `Copier le lien` (feedback "Lien copié.") sont disponibles aux côtés de `Ouvrir/Fermer` (UX-DR17).

## Epic 3 : Inscription Publique et Rattachement Automatique

Les bénévoles consultent le planning public et s'inscrivent en quelques gestes ; leur inscription s'attache automatiquement à leur compte Utilisateur, et toute divergence de coordonnées est résolue à leur prochaine connexion.

### Story 3.1 : Afficher la page publique de la kermesse

As a visiteur,
I want consulter la page de la kermesse avec ses stands, créneaux et places restantes,
So that je voie où mon aide est requise sans avoir à me connecter.

**Acceptance Criteria:**

**Given** une kermesse en état "Ouvert",
**When** le visiteur ouvre la page publique,
**Then** les stands et créneaux sont affichés avec les places restantes via des view models privacy-safe,
**And** aucune donnée personnelle de bénévole (nom, email, téléphone) n'est exposée (NFR5),
**And** les créneaux disponibles sont cliquables, les créneaux complets visibles mais désactivés (UX-DR9),
**And** un encart "Déjà inscrit ? Connectez-vous" redirige vers la demande de Magic Link.

**Given** une kermesse en état "Préparation" ou "Fermé",
**When** le visiteur ouvre la page publique,
**Then** le planning n'est pas interactif et un message explique le statut (UX-DR26).

### Story 3.2 : S'inscrire à un créneau (visiteur non connecté) et rattachement par email

As a visiteur non connecté,
I want m'inscrire en fournissant email, prénom, nom et téléphone (facultatif), avec pré-remplissage si je viens de m'inscrire ailleurs,
So that ma réservation soit fluide et automatiquement rattachée à mon email.

**Acceptance Criteria:**

**Given** un créneau disponible cliqué pour la première fois,
**When** le formulaire s'ouvre,
**Then** il résume le stand et le créneau en haut puis collecte prénom, nom, email et téléphone facultatif (UX-DR21, UX-DR24).

**Given** un visiteur qui vient de s'inscrire à un premier créneau dans la même session de navigation,
**When** il ouvre le formulaire d'un autre créneau,
**Then** ses informations (email, prénom, nom) sont pré-remplies.

**Given** la soumission d'une inscription valide sur un créneau disponible,
**When** `SlotSignupService.createSignup()` la traite dans une transaction,
**Then** la table `signups` (colonnes : id, slot_id, user_id, **first_name, last_name, email, phone** [snapshot coordonnées soumises], created_at — **pas de champ `status` stocké** : le statut est calculé à la volée depuis les timestamps `accepted_at`, `rejected_at`, `canceled_at`) étant disponible, un compte Utilisateur est créé si l'email est inconnu et l'inscription est rattachée à cet email,
**And** la capacité restante est vérifiée de façon transactionnelle (rejet `slot_full` si pleine — NFR3),
**And** si les informations soumises diffèrent du profil existant, le **snapshot** est conservé tel quel dans les colonnes `signups.first_name/last_name/email/phone` ; aucune table `profile_divergences` n'est créée — la réconciliation est stateless et sera effectuée à la première connexion du bénévole (Story 5.4) (FR8/FR13),
**And** `RoleService` crée ou confirme une entrée `Bénévole` dans `kermesse_user_roles` pour cet utilisateur et cette kermesse (permettant à la kermesse d'apparaître dans l'accueil connecté de l'utilisateur).

> ⚠️ **[COMPORTEMENT MODIFIÉ par Story 5.14 — Modif 3.2]** : L'AC ci-dessus décrit le comportement initial. **Implémenter directement Story 5.14 AC-1** : pour un visiteur non connecté (email inconnu ou connu), `user_id = NULL`, `created_by = NULL` — aucun compte n'est créé silencieusement. `RoleService` ne crée une entrée `Bénévole` que si `user_id` est non nul. Le rattachement au compte se fait lors de la première connexion via `resolveOrphanSignups` (Story 1.4 / Story 5.14 AC-2).

**Given** la soumission avec des données de formulaire invalides (email malformé, prénom/nom vide),
**When** le formulaire est envoyé,
**Then** des erreurs sont liées aux champs correspondants et les valeurs saisies sont conservées (UX-DR27).

### Story 3.3 : S'inscrire à un créneau (utilisateur connecté)

As an utilisateur connecté,
I want m'inscrire à un créneau sans ressaisir mes informations,
So that l'expérience soit instantanée.

**Acceptance Criteria:**

**Given** un utilisateur avec une session globale active sur la page de la kermesse,
**When** il clique sur un créneau disponible,
**Then** le système le reconnaît et l'inscription se fait sans redemander nom ni email (1-clic, ou formulaire pré-rempli et verrouillé),
**And** l'inscription est rattachée à son compte et soumise aux mêmes invariants serveur que l'inscription publique.

### Story 3.4 : Garantir doublon, chevauchement et course à la capacité (transactionnel)

As an organisateur,
I want que le système bloque techniquement toute inscription en conflit ou en dépassement,
So that mon planning reste d'une fiabilité absolue.

**Acceptance Criteria:**

**Given** un créneau avec une seule place restante,
**When** deux utilisateurs tentent de s'inscrire exactement en même temps,
**Then** la transaction n'en accepte qu'un seul,
**And** le second reçoit un message clair "Ce créneau vient d'être rempli." (`slot_full` — UX-DR26).

**Given** un Utilisateur tentant de s'inscrire à un créneau,
**When** il est déjà inscrit à ce même créneau (doublon) ou à un autre créneau dont l'horaire chevauche celui-ci,
**Then** l'inscription est refusée avec un message explicatif (`duplicate_signup` / `overlap_conflict`).

**Given** ces invariants critiques,
**When** la story est livrée,
**Then** des tests automatisés couvrent la course à la capacité, le doublon et le chevauchement (tests obligatoires avant de s'appuyer sur le flux).

**Given** un stand supprimé contenant des inscriptions actives (test d'intégration Story 2.4),
**When** la suppression est confirmée via `SignupService`,
**Then** toutes les inscriptions liées au stand sont marquées inactives et ne comptent plus dans la capacité active (couverture end-to-end du chemin "suppression forte" de Story 2.4).

### Story 3.5 : Envoyer l'email de confirmation bénévole

As a bénévole inscrit,
I want recevoir un email de confirmation contenant un Magic Link vers la gestion de mes participations,
So that je garde une trace et puisse gérer mon inscription plus tard.

**Acceptance Criteria:**

**Given** une inscription validée,
**When** le processus se termine,
**Then** un email est envoyé à l'adresse de l'utilisateur via `EmailService` et l'événement est journalisé dans `email_events`,
**And** l'email contient les détails de l'inscription et un Magic Link (avec redirection vers le tableau de bord),
**And** la confirmation à l'écran indique que l'inscription est confirmée et qu'un email a été envoyé ; si la livraison est inconnue, l'inscription reste confirmée et l'UI explique quoi faire (UX-DR22).

### Story 3.6 : [DÉPRÉCIÉE] Résoudre les divergences de profil à la connexion

*Note : Cette story a été dépréciée lors d'un "Correct Course" (Architecture Stateless). La table `profile_divergences` a été supprimée au profit d'une approche stateless (le nom saisi reste stocké sur l'inscription comme snapshot, et le parsing est fait à la volée lors de la première connexion - voir Story 5.4).*

## Epic 4 : Gestion Opérationnelle, Délégation et Autonomie Bénévole

Les bénévoles libèrent leur place depuis leur espace connecté, les administrateurs suivent le remplissage par stand/créneau, et un Owner/Admin peut inviter une équipe de gestion.

### Story 4.1 : Afficher le tableau de bord interne par rôle

As an utilisateur accédant à une kermesse,
I want voir uniquement les sections (Modification, Gestion des participants, Mes participations) correspondant à mon rôle,
So that je n'aie accès qu'aux outils qui me concernent.

**Acceptance Criteria:**

**Given** un utilisateur connecté accédant au tableau de bord d'une kermesse,
**When** le système vérifie son rôle dans `kermesse_user_roles` (via `RoleFilter`, côté serveur — NFR4),
**Then** la section "Modification" n'est rendue que pour `Owner`/`Admin`,
**And** la section "Gestion des participants" n'est rendue que pour `Owner`/`Admin`/`Gestionnaire`,
**And** la section "Mes participations" est rendue pour tout rôle,
**And** une section non autorisée est absente de la page, pas seulement désactivée (UX-DR16).

### Story 4.2 : Afficher "Mes participations" (bénévole)

As a bénévole,
I want consulter la liste de toutes mes inscriptions actives pour cette kermesse,
So that je me rappelle de mes engagements.

**Acceptance Criteria:**

**Given** la section "Mes participations" du tableau de bord,
**When** l'utilisateur l'ouvre,
**Then** il voit la liste de ses inscriptions actives (nom du stand, date, horaires de début et de fin),
**And** seules les inscriptions actives apparaissent (les inscriptions annulées en sont exclues — UX-DR23).

### Story 4.3 : Annuler une inscription (se désister)

As a bénévole,
I want supprimer mon inscription depuis "Mes participations",
So that je libère immédiatement la place dans le planning public en cas d'imprévu.

**Acceptance Criteria:**

**Given** une inscription active dans "Mes participations" alors que les inscriptions sont ouvertes,
**When** le bénévole clique sur annuler et confirme,
**Then** `SlotSignupService.cancelSignup()` marque l'inscription comme annulée/inactive,
**And** la place redevient immédiatement disponible sur le planning public,
**And** l'inscription disparaît de la liste active avec le message "La place est de nouveau disponible." (UX-DR23).

**Given** une kermesse fermée,
**When** le bénévole tente d'annuler,
**Then** l'action est indisponible avec le message "Les inscriptions sont fermées." (`signups_not_open`).

### Story 4.4 : Afficher la "Gestion des participants" (Admin/Gestionnaire)

As an Owner/Admin/Gestionnaire,
I want un récapitulatif par stand et créneau affichant l'identité et le contact des bénévoles inscrits,
So que je suive le remplissage opérationnel et contacte les volontaires si besoin.

**Acceptance Criteria:**

**Given** la section "Gestion des participants" ouverte par un rôle autorisé,
**When** elle se charge,
**Then** chaque stand et chaque créneau s'affiche avec places occupées et restantes,
**And** pour chaque place occupée, le nom, prénom et téléphone du bénévole sont affichés de façon lisible (UX-DR24),
**And** ces données personnelles ne sont accessibles qu'après autorisation du rôle (jamais en public — NFR5),
**And** la vue utilise la même définition d'inscription active que le planning public.

### Story 4.5 : Inviter des administrateurs et gestionnaires

As an Owner/Admin,
I want inviter une personne en tant qu'Admin ou Gestionnaire via son email, prénom et nom,
So that je délègue la gestion de ma kermesse à une équipe de confiance.

**Acceptance Criteria:**

**Given** la section "Gestion des participants" ouverte par un `Owner` ou `Admin`,
**When** il soumet le formulaire d'invitation avec un prénom, nom, email et un rôle (`Admin` ou `Gestionnaire`),
**Then** `RoleService` crée un compte Utilisateur complet si l'email est inconnu (le prénom et nom sont désormais obligatoires pour ne pas créer de profils fantômes),
**And** le rôle demandé est attribué à cet utilisateur pour cette kermesse dans `kermesse_user_roles`,
**And** un email d'invitation contenant un Magic Link vers la kermesse lui est envoyé (journalisé dans `email_events`),
**And** l'UI confirme que l'invitation a été envoyée (UX-DR20).

## Epic 5 : Post-MVP — Identité avancée et délégation [TERMINÉ]

Vague d'évolutions conçue **après** la livraison du MVP (Epics 1-4). Cet epic regroupe deux familles de stories : les **stories de modification** (deltas à des comportements déjà livrés — elles ne réécrivent pas l'historique des stories MVP, elles les font évoluer) et les **nouvelles fonctionnalités**. Couvre FR-15→18 ainsi que les extensions de FR-10 (4 onglets) et FR-13 (confirmation à la 1ʳᵉ connexion).

**FRs covered:** FR-10 (extension), FR-13 (extension), FR-15, FR-16, FR-17, FR-18

> **Pré-requis transverses** : Story 5.1 (colonnes `signups.last_modified_*`) et Story 5.2 (colonnes `kermesse_user_roles.first_access_at`/`last_access_at` + écriture au chargement) conditionnent plusieurs stories de cet epic (5.3, 5.5, 5.8, 5.10–5.12).

### Story 5.1 : [Modif 3.2] Tracer les modifications d'inscription

As an Owner/Admin/Gestionnaire,
I want que toute correction d'une inscription soit horodatée et attribuée,
So that la « Gestion des inscrits » indique qui a modifié quoi.

**Acceptance Criteria:**

**Given** la migration de cet epic,
**When** elle s'applique,
**Then** la table `signups` gagne les colonnes `last_modified_by_user_id` (NULLABLE) et `last_modified_at` (NULLABLE),
**And** elles sont renseignées par toute action de modification admin (Stories 5.3, 5.10, 5.11, 5.12),
**And** elles restent NULL pour une inscription jamais modifiée par un admin.

### Story 5.2 : [Modif 4.1] Navigation par 4 onglets + suivi d'accès par kermesse

As an utilisateur accédant à une kermesse,
I want naviguer entre les onglets du tableau de bord selon mon rôle,
So que j'accède rapidement à l'outil voulu sans parcourir une page interminable.

**Acceptance Criteria:**

**Given** un utilisateur connecté accédant au tableau de bord,
**When** le système vérifie son rôle (`RoleFilter`, côté serveur — NFR4),
**Then** jusqu'à 4 onglets s'affichent, chacun visible pour les seuls rôles autorisés — **[Modification]** (Owner/Admin), **[Gestion des inscrits]** (Owner/Admin/Gestionnaire), **[Équipe]** (Owner/Admin), **[Mes participations]** (tous rôles),
**And** le premier onglet autorisé est actif par défaut,
**And** un onglet non autorisé est absent (pas seulement désactivé — UX-DR16),
**And** en mobile les onglets sont des raccourcis-pilules scrollables ; en desktop une barre horizontale.

**Given** la migration de cet epic,
**When** elle s'applique,
**Then** `kermesse_user_roles` gagne `first_access_at` (NULLABLE) et `last_access_at` (NULLABLE).

**Given** un utilisateur chargeant le tableau de bord pour la première fois (`first_access_at IS NULL`),
**When** la page se charge,
**Then** **avant tout rendu**, si une validation est requise elle est déclenchée (Story 5.4) — sinon aucun écran,
**And** `RoleService` pose ensuite `first_access_at` (ce qui **bascule le verrou d'édition admin** — Story 5.10),
**And** `last_access_at` est mis à jour à **chaque** chargement (suivi d'activité, pas d'UI pour le moment).

> **Note de schéma** : `first_access_at` (par kermesse) sert à l'indicateur « invitation en attente » (Story 5.5/5.8) **et** au verrou d'édition admin (Story 5.10). Il **remplace** l'ancien `accepted_at` (quick-fix limité au chemin Magic Link). Le champ **global** `users.last_login_at` ne verrouille rien : il choisit seulement *quel* écran présenter au 1ᵉʳ accès (Story 5.4). C'est Story 5.2 qui **possède** le hook « 1ᵉʳ accès → validation puis pose de `first_access_at` ».

### Story 5.3 : [Modif 4.4] Renommer « Gestion des inscrits » + badge de modification

As an Owner/Admin/Gestionnaire,
I want voir qui a modifié une inscription dans la liste des inscrits,
So that je garde la traçabilité des corrections.

**Acceptance Criteria:**

**Given** la section « Gestion des participants »,
**When** l'onglet est rendu (Story 5.2),
**Then** elle est renommée **« Gestion des inscrits »**,
**And** pour chaque inscription dont `last_modified_by_user_id` est renseigné, un badge discret indique « Modifié par [Prénom admin] le [date] ».

### Story 5.4 : [Modif 3.6] Confirmation obligatoire à la 1ʳᵉ connexion + réconciliation par-kermesse

As an utilisateur,
I want confirmer mes coordonnées à ma toute première connexion, puis ne réconcilier qu'en cas de divergence,
So that mon profil m'appartient même si un tiers m'a inscrit, sans être dérangé inutilement.

**Acceptance Criteria:**

**Given** un Utilisateur à sa toute première connexion (`users.last_login_at IS NULL`),
**When** il valide son Magic Link / accède à une kermesse,
**Then** un écran de **confirmation des coordonnées** (prénom/nom/email/téléphone) est présenté **quoi qu'il arrive**, à confirmer/corriger avant de continuer.

**Given** un Utilisateur déjà actif (`users.last_login_at IS NOT NULL`),
**When** il accède à une kermesse dont les données d'inscription/invitation divergent de son profil,
**Then** la surface Profile resolution présente les valeurs divergentes et exige un choix explicite (UX-DR19), puis met à jour le profil unique.

**Given** un Utilisateur déjà actif **sans** divergence,
**When** il accède à une kermesse,
**Then** aucun écran n'est présenté (verrou admin basculé silencieusement — Story 5.10).

### Story 5.5 : [Modif 4.5] Onglet « Équipe » — vue des membres, état d'invitation, réinvitation

As an Owner/Admin,
I want voir la composition de mon équipe et les invitations en attente depuis l'onglet « Équipe »,
So that je pilote la délégation avec une vue d'ensemble.

**Acceptance Criteria:**

**Given** l'onglet « Équipe » (Owner/Admin),
**When** il se charge,
**Then** les membres actifs s'affichent groupés par rôle (Owner/Admin/Gestionnaire),
**And** les invitations en attente (`invited_at` non null, `first_access_at` null) apparaissent en section « En attente ».

**Given** un email correspondant à un utilisateur qui possède déjà le rôle demandé,
**When** l'invitation est soumise,
**Then** aucun doublon n'est créé et le message « Cet utilisateur a déjà le rôle [Admin/Gestionnaire] sur cette kermesse. » s'affiche (`already_has_role`).

**Given** un ancien membre ayant déjà accédé à la kermesse (`first_access_at` non null) puis révoqué/parti,
**When** il est réinvité,
**Then** son rôle élevé est restauré sans réinitialiser `first_access_at` (il ne réapparaît pas « en attente »).

**Given** l'utilisateur connecté figurant dans la liste des membres (rôle Admin ou Gestionnaire),
**When** il consulte l'onglet « Équipe »,
**Then** sa propre ligne affiche un badge « Vous » pour le distinguer visuellement,
**And** le bouton 🗑️ de révocation est absent sur sa propre ligne — la règle métier interdit l'auto-révocation (supprimer la tentation > l'interdire),
**And** si `canLeave = true` (aucune inscription active), un bouton « ↪️ Quitter » lui permet d'accéder directement au flux de départ (route `/kermesse/{id}/leave`) depuis l'onglet Équipe, en complément de l'entrée « Mes participations ».

**Given** une invitation acceptée par un nouveau membre (premier accès au dashboard),
**When** l'activation est confirmée,
**Then** le Owner reçoit un email de notification `team_change_notification` (action : `joined`) indiquant qui a rejoint, son rôle, et qui l'avait invité.

> **Note d'implémentation** : l'auto-révocation est bloquée à deux niveaux — UI (bouton absent) et backend (`KermesseAdminController::removeTeamMember()` retourne un 302 avec message d'erreur si `$userId === session user_id`). La détection côté vue repose sur `currentUserId` passé dans le view model par le contrôleur.

### Story 5.6 : Dupliquer un stand

As an Owner/Admin,
I want dupliquer un stand existant en choisissant un nouveau nom immédiatement,
So que je reproduise rapidement la structure de créneaux d'un stand sans tout ressaisir.

**Acceptance Criteria:**

**Given** un stand existant dans la section Modification,
**When** l'Owner/Admin clique sur "Dupliquer",
**Then** une boîte de dialogue demande le nom du nouveau stand,
**And** le bouton de validation est désactivé tant que le nom est vide.

**Given** un nom valide soumis,
**When** la duplication est confirmée,
**Then** un nouveau stand est créé avec ce nom,
**And** tous les créneaux du stand source (horaires + capacités) sont copiés,
**And** aucune inscription n'est copiée (le nouveau stand part avec zéro inscrit),
**And** le nouveau stand apparaît immédiatement dans la liste.

**Given** un stand source sans aucun créneau,
**When** la duplication est confirmée avec un nom valide,
**Then** le nouveau stand est créé vide — cas valide, l'admin ajoutera les créneaux ensuite.

### Story 5.7 : Gérer ses propres coordonnées (page profil)

As an utilisateur connecté,
I want une page profil où je modifie moi-même mon prénom, nom, email et téléphone,
So that je reste le seul maître de mes coordonnées une fois validées.

> **Note** : cette page existe déjà en code (`GET/POST /profile` → `ProfileController`, quick win) ; cette story la formalise et la relie au modèle de propriété de l'identité (Stories 5.4 / 5.10).

**Acceptance Criteria:**

**Given** un utilisateur connecté,
**When** il ouvre `/profile`,
**Then** ses coordonnées (prénom, nom, email, téléphone) sont affichées dans un formulaire éditable, modifiables et enregistrables (PRG + confirmation).

**Given** un utilisateur avec une résolution de profil en attente,
**When** il tente d'accéder à `/profile`,
**Then** il est d'abord redirigé vers la résolution (filtre `pending-resolution`).

**Given** un utilisateur qui modifie son email,
**When** il enregistre,
**Then** l'unicité de l'email est validée et le profil unique est mis à jour,
**And** c'est la **seule** voie pour corriger des coordonnées verrouillées côté admin (Story 5.10).

### Story 5.8 : Onglet « Équipe » — Révoquer un rôle

As an Owner ou Admin,
I want pouvoir retirer le rôle d'un Admin ou Gestionnaire depuis l'onglet "Équipe",
So that je contrôle précisément qui a accès à la gestion de ma kermesse.

**Acceptance Criteria:**

**Given** l'onglet "Équipe" affiché à un `Owner` ou `Admin`,
**When** il demande la révocation du rôle d'un `Admin` ou `Gestionnaire`,
**Then** une confirmation simple est demandée avant d'effectuer l'action.

**Given** la révocation confirmée d'un utilisateur (avec ou sans inscriptions actives),
**When** `RoleService` traite la révocation,
**Then** le rôle élevé (Admin/Gestionnaire) est remplacé par le rôle `Bénévole` dans `kermesse_user_roles`,
**And** ses inscriptions actives sont conservées et apparaissent toujours dans "Gestion des participants",
**And** l'utilisateur perd l'accès aux sections "Modification" et "Gestion des participants" à sa prochaine visite,
**And** la kermesse reste visible dans son accueil connecté (section "Mes participations" uniquement),
**And** l'utilisateur peut ensuite se retirer lui-même via Story 5.9 s'il le souhaite,
**And** le Owner reçoit un email de notification `team_change_notification` (action : `removed`) identifiant le membre révoqué, son ancien rôle, et l'acteur ayant effectué la révocation — y compris si c'est le Owner lui-même.

**Given** une tentative de révocation du rôle `Owner`,
**When** l'action est soumise,
**Then** elle est refusée (`unauthorized_role`) — le rôle Owner n'est pas révocable dans le MVP.

**Given** un `Admin` tentant de révoquer un autre `Admin` ou un `Gestionnaire`,
**When** l'action est soumise,
**Then** elle est autorisée (symétrie avec le droit d'invitation de Story 5.5).

**Given** un `Admin` ou `Gestionnaire` tentant de révoquer son propre rôle via le formulaire de révocation,
**When** l'action est soumise,
**Then** elle est refusée avec un message d'erreur orientant vers le flux « Quitter l'organisation » (Story 5.9) — guard backend indépendant du masquage UI.

**Given** un utilisateur révoqué qui avait une invitation en attente (invited_at non null, first_access_at null),
**When** la révocation est traitée par `RoleService`,
**Then** l'invitation en attente est annulée (statut mis à jour ou entrée supprimée) et n'apparaît plus dans "Invitations en attente" de l'onglet Équipe.

> **Note d'implémentation** : `RoleService::removeRole()` reçoit un troisième paramètre `$actorId` (int) pour identifier l'auteur de la révocation dans la notification email et dans le champ `metadata` de `email_events`. La notification est envoyée après commit (fire-and-forget) — un échec d'envoi est tracé dans `email_events` mais ne rollback jamais la révocation.

### Story 5.9 : Quitter une kermesse

As an utilisateur (Bénévole, Admin ou Gestionnaire),
I want pouvoir me retirer complètement d'une kermesse depuis mon tableau de bord ou mon accueil connecté,
So that mon accueil connecté ne liste que les kermesses auxquelles je participe encore.

**Acceptance Criteria:**

**Given** un utilisateur sans inscription active sur la kermesse,
**When** il choisit "Quitter cette kermesse" (accessible depuis la section "Mes participations", la carte kermesse dans l'accueil connecté, **ou le bouton « ↪️ Quitter » sur sa propre ligne de l'onglet « Équipe »**),
**Then** une confirmation simple est demandée ("Voulez-vous quitter cette kermesse ? Le propriétaire sera notifié."),
**And** après confirmation, son entrée dans `kermesse_user_roles` est supprimée,
**And** la kermesse disparaît de son accueil connecté,
**And** le Owner reçoit un email de notification `team_change_notification` (action : `left`) indiquant le membre partant et son ancien rôle.

**Given** un utilisateur ayant encore des inscriptions actives sur la kermesse,
**When** il consulte "Mes participations" ou sa carte kermesse dans l'accueil connecté,
**Then** l'action "Quitter cette kermesse" est absente (non rendue) — elle n'est visible que lorsqu'aucune inscription active n'existe.

**Given** un `Owner` tentant de quitter sa kermesse,
**When** l'action est soumise,
**Then** elle est refusée avec le message "En tant que propriétaire, vous ne pouvez pas quitter cette kermesse." (`unauthorized_role`).

> **Note d'implémentation** : la notification est envoyée après le DELETE de la ligne `kermesse_user_roles`, de façon non bloquante. L'acteur de la notification est le membre lui-même (pas un tiers). Le message de confirmation en UI mentionne explicitement que le propriétaire sera notifié.


---

## Epic 6 : Stabilisation et préparation à la production

Les organisateurs et bénévoles peuvent utiliser les parcours critiques de Kermesse sans régression connue ; l'équipe dispose de preuves automatisées reproductibles sur PHPUnit, MariaDB et navigateur réel, puis qualifie l'artefact immuable destiné à Ouvaton par une décision Go/No-Go fondée sur des preuves.

### Story 6.1 : Corriger l'ajout d'un créneau sur une kermesse ouverte

As an Owner ou Admin,
I want ajouter un créneau pendant que ma kermesse est ouverte,
So that je puisse adapter le planning sans interrompre les inscriptions.

**Acceptance Criteria:**

**Given** une kermesse au statut `open`, contenant un stand actif,
**When** un Owner soumet un créneau valide,
**Then** la requête suit le pattern PRG et redirige vers le stand concerné,
**And** le créneau est persisté avec les horaires, la capacité et le statut attendus,
**And** il apparaît après rechargement du dashboard et sur la page publique.

**Given** le même contexte,
**When** un Admin soumet le formulaire,
**Then** il obtient le même résultat que le Owner.

**Given** une soumission valide sur une kermesse ouverte,
**When** le test de non-régression est exécuté avant correction,
**Then** il reproduit la cause réelle de l'erreur observée,
**And** la correction n'est appliquée qu'après obtention de ce test rouge.

**Given** une saisie invalide,
**When** le formulaire est soumis,
**Then** aucun créneau n'est créé,
**And** les valeurs saisies sont conservées,
**And** une erreur explicite est affichée sans exception non gérée.

**Given** un Gestionnaire, un Bénévole ou un utilisateur non authentifié,
**When** il tente directement le POST,
**Then** l'accès est refusé côté serveur,
**And** aucune écriture n'est effectuée.

**Given** un stand appartenant à une autre kermesse ou désactivé,
**When** l'ajout est tenté,
**Then** la requête est refusée sans écriture inter-kermesse.

**Given** la correction implémentée,
**When** les tests sont exécutés sous SQLite et MariaDB,
**Then** le scénario d'ajout sur une kermesse ouverte passe dans les deux environnements.

**Given** les contraintes architecturales du projet,
**When** le flux d'écriture est corrigé,
**Then** l'écriture passe par un Service et un DTO `readonly`,
**And** le contrôleur reste limité à la validation et à l'orchestration HTTP.

### Story 6.2 : Cartographier les parcours critiques et leur couverture

As an équipe de livraison,
I want disposer d'un contrat de couverture fondé sur les risques,
So that aucun parcours critique ne soit oublié avant la mise en production.

**Acceptance Criteria:**

**Given** les FR1 à FR18, les routes, rôles et états métier existants,
**When** la cartographie est réalisée,
**Then** chaque parcours est décrit selon `surface × état × rôle × identité × action × résultat`,
**And** les états réels sont `preparation`, `open` et `closed`,
**And** les rôles réels sont Anonyme, Bénévole, Gestionnaire, Admin et Owner.

**Given** les parcours inventoriés,
**When** leur risque est évalué,
**Then** chacun est classé P0 (sécurité, autorisation, perte ou corruption de données), P1 (parcours métier indispensable ou régression visible) ou P2 (variante secondaire ou cosmétique).

**Given** une cellule de la matrice,
**When** sa couverture est analysée,
**Then** elle référence précisément un test PHPUnit, MariaDB ou navigateur existant,
**Or** elle est marquée `manquante`, `manuelle justifiée` ou `non applicable`, avec justification.

**Given** les fonctionnalités livrées jusqu'à l'Epic 5,
**When** la matrice est complète,
**Then** elle couvre au minimum l'authentification et les Magic Links, la création et le cycle de vie d'une kermesse, les stands et créneaux, l'inscription publique, les participations du bénévole, la gestion administrative des inscriptions, l'équipe et les rôles, le profil et l'identité, la confidentialité des vues publiques, ainsi que les migrations et le déploiement.

**Given** les trois régressions connues,
**When** elles sont reportées dans la matrice,
**Then** elles sont classées P1 au minimum,
**And** leur mécanisme — JavaScript, identité courante ou état `open` — est explicitement identifié.

**Given** les cellules P0/P1,
**When** le document est finalisé,
**Then** chacune possède un niveau de test cible et un propriétaire,
**And** les lacunes sont reliées aux Stories 6.3 à 6.7 sans attendre leur implémentation.

**Given** le document de cartographie,
**When** une nouvelle régression est découverte,
**Then** elle peut être ajoutée avec son test de non-régression et sa classification sans restructurer l'ensemble.

La Story produit un artefact versionné dans le dépôt et utilisable comme checklist de Go/No-Go.

### Story 6.3 : Automatiser les trois régressions connues avec Playwright

As an équipe de livraison,
I want exécuter les parcours critiques dans un vrai navigateur,
So that les régressions JavaScript et d'intégration soient détectées avant fusion.

**Acceptance Criteria:**

**Given** le dépôt Kermesse,
**When** l'environnement E2E est installé,
**Then** Playwright est une dépendance de développement uniquement,
**And** aucun build JavaScript n'est requis en production,
**And** les fichiers Playwright et Node sont exclus de l'archive Ouvaton.

**Given** la configuration Playwright,
**When** les tests sont exécutés,
**Then** deux profils Chromium sont disponibles : mobile et desktop,
**And** les sélecteurs utilisent prioritairement les rôles accessibles, libellés ou `data-testid` stables.

**Given** une machine de développement ou un runner CI disposant de Docker,
**When** la suite Playwright est lancée,
**Then** elle s'exécute dans un service Docker dédié basé sur une image Playwright officielle dont la version est épinglée et alignée avec la dépendance du projet,
**And** aucun runtime Node, navigateur ou dépendance Playwright n'est requis sur la machine hôte,
**And** la même image et la même commande sont utilisées localement et en CI.

**Given** l'environnement Docker E2E,
**When** les scénarios démarrent,
**Then** l'application et MariaDB sont accessibles via le réseau interne Docker,
**And** des contrôles de santé empêchent le lancement des tests avant leur disponibilité,
**And** les traces, captures d'écran et rapports sont écrits dans un volume récupérable depuis l'hôte ou la CI.

**Given** les données nécessaires aux scénarios,
**When** les fixtures sont préparées,
**Then** elles utilisent des données fictives et reproductibles,
**And** elles ne dépendent jamais de la production,
**And** aucun endpoint de préparation de test n'est accessible hors environnement `testing`.

**Given** un bénévole possédant une ou plusieurs inscriptions actives,
**When** il ouvre « Mes participations »,
**Then** toutes ses inscriptions sont visibles après l'initialisation JavaScript,
**And** elles restent visibles après rechargement complet.

**Given** un Admin connecté et un autre membre de l'équipe,
**When** l'onglet Équipe est affiché,
**Then** la ligne de l'utilisateur courant porte l'indication « Vous »,
**And** les actions interdites sur lui-même sont absentes,
**And** l'autre membre conserve les actions autorisées.

**Given** une kermesse ouverte et un stand actif,
**When** un Owner ou Admin ajoute un créneau depuis l'interface,
**Then** le succès est visible,
**And** le créneau apparaît dans le dashboard,
**And** il persiste après rechargement,
**And** il apparaît sur la page publique.

**Given** l'exécution d'un scénario E2E,
**When** une exception JavaScript, une réponse HTTP inattendue ou une erreur console non autorisée survient,
**Then** le test échoue.

**Given** un échec Playwright en CI,
**When** les artefacts sont collectés,
**Then** la trace, la capture d'écran et les journaux navigateur sont conservés pour diagnostic.

**Given** les trois scénarios,
**When** la suite smoke est exécutée plusieurs fois,
**Then** aucun test flaky n'est toléré avant son passage en gate bloquant.

### Story 6.4 : Automatiser les parcours critiques du bénévole

> **[TERMINÉ]**


As a bénévole,
I want que mes parcours publics et connectés soient validés de bout en bout,
So that je puisse m'inscrire et gérer mes participations sans régression.

**Acceptance Criteria:**

**Given** une kermesse dans chacun des états `preparation`, `open` et `closed`,
**When** un visiteur ouvre sa page publique,
**Then** les informations et actions correspondent à l'état,
**And** aucune donnée personnelle de bénévole, d'admin ou Magic Link n'est exposée.

**Given** une kermesse ouverte avec un créneau disponible,
**When** un visiteur réalise une inscription valide,
**Then** la confirmation visible contient le stand et le créneau,
**And** la place restante est mise à jour après rechargement.

**Given** un créneau complet, un doublon ou un chevauchement,
**When** une inscription est tentée,
**Then** le message métier attendu est visible,
**And** aucune inscription supplémentaire n'est créée.

**Given** un email associé à une inscription orpheline,
**When** l'utilisateur demande puis utilise un Magic Link,
**Then** le compte est créé ou retrouvé selon le contrat FR2,
**And** les inscriptions sont rattachées,
**And** elles apparaissent dans « Mes participations ».

**Given** une inscription non encore confirmée par son titulaire,
**When** le bénévole la consulte,
**Then** les actions « Confirmer » et « Refuser » apparaissent conformément à FR13,
**And** chacune produit le statut calculé et l'affichage attendus après rechargement.

**Given** une inscription active et une kermesse ouverte,
**When** le bénévole l'annule,
**Then** elle disparaît de la liste active,
**And** la place redevient disponible sur la page publique,
**And** `canceled_at` et `canceled_by` sont renseignés.

**Given** une kermesse fermée,
**When** le bénévole consulte ses participations,
**Then** les actions interdites sont absentes,
**And** une explication compréhensible est affichée.

**Given** un utilisateur connecté,
**When** il consulte puis modifie son profil,
**Then** ses informations sont persistées,
**And** les validations et erreurs conservent les valeurs saisies.

**Given** les scénarios bénévoles P0/P1 de la matrice,
**When** la Story est terminée,
**Then** chaque scénario nécessitant un navigateur possède un test Playwright,
**And** les règles mieux couvertes au niveau PHPUnit y restent référencées sans duplication E2E inutile.

**Given** les contraintes mobile-first,
**When** la suite est exécutée avec le profil mobile,
**Then** les actions principales restent accessibles à 320 px,
**And** aucun contenu critique ne nécessite de défilement horizontal.

### Story 6.5 : Automatiser les parcours critiques des organisateurs

As an Owner, Admin ou Gestionnaire,
I want que mes opérations de gestion soient validées selon mon rôle,
So that je puisse piloter la kermesse sans erreur de permission ni corruption du planning.

**Acceptance Criteria:**

**Given** un Owner configurant une nouvelle kermesse,
**When** il crée les stands et créneaux puis ouvre les inscriptions,
**Then** chaque transition réussit,
**And** les données configurées restent visibles et cohérentes après rechargement.

**Given** une kermesse ouverte puis fermée,
**When** l'Owner ou l'Admin change son état,
**Then** les actions publiques et administratives autorisées correspondent immédiatement au nouvel état,
**And** les données existantes sont conservées.

**Given** un Gestionnaire connecté,
**When** il ouvre le dashboard,
**Then** il accède à « Gestion des inscrits » et « Mes participations »,
**And** les onglets Modification et Équipe sont absents,
**And** les accès directs correspondants sont refusés côté serveur.

**Given** un Owner, Admin ou Gestionnaire autorisé,
**When** il ajoute, corrige, déplace ou annule une inscription,
**Then** l'opération respecte les invariants de capacité, doublon et chevauchement,
**And** son résultat persiste après rechargement,
**And** les historiques et traces d'acteur sont corrects.

**Given** un Owner ou Admin dans l'onglet Équipe,
**When** il invite, réinvite ou révoque un membre,
**Then** le rôle et l'état d'invitation affichés sont corrects,
**And** les permissions du membre changent conformément à FR14 et FR16.

**Given** l'utilisateur courant affiché dans l'équipe,
**When** il consulte sa propre ligne,
**Then** elle est identifiée par « Vous »,
**And** l'auto-révocation est impossible côté interface et serveur,
**And** le flux « Quitter » n'apparaît que lorsqu'il est autorisé.

**Given** un Owner,
**When** une tentative de révocation ou de départ le cible,
**Then** l'action est refusée,
**And** la propriété de la kermesse est préservée.

**Given** deux kermesses distinctes,
**When** un utilisateur tente d'agir sur une ressource appartenant à l'autre kermesse,
**Then** l'accès est refusé,
**And** aucune donnée inter-kermesse n'est lue ou modifiée.

**Given** les scénarios organisateurs P0/P1 de la matrice,
**When** la Story est terminée,
**Then** chaque interaction dépendant du rendu ou du JavaScript possède un test Playwright,
**And** chaque règle serveur reste également protégée au niveau PHPUnit approprié.

### Story 6.6 : Fiabiliser les preuves MariaDB des parcours critiques

As an équipe de livraison,
I want valider les invariants critiques sur le moteur réellement utilisé en production,
So that les tests SQLite ne masquent plus les divergences MariaDB.

**Acceptance Criteria:**

**Given** la suite MariaDB,
**When** sa base est initialisée,
**Then** elle utilise les migrations SQL applicatives réelles,
**And** les scénarios P0/P1 concernés ne recréent pas un schéma simplifié dans les tests.

**Given** les fixtures MariaDB,
**When** les scénarios sont préparés,
**Then** elles utilisent des données fictives reproductibles via les mécanismes de test du projet,
**And** elles respectent réellement les contraintes et clés étrangères de production.

**Given** une base vierge,
**When** toutes les migrations sont appliquées,
**Then** leur ordre, leur checksum et le schéma final sont validés,
**And** aucune table attendue ou migration ne peut être oubliée par une liste de nettoyage maintenue manuellement.

**Given** les invariants d'inscription,
**When** des opérations concurrentes ciblent la dernière place, un doublon ou un chevauchement,
**Then** la capacité n'est jamais dépassée,
**And** une seule opération compatible est validée,
**And** les transactions en échec ne laissent aucun état partiel.

**Given** un déplacement ou une annulation administrative,
**When** une contrainte ou une concurrence provoque un échec,
**Then** l'opération est atomique,
**And** l'inscription source et la capacité restent cohérentes.

**Given** les opérations sensibles de rôle,
**When** deux mutations concurrentes sont exécutées,
**Then** les protections contre l'auto-révocation, la perte du rôle Owner et les incohérences de rôle sont démontrées.

**Given** le scénario de la Story 6.1,
**When** un créneau est ajouté à une kermesse `open`,
**Then** le scénario passe également sur MariaDB avec les migrations réelles.

**Given** un comportement testé sous SQLite et MariaDB,
**When** les résultats divergent,
**Then** la CI échoue,
**And** la divergence doit être résolue ou explicitement documentée avant fusion.

**Given** l'environnement CI,
**When** le job MariaDB est exécuté,
**Then** l'absence ou l'échec du service MariaDB ne peut pas être traité comme un succès,
**And** les journaux nécessaires au diagnostic sont conservés.

Le remplacement de tous les anciens schémas de tests du projet reste hors périmètre : cette Story migre obligatoirement les fixtures des parcours P0/P1.

### Story 6.7 : Installer les gates CI de préparation à la production

As an équipe de livraison,
I want que les preuves de qualité bloquent automatiquement les régressions,
So that une release défectueuse ne puisse pas atteindre `main` ou la production.

**Acceptance Criteria:**

**Given** les outils de validation du projet,
**When** un développeur travaille localement,
**Then** des commandes Composer explicites existent pour PHPUnit, PHPStan, MariaDB et les tests E2E,
**And** une commande unique permet d'exécuter les contrôles requis avant PR.

**Given** l'analyse statique exigée par `project-context.md`,
**When** PHPStan est configuré,
**Then** son niveau et son périmètre sont versionnés,
**And** toute nouvelle erreur bloque la CI,
**And** une éventuelle baseline est explicite et ne peut pas masquer de nouvelles erreurs.

**Given** une Pull Request,
**When** la CI s'exécute,
**Then** PHPUnit SQLite, PHPStan, les scénarios MariaDB P0/P1 et les trois smoke tests Playwright de la Story 6.3 sont obligatoires,
**And** aucun job critique n'utilise `continue-on-error`.

**Given** une modification touchant les rôles, inscriptions, états, migrations ou workflows de déploiement,
**When** la CI s'exécute,
**Then** la suite critique étendue correspondante est exécutée sur MariaDB et dans Playwright.

**Given** la branche principale ou une release candidate,
**When** le pipeline complet s'exécute,
**Then** tous les parcours P0/P1 automatisés sont exécutés,
**And** l'artefact de déploiement n'est construit qu'après leur succès.

**Given** une suite Playwright instable,
**When** un test ne passe qu'après relance,
**Then** l'instabilité reste visible et bloque sa promotion en gate,
**And** les relances ne transforment pas silencieusement un échec en succès.

**Given** un échec PHPUnit, PHPStan, MariaDB, Playwright ou packaging,
**When** le workflow se termine,
**Then** aucune étape de déploiement ne peut démarrer.

**Given** l'archive Ouvaton,
**When** elle est construite,
**Then** elle contient le runtime PHP autonome et les assets statiques,
**And** elle exclut Node, Playwright, les tests, traces et dépendances de développement.

**Given** une exécution réussie,
**When** les preuves sont publiées,
**Then** elles sont rattachées au commit exact et au checksum de l'artefact,
**And** les noms des checks obligatoires sont documentés pour la protection de branche.

### Story 6.8 : Gérer l'expiration de session avec redirection gracieuse

As un utilisateur connecté (bénévole, gestionnaire, admin ou owner),
I want que l'expiration de ma session ne produise jamais une page d'erreur PHP CodeIgniter,
So that je sois redirigé vers la connexion Magic Link et ramené à ma page une fois authentifié.

**Acceptance Criteria:**

**Given** un utilisateur dont la session a expiré,
**When** il accède à une route GET authentifiée (ex. tableau de bord, profil),
**Then** le filtre d'authentification le redirige vers `/auth/request?redirect=<url_courante>`,
**And** après connexion Magic Link réussie, `MagicLinkController` le renvoie sur l'URL d'origine,
**And** aucune page d'erreur PHP n'est affichée.

**Given** un utilisateur dont la session a expiré,
**When** il soumet un formulaire POST (ex. inviter un admin, accepter/refuser/annuler une inscription),
**Then** CodeIgniter lève une `SecurityException` (le hash CSRF stocké en session est perdu avec la session elle-même, avant même l'exécution du filtre d'auth),
**And** le gestionnaire d'exceptions de l'application intercepte cette `SecurityException`, détecte l'absence de session utilisateur, et redirige vers `/auth/request` avec un message flash "Votre session a expiré — reconnectez-vous pour continuer.",
**And** après reconnexion, l'utilisateur atterrit sur le tableau de bord de la kermesse concernée (pas une page d'erreur 403 CodeIgniter),
**And** aucune page d'erreur PHP brute n'est affichée.

**Given** la configuration du gestionnaire d'exceptions,
**When** une `SecurityException` est levée avec une session active (CSRF genuinement invalide, pas une session expirée),
**Then** la réponse 403 habituelle est retournée sans redirection silencieuse vers le login.

**Given** le paramètre `redirect` transmis après reconnexion,
**When** sa valeur est validée,
**Then** seules les URLs de même origine (même domaine) sont acceptées,
**And** toute URL externe est rejetée et l'utilisateur atterrit sur l'accueil connecté (protection open redirect).

**Given** les tests de la Story 6.8,
**When** la suite PHPUnit est exécutée,
**Then** les scénarios GET expiré, POST expiré et open redirect sont couverts en feature tests,
**And** les tests s'exécutent sous SQLite sans dépendance à Ouvaton.

### Story 6.8bis : Résoudre la dette technique CI (Deferred Findings de la Story 6.7)

As an équipe de livraison,
I want traiter la dette technique de l'infrastructure CI identifiée lors de la Story 6.7 (PHPStan & E2E),
So that la pipeline de validation soit stricte et ne masque aucune anomalie réelle avant la release.

**Acceptance Criteria:**

**Given** le script d'exécution des tests bout-en-bout (`scripts/e2e.sh`),
**When** les tests Playwright s'exécutent via Docker,
**Then** le script ne fait pas une confiance aveugle aux codes de retour s'il y a un crash sous-jacent ("Blind Trust in E2E Script Exit Codes"),
**And** tout échec interne est propagé correctement à la CI.

**Given** la configuration de l'analyse statique (`phpstan.neon.dist`),
**When** PHPStan s'exécute,
**Then** le niveau d'analyse est augmenté ("PHPStan Level too low"),
**And** le problème de pollution par le fichier de bootstrap ("PHPStan bootstrap poisoning") est corrigé.

**Given** la baseline PHPStan (`phpstan-baseline.neon`),
**When** l'analyse est lancée,
**Then** les vraies anomalies masquées par la baseline sont résolues ("Baseline hides real issues"),
**And** la baseline est drastiquement réduite ou supprimée.

### Story 6.9 : Qualifier et déployer la release candidate

As an organisateur responsable de la mise en production,
I want disposer d'une release candidate éprouvée et récupérable,
So that la mise en production puisse être décidée et exécutée avec un risque maîtrisé.

**Acceptance Criteria:**

**Given** un commit candidat,
**When** la release candidate est créée,
**Then** son commit, son archive et son checksum sont immuables et enregistrés,
**And** aucun rebuild n'est effectué entre qualification et production.

**Given** un environnement de répétition représentatif d'Ouvaton,
**When** l'archive candidate est déployée,
**Then** aucun Composer, Node, build d'assets ou CLI de migration n'est requis sur la cible,
**And** les migrations passent exclusivement par le webhook HTTPS HMAC prévu.

**Given** un schéma et des données représentatifs de l'état précédant la release,
**When** la migration est répétée,
**Then** elle réussit sans perte de données,
**And** sa réexécution respecte le contrat d'idempotence,
**And** un échec partiel produit un état observable et récupérable.

**Given** une sauvegarde réalisée avant déploiement,
**When** la procédure de restauration est répétée,
**Then** le schéma, les données et l'application reviennent dans un état cohérent,
**And** la durée mesurée respecte le seuil accepté avant le Go.

**Given** l'archive déployée en répétition,
**When** les smoke tests post-déploiement sont exécutés,
**Then** ils couvrent au minimum la page publique et sa confidentialité, le Magic Link via le fournisseur de test, le dashboard par rôle, l'inscription et la participation bénévole, la gestion des membres, l'ajout d'un créneau sur une kermesse ouverte, ainsi que l'annulation et la libération de capacité.

**Given** la matrice de couverture,
**When** la revue Go/No-Go est tenue,
**Then** 100 % des cellules P0/P1 possèdent une preuve verte,
**And** aucune anomalie critique ou majeure ne reste ouverte sans arbitrage explicite,
**And** chaque risque résiduel possède un propriétaire.

**Given** la fenêtre de production,
**When** le Go est prononcé,
**Then** un responsable du déploiement et un décideur de rollback sont nommés,
**And** les seuils déclenchant l'arrêt ou la restauration sont écrits avant le lancement.

**Given** le déploiement autorisé,
**When** le workflow de production est exécuté,
**Then** il déploie exactement l'archive qualifiée,
**And** préserve le `.env` de production,
**And** n'effectue aucune manipulation manuelle hors pipeline.

**Given** le déploiement terminé,
**When** les smoke tests et contrôles de santé sont exécutés,
**Then** leur succès permet de confirmer la release,
**Or** leur échec déclenche la procédure d'arrêt ou de restauration définie.

**Given** la production confirmée,
**When** l'Epic 6 est clôturée,
**Then** les résultats, durées, décisions et risques résiduels sont archivés,
**And** l'Epic 7 peut quitter son état gelé.

### Story 6.10 : Sécuriser le déploiement des migrations incompatibles

As a responsable de la mise en production,
I want déployer le renommage physique `signups` → `slot_signups` avec une stratégie expand/contract et des contrôles automatisés,
So that une migration échouée ou partiellement appliquée ne rende jamais la production indisponible.

**Acceptance Criteria:**

**Given** les migrations déjà appliquées par la branche `main` en production,
**When** l'artefact de l'Epic 6 est construit,
**Then** chaque migration déjà livrée est conservée byte-for-byte avec le même checksum,
**And** toute dérive de checksum bloque la CI avant l'activation de la release,
**And** seules les migrations SQL de `database/migrations_sql/` sont incluses pour Ouvaton — aucune migration CI4 native n'est requise ou packagée.

**Given** l'application encore connectée au schéma contenant `signups`,
**When** la release de transition est activée,
**Then** le code applicatif fonctionne avant comme après le renommage de table,
**And** toutes les références SQL au nom physique de la table passent par le même mécanisme de compatibilité,
**And** le retour à la release précédente reste possible tant que la migration n'est pas confirmée.

**Given** l'état initial où seule la table `signups` existe,
**When** la migration de renommage est exécutée via `POST /ops/migrate`,
**Then** la table devient `slot_signups` sans perte de lignes, d'index ni de clés étrangères,
**And** une nouvelle exécution après succès est sans effet et retourne un succès,
**And** la présence simultanée des deux tables, ou l'absence des deux, provoque un échec explicite sans écriture supplémentaire.

**Given** une nouvelle release prête à être activée,
**When** le workflow `deploy-ouvaton.yml` atteint la phase de migration,
**Then** un préflight signé `POST /ops/migrate/status` vérifie l'absence de drift et la liste exacte des migrations attendues,
**And** un postflight exige `pending = []` et `failed = []`,
**And** un smoke test vérifie au minimum une lecture et une écriture sur `slot_signups`,
**And** tout échec arrête le déploiement dans un état applicatif compatible avec le schéma restant.

**Given** une base MariaDB contenant le schéma et des données représentatifs de la production sur `main`,
**When** le test d'upgrade complet est exécuté en CI,
**Then** toutes les migrations nouvelles sont appliquées dans leur ordre réel via `MigrationRunnerService`,
**And** les nombres de lignes, index, clés étrangères et invariants d'inscription sont vérifiés après migration,
**And** les assertions CI attendent `slot_signups` et l'absence de `profile_divergences`,
**And** aucune fusion de l'Epic 6 sur `main` n'est autorisée tant que ce test et les quality gates ne sont pas verts.

---

## Epic 7 : Post-MVP — Système d'Invités (Guests)

_Note : Cette Epic est prévue pour gérer les cas de comptes partagés au sein d'une même famille (parents partageant une adresse email)._

### Story 7.1 : Inscription de tiers (Guests)
As un bénévole connecté,
I want pouvoir inscrire mon conjoint ou mes enfants à des créneaux depuis mon propre compte,
So that toute la famille puisse participer sans avoir à créer plusieurs adresses email fictives.


### Story 5.10 : Onglet "Gestion des inscrits" — Gérer, annuler, et corriger une inscription

As an Owner/Admin/Gestionnaire,
I want pouvoir annuler, corriger ou annoter une inscription depuis l'onglet "Gestion des inscrits", avec une séparation claire entre actifs et historique,
So que je maintienne un planning exact sans attendre que le bénévole agisse lui-même.

**Acceptance Criteria:**

**Given** l'interface "Gestion des inscrits" d'un créneau,
**When** l'interface s'affiche,
**Then** l'Admin voit deux tableaux distincts : un pour les inscriptions actives (statut calculé ∈ `unconfirmed`, `certified` — i.e. `canceled_at IS NULL` et `rejected_at IS NULL`), et un pour l'historique (statut calculé ∈ `cancelled`, `removed`, `refused`).

**Given** l'affichage du nom d'un bénévole dans le tableau de bord de la kermesse (liste des inscrits),
**When** l'inscription est affichée,
**Then** si le statut calculé est `certified` ou `refused` (`accepted_at IS NOT NULL` ou `rejected_at IS NOT NULL`), **ou** si `viewed_at IS NOT NULL` (inscription déjà vue par le bénévole), le nom affiché est **exclusivement** celui de son profil global `users` (la copie `signups` est ignorée visuellement),
**And** si le statut calculé est `unconfirmed` et `viewed_at IS NULL`, le nom affiché est le snapshot temporaire stocké dans `signups` (qui peut avoir été corrigé par l'admin).
> **Note** : `confirmed` et `seen` ne sont pas des valeurs stockées dans `signups.status` — ils sont calculés à l'affichage (`seen` = `viewed_at IS NOT NULL`, `confirmed` = alias d'affichage pour `certified`).

**Given** la création d'une nouvelle inscription par l'admin au nom d'un bénévole,
**When** l'inscription est sauvegardée,
**Then** les timestamps `accepted_at`, `rejected_at`, `canceled_at` restent à NULL → statut calculé = `unconfirmed`.

**Given** l'onglet "Gestion des inscrits" ouvert par un rôle autorisé,
**When** l'admin clique sur "Annuler l'inscription" d'un bénévole et confirme,
**Then** `SlotSignupService.adminCancelSignup()` renseigne `canceled_at = now()` et `canceled_by = adminUserId` (statut calculé = `removed`) et libère la place,
**And** l'admin se voit proposer une case "Notifier [email du bénévole]" avant confirmation ; si cochée, un email d'annulation est envoyé,
**And** le bénévole passe dans le tableau de l'historique du créneau,
**And** `signups.last_modified_by_user_id` et `last_modified_at` sont renseignés,
**And** cette action est possible quel que soit l'état de la kermesse (override admin).

**Given** l'onglet "Gestion des inscrits" et la fiche d'un bénévole,
**When** l'admin modifie la fiche de l'inscription (prénom, nom, email, téléphone, ou les **notes internes** `admin_notes`),
**Then** **seuls** les champs de la fiche d'inscription (`signups`) sont mis à jour — le compte global (`users`) n'est **jamais** modifié.
**And** dans tous les cas, le champ `admin_notes` ("Maman de Léo") reste visible et éditable par l'admin.

> **Modèle de données — Approche Snapshot** : la fiche d'inscription (`signups`) porte sa **propre copie** prénom/nom/email/téléphone (capturée au moment de l'inscription publique) ainsi que le champ `admin_notes`. Le compte utilisateur global n'est jamais réécrit par un admin.

> **Règle de l'Identité Unique (Choix A)** : 1 compte (email) = 1 identité physique. Dès que le compte est validé par l'utilisateur, l'organisateur ne voit plus que le "vrai" nom du compte (table `users`), garantissant que le système ne triche pas en inventant plusieurs identités pour un même compte. Tout le besoin organisationnel (ex: le conjoint qui vient tenir le stand) est couvert par le champ `admin_notes` en attendant la future Epic de gestion des Invités.

### Story 5.11 : Onglet "Gestion des inscrits" — Ajouter une inscription manuellement

As an Owner/Admin/Gestionnaire,
I want ajouter manuellement une inscription depuis l'onglet "Gestion des inscrits",
So que j'inscrive un bénévole qui s'est signalé par téléphone ou en personne.

**Acceptance Criteria:**

**Given** l'onglet "Gestion des inscrits" ouvert par un rôle autorisé,
**When** l'admin clique sur "Ajouter un bénévole" sur un créneau,
**Then** un formulaire s'ouvre avec prénom, nom, email (obligatoire), téléphone (facultatif) et une case "Envoyer un email de confirmation",
**And** le bouton de validation est désactivé tant que les champs obligatoires sont vides.

**Given** un formulaire soumis avec des champs obligatoires manquants ou un email invalide,
**When** l'admin soumet,
**Then** des erreurs inline sont liées aux champs invalides et les valeurs saisies sont conservées.

**Given** la soumission d'une inscription manuelle valide,
**When** `SlotSignupService.createSignup()` la traite,
**Then** les mêmes invariants s'appliquent qu'à l'inscription publique (capacité, doublon, chevauchement),
**And** si la case "Envoyer un email de confirmation" est cochée, un email de confirmation avec Magic Link est envoyé via `EmailService`,
**And** cette action est possible même si la kermesse est à l'état "Fermé" — override admin,
**And** `signups.last_modified_by_user_id` est renseigné avec l'ID de l'admin.

**Given** un email saisi qui correspond à un compte déjà existant, avec un prénom/nom différent de son profil,
**When** l'inscription manuelle est créée,
**Then** la fiche d'inscription (`signups`) porte les coordonnées saisies par l'admin, le compte global (`users`) n'est **pas** modifié,
**And** la divergence copie↔profil sera réconciliée au premier accès du bénévole à la kermesse (Story 5.4), sans fusion silencieuse dans son compte.

### Story 5.12 : Onglet "Gestion des inscrits" — Déplacer une inscription

As an Owner/Admin/Gestionnaire,
I want déplacer l'inscription d'un bénévole vers un autre créneau,
So que je gère les ajustements de planning sans annuler et recréer manuellement.

**Acceptance Criteria:**

**Given** une inscription active dans l'onglet "Gestion des inscrits",
**When** l'admin clique sur "Déplacer",
**Then** une liste des créneaux disponibles (places restantes > 0, hors créneau source) s'affiche avec les horaires et places restantes.

**Given** un créneau cible sélectionné,
**When** l'admin confirme le déplacement,
**Then** `SlotSignupService.moveSignup()` exécute dans une transaction : annule l'inscription source et crée l'inscription cible,
**And** si la capacité du créneau cible est pleine au moment du déplacement, l'erreur `slot_full` est retournée et aucune des deux inscriptions n'est modifiée,
**And** si le créneau cible chevauche un autre créneau où le bénévole est déjà inscrit (hors inscription source), l'erreur `overlap_conflict` est retournée et aucune des deux inscriptions n'est modifiée,
**And** l'admin se voit proposer une case "Notifier [email]" ; si cochée, un email de déplacement est envoyé,
**And** `signups.last_modified_by_user_id` et `last_modified_at` sont renseignés sur la nouvelle inscription.

### Story 5.13 : Renommer le concept `signup` → `SlotSignup` (Ubiquitous Language) `[DÉFÉRÉ — Candidat Epic 7 Tech-Debt]`

> **Décision (2026-06-17, renumérotation 2026-06-18)** : story déprioritisée au profit du fonctionnel restant (Story 5.14). Déplacée en backlog — candidate pour l'Epic 7 "Post-MVP Guests & Nettoyage".

As a développeur,
I want que l'entité « inscription à un créneau » soit nommée sans ambiguïté dans le code,
So that on ne confonde plus avec une « création de compte » (qui, elle, est implicite via Magic Link).

**Acceptance Criteria:**

**Given** le code actuel utilisant la racine `signup`,
**When** le renommage est appliqué en **un changement atomique**,
**Then** l'entité devient `SlotSignup` : `SlotSignupService`, `SlotSignupModel`, `SlotSignupResult`, contrôleurs, vues et emails associés,
**And** la table `signups` est renommée `slot_signups` (migration de renommage),
**And** les identifiants dans `epics.md`, `architecture.md` et `CLAUDE.md` sont alignés,
**And** les codes d'erreur stables (`duplicate_signup`, `signups_not_open`, `slot_full`) sont **conservés** tels quels (contrats stables, contextuellement clairs),
**And** l'UI/French reste inchangée (« inscription » / « Mes participations »).

_Hors périmètre (post-Epic 5) : journal chronologique complet des modifications (Qui/Quand/Quoi) par inscription, y compris les actions du bénévole lui-même._

### Story 5.14 : Traçabilité et Validation des Inscriptions (Stateless)

> **[Modif 3.2, Modif 4.3, Modif 4.4]** Cette story modifie le comportement de `SlotSignupService.createSignup()` livré en Story 3.2 (pas de création de compte silencieuse), de `SlotSignupService.cancelSignup()` livré en Story 4.3 (ajout `canceled_at`/`canceled_by`), et de `SlotSignupModel.findActiveParticipantsForKermesse()` livré en Story 4.4 (passage à `LEFT JOIN users` pour inclure les orphelins).

As un architecte et product owner,
I want tracer avec précision l'origine et le cycle de vie de chaque inscription via une machine à états rigoureuse (statuts + timestamps d'acteurs),
So that je garantis la fiabilité des inscriptions tout en évitant la création de comptes inutiles et en responsabilisant les utilisateurs (bénévoles et admins).

**Acceptance Criteria:**

---

**[AC-1 — Statut à la création et `created_by`]**

**Given** l'inscription publique d'un bénévole à un créneau,
**When** le visiteur n'est pas connecté (email inconnu ou connu),
**Then** l'inscription est insérée avec `user_id = NULL` et `created_by = NULL` (pas de création de compte silencieuse) — **aucun champ `status` n'est inséré en base** ; le statut `unconfirmed` est calculé à la volée : `canceled_at IS NULL AND rejected_at IS NULL AND accepted_at IS NULL`.
**And** le champ `created_by` est présent dans `SlotSignupModel.$allowedFields` et inclus dans chaque appel INSERT de `signupWithinTransaction()`.

**When** l'utilisateur est connecté (bénévole ou admin via `createSignupByAdmin`),
**Then** l'inscription est insérée avec `created_by = user_id_de_la_session` et `accepted_at = now()` — le statut `certified` est calculé à la volée : `accepted_at IS NOT NULL`.

**And** dans tous les cas, `RoleService` ne crée une entrée `Bénévole` dans `kermesse_user_roles` **que si** `user_id` est non nul ; si `user_id = NULL`, cette entrée sera créée lors de `resolveOrphanSignups` à la première connexion.

---

**[AC-2 — Rattachement des orphelins à la connexion]**

**Given** un bénévole qui se connecte,
**When** il existe des inscriptions associées à son email dont le `user_id` est NULL,
**Then** le système les rattache (mise à jour du `user_id`) via `SlotSignupService.resolveOrphanSignups()` (qui délègue à `SlotSignupModel.attachOrphansToUser()` en interne),
**And** `viewed_at` est renseigné sur ces inscriptions (s'il était NULL) pour prouver la prise de connaissance,
**And** `RoleService` crée une entrée `Bénévole` dans `kermesse_user_roles` pour chaque kermesse concernée.

---

**[AC-3 — Accept/Reject depuis "Mes participations"]**

**Given** une inscription où `created_by` est NULL ou différent de l'`user_id` du bénévole connecté,
**When** le bénévole consulte la section « Mes participations »,
**Then** un bouton « Confirmer » et un bouton « Refuser » sont affichés tant que `accepted_at` et `rejected_at` sont tous les deux NULL.

**When** le bénévole clique « Confirmer »,
**Then** `SlotSignupService.acceptSignup(signupId, userId)` renseigne `accepted_at = now()` et retourne succès.

**When** le bénévole clique « Refuser »,
**Then** `SlotSignupService.rejectSignup(signupId, userId)` renseigne `rejected_at = now()` et retourne succès.

Ces deux méthodes doivent exister dans `SignupService` et dans `SignupModel` (méthode dédiée ou via `save()`).

---

**[AC-4 — Annulations avec `canceled_at` / `canceled_by`]**

**Given** un bénévole annulant sa propre inscription (`cancelSignup`),
**Then** en plus du `status = 'cancelled'`, `SlotSignupModel.markCancelled()` renseigne `canceled_at = now()` et `canceled_by = userId`.

**Given** un admin annulant une inscription (`adminCancelSignup`),
**Then** en plus du `status = 'removed'`, `SlotSignupModel.markCancelledByAdmin()` renseigne `canceled_at = now()` et `canceled_by = adminUserId`.

Ces deux timestamps doivent être inclus dans le `update()` de leurs méthodes respectives dans `SignupModel`.

---

**[AC-5 — Requête admin orphan-safe]**

**Given** la méthode `SlotSignupModel.findActiveParticipantsForKermesse()`,
**When** des inscriptions avec `user_id = NULL` existent pour la kermesse,
**Then** ces inscriptions apparaissent dans la liste admin (elles ne sont pas exclues),
**And** pour ces orphelins, le nom et l'email affichés sont les colonnes snapshot `signups.first_name`, `signups.last_name`, `signups.email` (fallback puisqu'il n'y a pas de `users` row),
**And** la requête utilise un `LEFT JOIN users` au lieu d'un `INNER JOIN` pour ne pas filtrer silencieusement les orphelins.

---

**[AC-6 — Admin : réassignation d'email]**

**When** l'administrateur corrige manuellement l'email d'un invité et que ce nouvel email correspond à un utilisateur existant,
**Then** l'inscription est réassignée en mettant à jour le `user_id` avec celui du compte correspondant (réassignation simple — pas de fusion complexe de comptes).

---

**[AC-7 — Orphelins et capacité]**

**Given** une inscription orpheline (`user_id = NULL`) non encore réclamée,
**When** elle existe sur un créneau,
**Then** elle compte dans la capacité active ; seul l'admin peut la supprimer manuellement depuis « Gestion des inscrits ».

---

> **Note de migration de comportement (Modif 3.2)** : en Epic 3, `SlotSignupService.createSignup()` créait un compte `users` si l'email était inconnu. Cette story supprime ce comportement : seul `find` (jamais `findOrCreate`) est utilisé dans `signupWithinTransaction()`. Le `user_id` reste NULL jusqu'à la première connexion du bénévole. Les tests de Story 3.2 couvrant la création d'utilisateur implicite seront mis à jour lors du développement de cette story.

> **Note d'implémentation — couche de données** : les colonnes `created_by`, `viewed_at`, `accepted_at`, `rejected_at`, `canceled_at`, `canceled_by` doivent toutes être présentes dans `SlotSignupModel.$allowedFields`. La migration ajoutant ces colonnes à la table `signups` est le prérequis de cette story.


### Story 7.2 : Nettoyage du code zombie de résolution de profil `[DÉFÉRÉ — Candidat Epic 7 Tech-Debt]`

> **Décision (2026-06-17, renumérotation 2026-06-18)** : story déprioritisée au profit du fonctionnel restant (Story 5.14). Déplacée en backlog — candidate pour l'Epic 7 "Post-MVP Guests & Nettoyage".

As a développeur,
I want supprimer le code mort résiduel issu de l'ancienne résolution de profil (pré-stateless),
So that la base de code ne contienne plus de chemins non utilisés qui créent de la confusion.

**Acceptance Criteria:**

**Given** le refactoring stateless effectué dans les stories 3.6 et 5.4,
**When** le nettoyage est appliqué,
**Then** tout code zombie identifié (méthodes, tables, colonnes, vues) est supprimé proprement,
**And** les tests couvrant le comportement supprimé sont mis à jour ou retirés,
**And** aucune régression fonctionnelle n'est introduite.

### Story 7.3 : Corriger l'incohérence d'état d'invitation de l'onglet « Équipe » `[DÉFÉRÉ — Candidat Epic 7 Tech-Debt]`

> **Décision (2026-06-21)** : bug découvert lors du checkpoint manuel de la Story 6.8 (expiration de session). Hors-périmètre de la 6.8 ; isolé en backlog Epic 7 (dette technique) pour ne pas élargir la clôture de l'Epic 6. Touche les stories 5.5 (onglet Équipe) et 5.8.

As an Owner/Admin,
I want que l'état d'une invitation soit cohérent entre la liste de l'équipe, la modale d'édition et l'action « Relancer »,
So that je ne voie plus un membre déjà connecté affiché « Invitation envoyée » avec un bouton « Relancer » qui n'envoie aucun email.

**Contexte — trois symptômes, deux causes :**

Le cycle d'une invitation a **quatre jalons** en base : `invited_at` (envoi) → `accepted_at` (clic Magic Link, `MagicLinkController:115`) → `last_login_at` (1ʳᵉ connexion confirmée, `ProfileService:48`) → `first_access_at` (1ʳᵉ ouverture du dashboard de *cette* kermesse, `RoleService:62`). Trois écrans répondent à « cette personne a-t-elle pris en main son compte ? » en lisant **trois colonnes différentes** :
- Badge liste « Invitation envoyée » → `first_access_at IS NULL` (`RoleService::getTeamMembersGroupedByStatus`, ligne ~507).
- Modale d'édition « gère son propre compte » → `accepted_at !== null` (JS `openEditMemberModal`, `dashboard.php:985`).
- Garde de « Relancer » → `accepted_at !== null && last_login_at !== null` (`RoleService::resendInvitation`, ligne ~236).

**Acceptance Criteria:**

**Given** un membre ayant cliqué le Magic Link **et** confirmé sa connexion (`accepted_at` et `last_login_at` renseignés) mais n'ayant pas encore ouvert le dashboard de cette kermesse (`first_access_at IS NULL`),
**When** l'onglet « Équipe » s'affiche,
**Then** ce membre n'apparaît **plus** comme « Invitation envoyée » (il est traité comme actif), en alignant la classification sur la définition métier unique `accepted_at !== null && last_login_at !== null` — tout en préservant le cas Owner (`invited_at IS NULL` reste actif).

**Given** la modale d'édition d'un membre,
**When** elle s'ouvre,
**Then** son verrouillage des champs et son message « gère son propre compte » utilisent **la même** condition que la classification de la liste (ajouter `u.last_login_at` au SELECT de `UserRoleModel::findTeamMembers`).

**Given** une action de l'onglet « Équipe » (relancer, éditer, inviter, révoquer),
**When** elle aboutit et redirige,
**Then** l'utilisateur **reste sur l'onglet concerné** (ancre `#equipe` ou `#inscrits` selon l'action) et **voit le flash** — au lieu de l'ancre obsolète `#participants` qui le renvoie sur l'onglet Modification (`KermesseAdminController` lignes ~550, 621, 635, 639, 665 ; mapper chaque redirection au bon onglet).

**Given** les corrections ci-dessus,
**When** la suite PHPUnit est exécutée,
**Then** des feature tests couvrent (a) le membre connecté qui ne doit plus être « en attente », (b) le comportement de « Relancer » sur ce membre, (c) la redirection post-action vers le bon onglet,
**And** la suite est verte et PHPStan sans ajout au baseline.
