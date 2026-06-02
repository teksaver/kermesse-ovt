<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Kermesse</title>
    <meta name="description" content="Application de gestion de kermesse">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="status-panel" aria-labelledby="page-title">
        <p class="eyebrow">Fondation applicative</p>
        <h1 id="page-title">Kermesse</h1>
        <p>
            L'application CodeIgniter est initialisee. Les parcours owner, admin et benevole
            seront ajoutes dans les stories suivantes.
        </p>
        <dl class="status-list">
            <div>
                <dt>Framework</dt>
                <dd>CodeIgniter 4</dd>
            </div>
            <div>
                <dt>Production</dt>
                <dd>Runtime PHP, assets statiques, configuration par environnement</dd>
            </div>
        </dl>
    </section>
</main>
<script src="<?= base_url('assets/js/app.js') ?>" defer></script>
</body>
</html>
