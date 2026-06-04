<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérifiez votre email – Kermesse</title>
    <meta name="description" content="Un email de validation a été envoyé.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="confirmation-panel" aria-labelledby="page-title">
        <h1 id="page-title" class="page-title">Vérifiez votre email</h1>
        <p>
            Votre kermesse <strong>« <?= esc($kermesseName) ?> »</strong> a bien été créée.
        </p>
        <?php if ($emailSent ?? true): ?>
        <p>
            Vérifiez votre email pour configurer la kermesse. Un message a été envoyé à
            <strong><?= esc($ownerEmail) ?></strong> avec un lien de validation.
        </p>
        <p>
            Cliquez sur le lien dans l'email pour accéder à l'espace d'administration
            de votre kermesse.
        </p>
        <div class="info-box">
            <p>
                <strong>Vous ne trouvez pas l'email ?</strong>
                Vérifiez votre dossier spam ou courrier indésirable.
                Le lien de validation expire dans 24 heures.
            </p>
        </div>
        <?php else: ?>
        <p>
            L'envoi de l'email a rencontré un <strong>problème technique</strong>.
            Votre kermesse est bien enregistrée, mais l'email d'accès n'a pas pu être envoyé.
        </p>
        <p>
            Utilisez <a href="<?= site_url('owner/login') ?>">me connecter</a> pour recevoir
            un nouveau lien à l'adresse <strong><?= esc($ownerEmail) ?></strong>.
        </p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
