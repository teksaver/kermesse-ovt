# Nettoyage UX pré-EPIC 7

## Contexte

Avant de démarrer l'EPIC 7, le tableau de bord organisateur doit être plus lisible et mieux préserver le contexte de travail. Les irritants identifiés concernent l'orientation dans les onglets, la compréhension des statuts d'inscription, l'exploitation de la largeur de page et les informations affichées sur l'accueil connecté.

Ce changement est volontairement limité à un nettoyage UX de l'existant. Il ne modifie pas les routes internes, les slugs d'onglets, les tables SQL ni les statuts métier stockés.

## Besoin UX

1. Quand un Owner/Admin invite ou administre un membre depuis l'onglet équipe, il doit rester dans ce contexte après la soumission.
2. Les noms de sections doivent être explicites :
   - `Modification` devient `Gestion des stands`.
   - `Équipe` devient `Équipe d'organisation`.
3. La section `Gestion des inscrits` doit mieux exploiter la largeur desktop tout en restant lisible sur mobile.
4. Chaque inscrit visible dans `Gestion des inscrits` doit afficher un badge de validation compréhensible :
   - `Confirmé` pour une inscription validée.
   - `À confirmer` pour une inscription orpheline ou non confirmée, y compris lorsqu'elle a été créée par un admin.
5. L'accueil connecté doit afficher les informations de base des kermesses, notamment leur statut métier.

## Contraintes

- Les identifiants d'onglets restent inchangés : `modification`, `inscrits`, `equipe`, `participations`.
- Les vues ne calculent pas la logique métier : les libellés/classes de badges sont préparés par les contrôleurs.
- Aucune donnée personnelle ne doit être exposée hors des surfaces déjà autorisées.
- Le rendu reste compatible mobile-first 320px.
- Aucun build frontend n'est introduit.

## Critères d'acceptation

- Given un Owner/Admin invite une personne, when la soumission réussit, then la redirection cible `#equipe`.
- Given un Owner/Admin modifie, relance ou révoque un membre d'équipe, when la soumission réussit, then la redirection cible aussi `#equipe`.
- Given un utilisateur autorisé ouvre le dashboard, when les onglets sont rendus, then les libellés visibles sont `Gestion des stands`, `Gestion des inscrits`, `Équipe d'organisation` et `Mes participations` selon les droits.
- Given une inscription confirmée et une inscription à confirmer, when `Gestion des inscrits` s'affiche, then les deux badges sont distincts et préparés dans le View Model.
- Given une inscription orpheline créée par admin avec `created_by` renseigné, when `Gestion des inscrits` s'affiche, then le badge reste `À confirmer`.
- Given un utilisateur connecté a des kermesses, when il ouvre `/`, then chaque carte affiche le nom, le rôle, le statut métier, la date/le lieu si disponibles et les actions existantes.

## Points de revue

- `app/Controllers/Kermesse/Dashboard/KermesseAdminController.php` : libellés d'onglets, redirections `#equipe`, calcul View Model des badges inscrits.
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

