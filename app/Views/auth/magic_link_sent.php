<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<main class="container container--narrow">
    <h1 class="page-title">Vérifiez votre boîte mail</h1>
    <p>Un lien de connexion a été envoyé à <strong><?= esc($email ?? 'votre adresse') ?></strong>.</p>
    <p>Le lien est valable 15 minutes. Si vous ne le trouvez pas, vérifiez vos spams.</p>
</main>
<?= $this->endSection() ?>
