<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion à votre kermesse – Kermesse</title>
    <meta name="description" content="Résultat de la tentative de connexion.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="result-panel" aria-labelledby="page-title">

        <?php if ($status === 'expired_token'): ?>
        <h1 id="page-title" class="page-title">Ce lien de connexion a expiré</h1>
        <p>Votre lien de connexion a expiré. Demandez un nouveau lien pour continuer.</p>
        <a href="<?= esc($loginUrl, 'attr') ?>" class="btn btn-primary">Demander un nouveau lien</a>

        <?php elseif ($status === 'used_token'): ?>
        <h1 id="page-title" class="page-title">Ce lien de connexion a déjà été utilisé</h1>
        <p>Ce lien ne peut plus être utilisé. Demandez un nouveau lien pour vous connecter.</p>
        <a href="<?= esc($loginUrl, 'attr') ?>" class="btn btn-primary">Demander un nouveau lien</a>

        <?php elseif ($status === 'revoked_token'): ?>
        <h1 id="page-title" class="page-title">Ce lien de connexion n'est plus valide</h1>
        <p>Ce lien n'est plus valide. Demandez un nouveau lien pour vous connecter.</p>
        <a href="<?= esc($loginUrl, 'attr') ?>" class="btn btn-primary">Demander un nouveau lien</a>

        <?php else: ?>
        <h1 id="page-title" class="page-title">Ce lien de connexion n'est plus valide</h1>
        <p>Ce lien n'est pas reconnu. Demandez un nouveau lien pour vous connecter.</p>
        <a href="<?= esc($loginUrl, 'attr') ?>" class="btn btn-primary">Demander un nouveau lien</a>
        <?php endif; ?>

    </section>
</main>
</body>
</html>
