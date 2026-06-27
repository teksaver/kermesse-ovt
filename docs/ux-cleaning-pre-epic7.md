# Nettoyage UX pré-EPIC 7

## Contexte

Avant de démarrer l'EPIC 7, le tableau de bord organisateur doit être plus lisible et mieux préserver le contexte de travail. Les irritants identifiés concernent l'orientation dans les onglets, la compréhension des statuts d'inscription, l'exploitation de la largeur de page et les informations affichées sur l'accueil connecté.

Ce changement est volontairement limité à un nettoyage UX de l'existant. Il ne modifie pas les routes internes, les slugs d'onglets, les tables SQL ni les statuts métier stockés.

## Besoin UX

1. Quand un Owner/Admin invite ou administre un membre depuis l'onglet équipe, il doit rester dans ce contexte après la soumission.
2. Les noms de sections doivent être explicites :
   - `Modification` devient `Stands et créneaux` (contenu pur stands/créneaux une fois les actions globales extraites dans le header).
   - `Équipe` devient `Équipe d'organisation`.
3. La section `Gestion des inscrits` doit mieux exploiter la largeur desktop tout en restant lisible sur mobile.
4. Chaque inscrit visible dans `Gestion des inscrits` doit afficher un badge de validation compréhensible :
   - `Confirmé` pour une inscription validée.
   - `À confirmer` pour une inscription orpheline ou non confirmée, y compris lorsqu'elle a été créée par un admin.
5. L'accueil connecté doit afficher les informations de base des kermesses, notamment leur statut métier.
6. Chaque inscrit dans `Gestion des inscrits` doit tenir sur une seule ligne (desktop ≥ 600 px) : nom + badge statut, contact, puis actions en icônes emoji à droite. Sur mobile la ligne peut se replier.
7. Les actions admin par inscrit (modifier, déplacer, annuler) sont représentées par des boutons icônes (✏️ ↗️ 🗑️) avec `title` et `aria-label` incluant le nom du bénévole. Les panneaux `<details>` s'ouvrent toujours en dessous.
8. Le bouton `+ Ajouter un bénévole` ne s'affiche que si `remaining > 0`. Quand le créneau est complet, un badge `Créneau complet` le remplace.

## Contraintes

- Les identifiants d'onglets restent inchangés : `modification`, `inscrits`, `equipe`, `participations`.
- Les boutons Paramètres et lifecycle (Ouvrir/Fermer les inscriptions) sont extraits de l'onglet et placés dans un `div.kermesse-header-actions` persistant dans le header de la kermesse, visibles uniquement pour Owner/Admin (`$canModify`). Cette séparation permet à l'onglet `Stands et créneaux` de n'exposer que du contenu stands/créneaux.
- L'onglet actif par défaut est déterminé par le statut de la kermesse : `préparation` → `modification`, `ouvert`/`clôturé` → `inscrits`, sinon premier onglet disponible.
- Les vues ne calculent pas la logique métier : les libellés/classes de badges sont préparés par les contrôleurs.
- Aucune donnée personnelle ne doit être exposée hors des surfaces déjà autorisées.
- Le rendu reste compatible mobile-first 320px ; les lignes d'inscrits se replient en flex-wrap sur petits écrans.
- Aucun build frontend n'est introduit.
- Les cibles tactiles des boutons icônes sont au minimum 44 × 44 px.
- Les boutons icônes portent `title` (tooltip desktop) et `aria-label` explicite incluant le nom du bénévole.

## Critères d'acceptation

- Given un Owner/Admin invite une personne, when la soumission réussit, then la redirection cible `#equipe`.
- Given un Owner/Admin modifie, relance ou révoque un membre d'équipe, when la soumission réussit, then la redirection cible aussi `#equipe`.
- Given un utilisateur autorisé ouvre le dashboard, when les onglets sont rendus, then les libellés visibles sont `Stands et créneaux`, `Gestion des inscrits`, `Équipe d'organisation` et `Mes participations` selon les droits.
- Given un Owner/Admin ouvre le dashboard, when le header est rendu, then les boutons Paramètres et lifecycle (Ouvrir/Fermer/Rouvrir) sont présents dans le header, indépendamment de l'onglet actif.
- Given une inscription confirmée et une inscription à confirmer, when `Gestion des inscrits` s'affiche, then les deux badges sont distincts et préparés dans le View Model.
- Given une inscription orpheline créée par admin avec `created_by` renseigné, when `Gestion des inscrits` s'affiche, then le badge reste `À confirmer`.
- Given un utilisateur connecté a des kermesses, when il ouvre `/`, then chaque carte affiche le nom, le rôle, le statut métier, la date/le lieu si disponibles et les actions existantes.
- Given un créneau avec `remaining > 0`, when `Gestion des inscrits` s'affiche, then le bouton `+ Ajouter un bénévole` est visible.
- Given un créneau complet (`remaining = 0`), when `Gestion des inscrits` s'affiche, then le bouton est remplacé par le badge `Créneau complet` et aucune modale ne peut être ouverte.
- Given un écran ≥ 600 px, when `Gestion des inscrits` s'affiche, then chaque ligne d'inscrit tient sur une seule ligne (nom, contact, icônes d'action).
- Given les icônes d'action (✏️ ↗️ 🗑️), when l'utilisateur survole ou navigue au clavier, then un `title` et un `aria-label` explicites incluant le nom du bénévole sont présents.

## Points de revue

- `app/Controllers/Kermesse/Dashboard/KermesseAdminController.php` : libellés d'onglets (`Stands et créneaux`, `Équipe d'organisation`), redirections `#equipe`, onglet par défaut selon statut, calcul View Model des badges inscrits.
- `app/Views/kermesse/dashboard.php` : rendu des nouveaux libellés et badges sans logique métier.
- `app/Controllers/Home/HomeController.php` et `app/Views/home/connected.php` : statut métier sur l'accueil connecté.
- `public/assets/css/app.css` : layout desktop de `Gestion des inscrits` et structure des cartes d'accueil.
- `tests/feature/InviteRoleTest.php` : redirections d'équipe.
- `tests/feature/ManageParticipantsTest.php` : badges `Confirmé` / `À confirmer`, y compris le cas orphelin créé par admin.
- `tests/feature/ConnectedHomeTest.php` : statut métier sur l'accueil connecté.
- `tests/feature/DashboardTabNavigationTest.php` : nouveaux libellés d'onglets.

## Vérifications exécutées

- `vendor/bin/phpunit tests/feature/DashboardTabNavigationTest.php tests/feature/InviteRoleTest.php tests/feature/ManageParticipantsTest.php tests/feature/ConnectedHomeTest.php`
- `vendor/bin/phpunit --exclude-group mariadb`
- `composer analyse`

