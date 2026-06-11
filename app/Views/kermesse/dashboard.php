<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="kermesse-dashboard">
    <h1 class="page-title"><?= esc($kermesse['name']) ?></h1>

    <div class="kermesse-dashboard__info">
        <?= $this->include('partials/status_badge', ['status' => $kermesse['status']]) ?>

        <?php if (! empty($kermesse['event_date'])): ?>
        <p class="kermesse-dashboard__date"><?= esc($kermesse['event_date']) ?></p>
        <?php endif; ?>

        <?php if (! empty($kermesse['location'])): ?>
        <p class="kermesse-dashboard__location"><?= esc($kermesse['location']) ?></p>
        <?php endif; ?>

        <?php if (! empty($kermesse['short_description'])): ?>
        <p class="kermesse-dashboard__description"><?= esc($kermesse['short_description']) ?></p>
        <?php endif; ?>
    </div>

    <div class="kermesse-dashboard__actions">
        <a href="<?= site_url('/') ?>" class="btn btn--secondary">Retour à mes kermesses</a>
    </div>
</div>

<?= $this->endSection() ?>
