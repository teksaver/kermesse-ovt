<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Créer une kermesse – Kermesse</title>
    <meta name="description" content="Créez votre kermesse et commencez à organiser vos bénévoles.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="form-panel" aria-labelledby="page-title">
        <h1 id="page-title" class="page-title">Créer votre kermesse</h1>
        <p>Remplissez les informations ci-dessous pour démarrer l'organisation de votre kermesse.</p>

        <?php if (! empty($errors)): ?>
        <div class="form-error-summary" role="alert" aria-label="Erreurs du formulaire">
            <p><strong>Veuillez corriger les erreurs suivantes :</strong></p>
            <ul>
	                <?php foreach ($errors as $field => $message): ?>
	                <?php if ($field === 'general'): ?>
	                <li><?= esc($message) ?></li>
	                <?php else: ?>
	                <li><a href="#field-<?= esc($field) ?>"><?= esc($message) ?></a></li>
	                <?php endif; ?>
	                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('kermesses') ?>" novalidate>
            <?= csrf_field() ?>

            <?= view('partials/form_field', [
                'name'     => 'owner_name',
                'label'    => 'Votre nom',
                'type'     => 'text',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
	                'attrs'    => ['maxlength' => 255, 'autocomplete' => 'name'],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'owner_email',
                'label'    => 'Votre adresse email',
                'type'     => 'email',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
	                'attrs'    => ['maxlength' => 320, 'autocomplete' => 'email'],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'kermesse_name',
                'label'    => 'Nom de la kermesse',
                'type'     => 'text',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
	                'attrs'    => ['maxlength' => 255],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'event_date',
                'label'    => 'Date de l\'événement',
                'type'     => 'date',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
	                'attrs'    => [],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'location',
                'label'    => 'Lieu',
                'type'     => 'text',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
	                'attrs'    => ['maxlength' => 255],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'short_description',
                'label'    => 'Description courte',
                'type'     => 'textarea',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
	                'attrs'    => ['maxlength' => 500, 'rows' => 3],
            ]) ?>

            <button type="submit" class="btn btn-primary">Créer ma kermesse</button>
        </form>
    </section>
</main>
</body>
</html>
