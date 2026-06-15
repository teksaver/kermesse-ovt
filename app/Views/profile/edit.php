<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="kermesse-dashboard">
    <h1 class="page-title"><?= esc($title) ?></h1>

    <div class="kermesse-dashboard__info kermesse-characteristics" style="max-width: 600px; margin: 0 auto;">
        <?php if (! empty($success = session()->getFlashdata('success'))): ?>
        <div class="form-success">
            <?= esc($success) ?>
        </div>
        <?php endif; ?>

        <?php if (! empty($error = session()->getFlashdata('error'))): ?>
        <div class="form-error">
            <?= esc($error) ?>
        </div>
        <?php endif; ?>

        <?php $errors = session()->getFlashdata('errors') ?? []; ?>
        <?= view('partials/form_errors', ['errors' => $errors]) ?>

        <form method="post" action="<?= site_url('profile') ?>">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="first_name" class="form-label">Prénom</label>
                <input type="text" id="first_name" name="first_name" class="form-control"
                       value="<?= esc(old('first_name', $user['first_name'] ?? '')) ?>"
                       maxlength="100" required>
            </div>

            <div class="form-group">
                <label for="last_name" class="form-label">Nom</label>
                <input type="text" id="last_name" name="last_name" class="form-control"
                       value="<?= esc(old('last_name', $user['last_name'] ?? '')) ?>"
                       maxlength="100" required>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Adresse email</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= esc(old('email', $user['email'] ?? '')) ?>"
                       maxlength="255" required>
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Numéro de téléphone (optionnel)</label>
                <input type="tel" id="phone" name="phone" class="form-control"
                       value="<?= esc(old('phone', $user['phone'] ?? '')) ?>"
                       maxlength="20">
            </div>

            <div class="k-modal__actions" style="margin-top: 24px;">
                <a href="<?= site_url('/') ?>" class="btn btn--secondary">Annuler</a>
                <button type="submit" class="btn btn--primary">Enregistrer les modifications</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
