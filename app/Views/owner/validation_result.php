<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Validation de votre kermesse – Kermesse</title>
    <meta name="description" content="Résultat de la validation de votre adresse email.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="result-panel" aria-labelledby="page-title">

	        <?php if ($status === 'expired'): ?>
	        <h1 id="page-title" class="page-title">Ce lien a expiré</h1>
	        <p>Votre lien de validation a expiré. Demandez un nouveau lien pour continuer.</p>
	        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Demander un nouveau lien</a>

        <?php elseif ($status === 'already_active'): ?>
        <h1 id="page-title" class="page-title">Email déjà validé</h1>
        <p>Votre email est déjà validé. Ce lien ne peut plus être utilisé.</p>
        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Me connecter</a>

        <?php elseif ($status === 'used'): ?>
        <h1 id="page-title" class="page-title">Lien déjà utilisé</h1>
        <p>Ce lien a déjà été utilisé. Demandez un nouveau lien pour continuer.</p>
        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Demander un nouveau lien</a>

        <?php elseif ($status === 'revoked'): ?>
        <h1 id="page-title" class="page-title">Ce lien n'est plus valide</h1>
        <p>Ce lien n'est plus valide. Demandez un nouveau lien pour continuer.</p>
        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Demander un nouveau lien</a>

        <?php elseif ($status === 'validation_required'): ?>
        <h1 id="page-title" class="page-title">Validation requise</h1>
        <p>Validez votre email avant d'accéder à l'espace admin.</p>
        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Me connecter</a>

        <?php elseif ($status === 'access_denied'): ?>
        <h1 id="page-title" class="page-title">Accès refusé</h1>
	        <p>Vous n'avez pas accès à cette page.</p>
	        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Retour à la connexion</a>
	
	        <?php elseif ($status === 'invalid'): ?>
	        <h1 id="page-title" class="page-title">Ce lien n'est plus valide</h1>
	        <p>Ce lien n'est pas reconnu. Demandez un nouveau lien pour continuer.</p>
	        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Demander un nouveau lien</a>

	        <?php else: ?>
	        <h1 id="page-title" class="page-title">Ce lien n'est plus valide</h1>
	        <p>Demandez un nouveau lien pour continuer.</p>
	        <a href="<?= esc($loginUrl) ?>" class="btn btn-primary">Demander un nouveau lien</a>
	        <?php endif; ?>

    </section>
</main>
</body>
</html>
