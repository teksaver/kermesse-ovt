<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inscription confirmée – <?= esc($kermesseName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell app-shell--public">
    <section class="form-panel public-page" aria-labelledby="confirmation-title">

        <h1 id="confirmation-title" class="page-title">Inscription confirmée !</h1>

        <div class="confirmation-panel" role="status" aria-live="polite">
            <p class="confirmation-message">
                Votre inscription à <strong><?= esc($kermesseName) ?></strong> est bien enregistrée.
            </p>
            <p class="confirmation-email-notice">
                Un email de confirmation avec votre lien de gestion vous a été envoyé.
                Si vous ne le recevez pas, vérifiez vos courriers indésirables.
            </p>
        </div>

        <div class="signup-back">
            <a href="<?= site_url('k/' . esc($publicSlug, 'attr')) ?>" class="back-link">← Retour aux créneaux</a>
        </div>

    </section>
</main>
</body>
</html>
