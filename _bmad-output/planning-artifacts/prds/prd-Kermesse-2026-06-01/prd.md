---
title: "PRD: Kermesse"
status: final
created: 2026-06-01
updated: 2026-06-17
changelog:
  - 2026-06-17: Ajout des 3 scénarios de création d'inscription (Party Mode) + politique des inscriptions fantômes (FR-8, FR-13).
  - 2026-06-17b: Précision de la machine à états des statuts (FR-8), `created_by` obligatoire dans le INSERT, `canceled_at`/`canceled_by` sur toutes les annulations, accept/reject depuis "Mes participations", et requête admin orphan-safe (FR-13).
  - 2026-06-17c: FR-2 complété — `findOrCreateByEmail()` à la validation du Magic Link est le seul moment de création de compte pour un email inconnu, et le prérequis explicite de FR-13 (`resolveOrphanSignups`).
  - 2026-06-17d: Refactoring de la machine à états (FR-8, FR-13) : le champ `status` est supprimé de la base de données au profit d'une valeur calculée à la volée (via les timestamps) pour éviter toute redondance et désynchronisation.
---

# PRD: Kermesse

## 0. Objet Du Document

Ce PRD cadre la premiere version exploitable de Kermesse pour une mise en production rapide. Il s'appuie sur les fichiers de Design Thinking initiaux, ainsi que sur le Sprint Change Proposal "Modèle d'Identité Unifié & Rôles" du 2026-06-10.

Le document privilégie un MVP robuste qui s'appuie sur un modèle d'identité unifié (Magic Link, rôles par kermesse) dès le départ pour éliminer la dette technique d'une gestion fragmentée, tout en gardant une interface publique simple.

## 1. Vision

Kermesse est une application web mobile-first qui aide un organisateur à publier les besoins par stand et par créneau, puis à collecter les inscriptions des bénévoles avec le moins de friction possible.

L'application repose sur un modèle d'identité centralisé basé sur l'email, permettant une connexion fluide sans mot de passe ("Magic Link"). Ce modèle permet aux utilisateurs de cumuler différents rôles (Owner, Admin, Gestionnaire, Bénévole) sur plusieurs kermesses à partir d'un compte unique, tout en offrant aux bénévoles un tableau de bord unifié de leurs participations.

## 2. Utilisateurs Cibles

### 2.1 Jobs To Be Done

- En tant qu'organisateur, je veux configurer rapidement les stands, créneaux et capacités.
- En tant qu'organisateur, je veux partager un lien ou QR code afin que les bénévoles accèdent directement à la page d'inscription.
- En tant qu'organisateur, je veux voir les inscrits et gérer ma kermesse depuis un tableau de bord unifié.
- En tant que bénévole, je veux m'inscrire en quelques gestes sans me heurter à une barrière de connexion.
- En tant que bénévole, je veux retrouver facilement toutes mes participations depuis mon tableau de bord via un Magic Link.
- En tant qu'utilisateur, je veux me connecter avec mon email sans retenir de mot de passe.

### 2.2 Non-Utilisateurs V1

- Organisateurs de gros événements multi-jours avec affectation automatique.
- Administrateurs cherchant un CRM, un outil de billetterie ou de paiement.

### 2.3 Parcours Utilisateurs Clés

- **UJ-1. Sylvain crée et configure la kermesse.**
  - **Persona + contexte:** Sylvain organise une kermesse imminente.
  - **État d'entrée:** Accueil public, non connecté.
  - **Parcours:** Il choisit "Créer une kermesse", saisit ses informations (créant ainsi son compte Utilisateur) et reçoit un Magic Link pour valider. Une fois connecté, il arrive sur l'espace admin de son tableau de bord, ajoute les stands, créneaux, capacités, et ouvre les inscriptions.
  - **Moment de valeur:** Le lien/QR code pointe vers une page claire.

- **UJ-2. Claire s'inscrit depuis son téléphone.**
  - **Persona + contexte:** Claire est parent d'élève, non connectée.
  - **État d'entrée:** Elle arrive via lien ou QR code sur la page publique.
  - **Parcours:** Elle choisit un créneau, saisit prénom, nom, email et téléphone. Le système attache l'inscription à son email (créant un compte si nouveau, ou enregistrant une tentative de mise à jour des coordonnées si existant).
  - **Moment de valeur:** L'inscription est acceptée, la place restante est mise à jour. Elle voit qu'elle peut retrouver ses inscriptions en se connectant via Magic Link.

- **UJ-3. Claire gère ses participations.**
  - **Persona + contexte:** Claire ne peut plus tenir le créneau.
  - **État d'entrée:** Elle va sur l'accueil, saisit son email pour demander un Magic Link, et clique sur le lien reçu.
  - **Parcours:** Connectée, elle voit l'accueil listant ses kermesses. Elle accède au tableau de bord de la kermesse concernée dans la section "Mes participations". Elle sélectionne l'inscription à supprimer et confirme.
  - **Moment de valeur:** La place redevient disponible.

