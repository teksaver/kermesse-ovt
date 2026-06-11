<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
<main class="container container--narrow">
    <h1 class="page-title">Lien invalide ou expiré</h1>
    <p>Ce lien de connexion n'est plus valide. Il a peut-être expiré ou déjà été utilisé.</p>
    <p><a href="<?= site_url('auth/login') ?>">Demander un nouveau lien de connexion</a></p>
</main>
<?= $this->endSection() ?>
