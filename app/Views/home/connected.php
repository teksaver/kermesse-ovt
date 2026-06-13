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
                <li class="kermesse-card" style="display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span class="kermesse-card__name"><?= esc($k['name']) ?></span>
                        <span class="kermesse-status-badge"><?= esc($roleLabels[$k['role']] ?? $k['role']) ?></span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <a href="<?= site_url("kermesse/{$k['id']}") ?>" class="btn btn--sm btn--primary">Administration</a>
                        <a href="<?= site_url("k/{$k['public_slug']}") ?>" class="btn btn--sm btn--secondary">Page publique</a>
                    </div>
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
