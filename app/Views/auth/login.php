<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<main class="container container--narrow">
    <h1 class="page-title">Se connecter</h1>
    <p>Saisissez votre adresse email pour recevoir un lien de connexion.</p>
    <!-- TODO: Story 1.3 — formulaire Magic Link request -->
    <?= view('partials/form_errors', ['errors' => $errors ?? []]) ?>
<form method="post" action="<?= site_url('auth/login') ?>">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="email" class="form-label">Adresse email</label>
            <input type="email" id="email" name="email" class="form-input" required autocomplete="email">
        </div>
        <button type="submit" class="btn btn--primary">Recevoir le lien</button>
    </form>
</main>
<?= $this->endSection() ?>