## 3. Glossaire

- **Utilisateur** — Entité unique identifiée par email. La première action crée le compte.
- **Rôle** — Niveau de permission sur une Kermesse (Owner, Admin, Gestionnaire, Bénévole). Un utilisateur possède un rôle unique par kermesse. Tous les rôles permettent de s'inscrire aux créneaux et voir ses participations.
- **Magic Link** — Méthode de connexion universelle sans mot de passe.
- **Kermesse** — Événement avec date, lieu, stands et créneaux.
- **Stand** — Poste demandant des bénévoles.
- **Créneau** — Plage horaire avec une capacité.
- **Inscription** (FR, UI) / **SlotSignup** (code, entité cible) — Réservation d'un utilisateur sur un créneau. Listée côté bénévole sous « Mes participations ». Le qualificatif `SlotSignup` (entité, table `slot_signups`, `SlotSignupService`) lève l'ambiguïté du terme `signup` seul, qui évoque à tort une « création de compte » — laquelle n'existe pas comme action distincte (le compte est créé **implicitement** via Magic Link). Il n'y a donc **pas** de « signup global » dans le système.
- **Tableau de bord unifié** — Interface (pour les utilisateurs connectés) listant les kermesses, et pour une kermesse donnée, offrant des sections selon le rôle (Modification, Gestion, Mes participations).

## 4. Fonctionnalités

### 4.1 Identité, Accueil et Accès

