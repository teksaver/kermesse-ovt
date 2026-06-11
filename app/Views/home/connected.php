<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="home-connected">
    <h1 class="page-title">Mes kermesses</h1>

    <?php if (empty($kermesses)): ?>

        <div class="kermesse-empty-state">
            <p class="kermesse-empty-state__title">Vous n'avez aucune kermesse pour le moment</p>
            <p class="kermesse-empty-state__text">Créez votre première kermesse pour commencer à organiser vos bénévoles.</p>
        </div>

    <?php else: ?>

        <ul class="kermesse-list" role="list">
            <?php foreach ($kermesses as $k): ?>
                <li class="kermesse-card">
                    <span class="kermesse-card__name"><?= esc($k['name']) ?></span>
                    <span class="kermesse-status-badge"><?= esc($roleLabels[$k['role']] ?? $k['role']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

    <div class="home-connected__actions">
        <a href="<?= site_url('kermesse/create') ?>" class="btn btn--primary btn--large">
            Créer une nouvelle kermesse
        </a>
    </div>
</div>

<?= $this->endSection() ?>
