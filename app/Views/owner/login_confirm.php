<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Accéder à votre kermesse – Kermesse</title>
    <meta name="description" content="Confirmez votre connexion à l'espace d'administration.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="form-panel" aria-labelledby="page-title">
        <h1 id="page-title" class="page-title">Accéder à votre espace admin</h1>
        <p>Cliquez sur le bouton ci-dessous pour vous connecter à votre kermesse.</p>
        <form method="post" action="<?= site_url('owner/login/' . esc($rawToken, 'url')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Se connecter</button>
        </form>
        <p class="form-help">
            <a href="<?= esc($loginUrl, 'attr') ?>">Demander un nouveau lien</a>
        </p>
    </section>
</main>
</body>
</html>