#### FR-1: Page d'accueil (Non connecté)
Deux actions possibles : "Créer une kermesse" (demande des infos personnelles de l'organisateur + informations basiques de la kermesse) ou "Me connecter" (demande de Magic Link).

#### FR-2: Connexion par Magic Link
Un utilisateur peut demander un Magic Link via son email. À la validation du lien, si aucun compte `users` n'existe pour cet email, il est créé à ce moment-là (`findOrCreateByEmail()`) — c'est **le seul moment** où un compte peut être créé pour un email inconnu (ni à l'inscription publique, ni avant la validation du lien). Le lien établit ensuite la session et redirige vers l'accueil connecté.

Ce comportement est le prérequis de FR-13 : c'est grâce à l'`user_id` créé ou retrouvé ici que `resolveOrphanSignups()` peut rattacher les inscriptions orphelines créées avant la connexion.

#### FR-3: Page d'accueil (Connecté)
Affiche la liste des kermesses de l'utilisateur avec son rôle pour chacune, et un bouton "Créer une nouvelle kermesse" (formulaire allégé sans redemander les infos perso).

### 4.2 Configuration Admin De La Kermesse

#### FR-4: Créer une kermesse
Un utilisateur (connecté ou non) peut créer une kermesse. S'il n'est pas connecté, cela crée son compte Utilisateur. Il obtient le rôle Owner sur cette kermesse.

#### FR-5: Gérer les stands et créneaux
L'admin peut ajouter, modifier, supprimer des stands et des créneaux (capacité > 0). La suppression d'un stand avec inscriptions requiert une validation forte (`SUPPRIMER`).

#### FR-6: Ouvrir et fermer les inscriptions
L'admin peut ouvrir ou fermer les inscriptions bénévoles.

### 4.3 Page Bénévole Et Inscription

#### FR-7: Afficher la kermesse publique
Vue publique listant stands/créneaux/places restantes. Un encart "Déjà inscrit ? Connectez-vous" redirige vers l'authentification Magic Link.

#### FR-8: Créer une inscription (Publique)
Le bénévole s'inscrit en renseignant email et infos. L'inscription est créée avec un log immutable (`created_by`, `created_at`). Trois scénarios selon le contexte de soumission :

- **Visiteur non connecté, email inconnu** : aucun compte créé silencieusement ; `user_id = NULL`, `created_by = NULL`. L'inscription sera rattachée au compte lors de la première connexion de l'utilisateur.
- **Visiteur non connecté, email connu** : `user_id` renseigné avec le compte existant, `created_by = NULL` (le bénévole n'était pas connecté au moment de l'acte).
- **Utilisateur connecté** : `user_id` et `created_by` renseignés avec l'ID de la session, et `accepted_at` renseigné automatiquement.

**Machine à états (Valeur Calculée)** : Afin d'éviter toute redondance et désynchronisation, le statut n'est plus stocké en base de données. Il est **calculé à la volée** (ex: via un accesseur `getStatus()` de l'entité) en fonction des timestamps :
- Si `canceled_at` != NULL et `canceled_by != user_id` → `removed` (annulation par un admin)
- Si `canceled_at` != NULL et `canceled_by == user_id` → `cancelled` (annulation par le bénévole)
- Si `rejected_at` != NULL → `refused` (bénévole rejette l'inscription orpheline)
- Si `accepted_at` != NULL → `certified` (bénévole valide ou crée lui-même l'inscription)
- Sinon → `unconfirmed` (attente d'action du bénévole à sa connexion)

- Affichage Admin : si le statut calculé est `certified` ou `refused` → le nom affiché en admin est celui du profil global `users` ; si `unconfirmed`, le nom affiché est le snapshot `signups.first_name/last_name`.

**`created_by`** : colonne obligatoire dans `signups.allowedFields` et incluse dans chaque INSERT de signup. Elle est NULL pour tout visiteur non connecté, et contient l'`user_id` de la session pour tout utilisateur connecté (bénévole ou admin).

**Politique des inscriptions fantômes** : dans tous les cas, l'inscription réserve la place et est comptée dans la capacité active du créneau, même si elle n'est pas encore validée par son titulaire. Les inscriptions orphelines (`user_id = NULL`) restent sous la responsabilité des admins : ils peuvent les supprimer manuellement depuis l'onglet « Gestion des inscrits » si elles s'avèrent erronées.

#### FR-9: Éviter les conflits de planning
Empêche l'inscription double sur un même créneau ou sur des créneaux qui se chevauchent pour le même Utilisateur (identifié par email).

### 4.4 Tableau de bord Kermesse (Connecté)

#### FR-10: Accès au tableau de bord
L'utilisateur connecté accédant à une kermesse voit une vue unifiée avec jusqu'à 4 onglets selon son rôle :
1. Modification de la kermesse (Admin/Owner)
2. Gestion des inscrits (Admin/Owner/Gestionnaire)
3. Équipe — membres, invitations, révocations (Admin/Owner)
4. Mes participations (Tous rôles)

#### FR-11: Supprimer une inscription (Bénévole)
Dans la section "Mes participations", l'utilisateur peut supprimer une de ses inscriptions actives, libérant ainsi la place.

#### FR-12: Voir les inscrits (Admin/Gestionnaire)
Dans la section "Gestion des participants", affichage des bénévoles inscrits par stand/créneau.

#### FR-13: Flux de Confirmation d'Identité et Traçabilité
Lorsqu'un Utilisateur se connecte, le système recherche toutes les inscriptions orphelines (sans `user_id`) associées à son email pour les rattacher (`resolveOrphanSignups`). Pour toute inscription dont `created_by` est NULL ou différent de l'`user_id` de la session, le timestamp `viewed_at` est renseigné à la connexion pour prouver la prise de connaissance. Le bénévole peut ensuite accepter (`accepted_at` renseigné, via méthode `acceptSignup()`) ou refuser (`rejected_at` renseigné, via méthode `rejectSignup()`) depuis « Mes participations ». Ces boutons n'apparaissent que si `accepted_at` et `rejected_at` sont tous les deux NULL.

**Annulations** : toute annulation — bénévole (`cancelSignup`) ou admin (`adminCancelSignup`) — doit renseigner **à la fois** `canceled_at` (horodatage) et `canceled_by` (ID de l'acteur). Ces champs déterminent le statut final (`cancelled` ou `removed`).

**Requête admin orphan-safe** : la méthode `findActiveParticipantsForKermesse()` doit inclure les inscriptions orphelines (`user_id = NULL`) via un `LEFT JOIN users` au lieu d'un `JOIN` strict. Pour les orphelins, le nom et l'email affichés sont les colonnes snapshot `signups.first_name/last_name/email`.

Les inscriptions orphelines (`user_id = NULL`) comptent dans la capacité active jusqu'à ce qu'elles soient réclamées ou supprimées ; les admins sont responsables du nettoyage des inscriptions fantômes non réclamées.

#### FR-14: Inviter et attribuer des rôles
Un Owner ou Admin peut inviter d'autres utilisateurs en saisissant leur email et en leur attribuant un rôle (Admin ou Gestionnaire) pour déléguer la gestion de la kermesse. Le système crée le compte si l'email est inconnu et notifie la personne par email. Lorsque le nouveau membre accepte son invitation (premier accès au dashboard), **le Owner reçoit également un email de notification** (`team_change_notification`, action : `joined`) indiquant le nom du membre, son rôle et l'identité de l'invitant.

#### FR-15: Gérer les inscriptions (Admin/Gestionnaire)
Depuis l'onglet « Gestion des inscrits », un rôle autorisé peut **ajouter**, **corriger les coordonnées**, **annuler** et **déplacer** l'inscription d'un bénévole (override d'état de kermesse possible). L'annulation par l'admin est tracée par son identité (`canceled_by`). Si l'admin corrige une erreur de saisie sur l'email d'un invité, et que ce nouvel email correspond à un profil existant, l'inscription lui est réassignée automatiquement (modification du `user_id`). Les corrections n'écrivent que dans le snapshot temporaire de l'inscription, jamais dans le profil utilisateur global. L'interface sépare visuellement les inscriptions actives des historiques et indique par des badges visuels si l'inscription est rattachée ou validée.

#### FR-16: Révoquer un rôle
Un Owner ou Admin peut révoquer le rôle d'un Admin/Gestionnaire ; la personne est rétrogradée en Bénévole (la kermesse reste visible dans son accueil connecté). Le rôle Owner n'est pas révocable. **Le Owner reçoit un email de notification** (`team_change_notification`, action : `removed`) identifiant le membre révoqué, son ancien rôle et l'acteur de la révocation — y compris si c'est le Owner lui-même qui a effectué l'action. Un admin ne peut pas révoquer son propre rôle via ce formulaire ; il doit utiliser le flux « Quitter l'organisation ».

#### FR-17: Quitter une kermesse
Un utilisateur sans inscription active peut se retirer lui-même d'une kermesse (le bouton est absent tant qu'il a des inscriptions actives). Le rôle Owner ne peut pas quitter. **Le Owner reçoit un email de notification** (`team_change_notification`, action : `left`) indiquant le membre partant et son ancien rôle. Le flux est accessible depuis « Mes participations », la carte kermesse dans l'accueil connecté, **et depuis l'onglet « Équipe »** (bouton « ↪️ Quitter » sur la propre ligne de l'utilisateur connecté si `canLeave = true`). Dans l'onglet Équipe, la propre ligne de l'utilisateur connecté affiche un badge « Vous » et masque le bouton de révocation.

#### FR-18: Gérer son profil
Un utilisateur connecté dispose d'une page profil (`/profile`) pour modifier lui-même ses coordonnées (prénom, nom, email, téléphone) ; c'est la seule voie pour corriger des coordonnées verrouillées côté admin.

## 5. Non-Objectifs Explicites

- Pas de liste publique globale de *toutes* les kermesses existantes (seul le lien direct marche).
- Pas de rôles différenciés par créneau.
- Pas de liste d'attente.
- Pas de personnalisation avancée de l'expéditeur email.

## 6. Périmètre MVP

### 6.1 Inclus
- Modèle d'identité unique (Utilisateur) via email.
- Connexion Magic Link universelle.
- Résolution des conflits de profil à la connexion.
- Accueil connecté avec liste des kermesses de l'utilisateur.
- Tableau de bord kermesse unifié basé sur les rôles.
- Rôles (Owner, Admin, Gestionnaire, Bénévole).
- Page publique pour inscription avec informations sans barrière de login.
- Rattachement automatique d'une inscription publique à l'Utilisateur.
- Gestion complète des stands et créneaux.
- Suppression autonome par l'utilisateur de son inscription depuis son espace.
- Vue admin des inscrits par stand/créneau.
- Gestion des inscriptions par l'admin : ajouter, corriger, annuler, **déplacer** une inscription (FR-15).
- Révoquer un rôle (FR-16), quitter une kermesse (FR-17), gérer son profil (FR-18).

### 6.2 Hors MVP
- Espace de recherche/filtrage globale de kermesses publiques.
- Multi-admin avancé ou transfert de propriété complet.
- Journal chronologique complet des modifications d'inscription (Qui/Quand/Quoi).

## 7. Exigences Non Fonctionnelles
- **Mobile-first:** utilisable confortablement sur smartphone, notamment pour la page bénévole publique.
- **Fluidité (Frictionless):** l'inscription publique ne doit pas obliger la création explicite d'un compte avec mot de passe au moment de l'action.
- **Robustesse:** prévention stricte des surcapacités côté serveur et des chevauchements horaires par Utilisateur.
- **Sécurité:** Les Magic Links doivent expirer après une durée courte (ex: 15 minutes) et être à usage unique. Les routes de modification doivent vérifier le rôle en base côté serveur.
- **Confidentialité (Privacy):** La page publique de la kermesse ne doit exposer aucune donnée personnelle des bénévoles inscrits (les noms sont visibles uniquement dans l'espace Admin/Gestionnaire connecté).

## 8. Indicateurs De Succès
- **SM-1:** Les utilisateurs (orga et bénévoles) parviennent à se connecter facilement par Magic Link.
- **SM-2:** L'inscription publique depuis un QR code fonctionne de manière fluide et s'attache correctement au compte.
- **SM-3:** Les conflits de profil (coordonnées différentes) sont résolus naturellement à la connexion suivante sans bloquer l'utilisateur.
- **SM-4:** Aucun créneau ne dépasse sa capacité, et la gestion centralisée par rôles permet de facilement déléguer la kermesse.

## 9. Questions Ouvertes
Aucune question ouverte bloquante a ce stade.

## 10. Index Des Hypotheses
Aucune hypothese ouverte a ce stade.
