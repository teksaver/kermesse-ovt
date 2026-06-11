<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="page-create-kermesse">
    <h1 class="page-title">Créer une kermesse</h1>

    <?= view('partials/form_errors', ['errors' => $errors]) ?>

    <form method="post" action="<?= site_url('kermesses') ?>" novalidate>
        <?= csrf_field() ?>

        <?= view('partials/form_field', [
            'name'     => 'name',
            'label'    => 'Nom de la kermesse',
            'type'     => 'text',
            'required' => true,
            'oldInput' => $oldInput,
            'errors'   => $errors,
            'attrs'    => ['maxlength' => '255', 'autocomplete' => 'off'],
        ]) ?>

        <?= view('partials/form_field', [
            'name'     => 'event_date',
            'label'    => "Date de l'événement",
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
            'attrs'    => ['maxlength' => '255'],
        ]) ?>

        <?= view('partials/form_field', [
            'name'     => 'short_description',
            'label'    => 'Description courte',
            'type'     => 'textarea',
            'required' => false,
            'oldInput' => $oldInput,
            'errors'   => $errors,
            'attrs'    => ['maxlength' => '500', 'rows' => '3'],
        ]) ?>

        <?php if (! $isLoggedIn): ?>

        <fieldset class="fieldset-guest">
            <legend class="fieldset-guest__legend">Vos coordonnées d'organisateur</legend>

            <?= view('partials/form_field', [
                'name'     => 'first_name',
                'label'    => 'Prénom',
                'type'     => 'text',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
                'attrs'    => ['maxlength' => '100', 'autocomplete' => 'given-name'],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'last_name',
                'label'    => 'Nom',
                'type'     => 'text',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
                'attrs'    => ['maxlength' => '100', 'autocomplete' => 'family-name'],
            ]) ?>

            <?= view('partials/form_field', [
                'name'     => 'email',
                'label'    => 'Adresse email',
                'type'     => 'email',
                'required' => true,
                'oldInput' => $oldInput,
                'errors'   => $errors,
                'attrs'    => ['maxlength' => '320', 'autocomplete' => 'email'],
            ]) ?>
        </fieldset>

        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Créer ma kermesse</button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
