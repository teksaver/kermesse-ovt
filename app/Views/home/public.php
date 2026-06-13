<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>

<div class="home-public">
    <header class="home-public__header">
        <h1 class="home-public__title">Padlapin</h1>
        <p class="home-public__subtitle">Avec Padlapin, évitez les lapins dans vos événements !<br>Organisez vos kermesses et gérez les inscriptions des bénévoles facilement.</p>
    </header>

    <div class="home-public__actions">
        <a href="<?= site_url('kermesse/create') ?>" class="btn btn--primary btn--large btn--block">
            Créer une kermesse
        </a>
        <a href="<?= site_url('auth/login') ?>" class="btn btn--secondary btn--large btn--block">
            Me connecter
        </a>
    </div>
</div>

<?= $this->endSection() ?>
