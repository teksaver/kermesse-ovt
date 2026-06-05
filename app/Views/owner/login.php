<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Me connecter – Kermesse</title>
    <meta name="description" content="Saisissez votre email pour recevoir un lien de connexion.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">

    <?php if (! empty($showConfirm)): ?>
    <section class="confirmation-panel" aria-labelledby="page-title">
        <h1 id="page-title" class="page-title">Vérifiez votre email</h1>
        <p>Si cette adresse correspond à une kermesse, un email vient d'être envoyé.</p>
        <div class="info-box">
            <p>Vérifiez votre boîte de réception et cliquez sur le lien de connexion.</p>
        </div>
    </section>

    <?php else: ?>
    <section class="form-panel" aria-labelledby="page-title">
        <h1 id="page-title" class="page-title">Me connecter</h1>
        <p>Saisissez l'adresse email de votre kermesse pour recevoir un lien de connexion.</p>

        <?php if (! empty($errors)): ?>
        <div class="form-error-summary" role="alert" aria-label="Erreurs du formulaire">
            <p><strong>Veuillez corriger les erreurs suivantes :</strong></p>
	            <ul>
	                <?php foreach ($errors as $field => $message): ?>
	                <?php if ($field !== 'owner_email'): ?>
	                <li><?= esc($message) ?></li>
	                <?php else: ?>
	                <li><a href="#field-<?= esc($field) ?>"><?= esc($message) ?></a></li>
	                <?php endif; ?>
	                <?php endforeach; ?>
	            </ul>
        </div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('owner/login') ?>" novalidate>
            <?= csrf_field() ?>

	            <?= view('partials/form_field', [
	                'name'     => 'owner_email',
	                'label'    => 'Votre adresse email',
	                'type'     => 'email',
	                'required' => true,
	                'oldInput' => ['owner_email' => $oldEmail ?? ''],
	                'errors'   => $errors,
	                'attrs'    => ['maxlength' => 320, 'autocomplete' => 'email'],
	            ]) ?>

            <button type="submit" class="btn btn-primary">Recevoir le lien</button>
        </form>
    </section>
    <?php endif; ?>

</main>
</body>
</html>
