<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="home-connected">
    <h1 class="page-title">Mes kermesses</h1>
    <p style="margin-top:-16px; margin-bottom:24px; color:var(--color-text-muted);">Avec Padlapin, évitez les lapins dans vos événements ! Organisez vos kermesses et gérez les inscriptions des bénévoles facilement.</p>

    <?php if (empty($kermesses)): ?>

        <div class="kermesse-empty-state">
            <p class="kermesse-empty-state__title">Vous n'avez aucune kermesse pour le moment</p>
            <p class="kermesse-empty-state__text">Créez votre première kermesse pour commencer à organiser vos bénévoles.</p>
        </div>

    <?php else: ?>

        <ul class="kermesse-list" role="list">
            <?php foreach ($kermesses as $k): ?>
                <li class="kermesse-card" style="display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <span class="kermesse-card__name" style="flex:1; overflow:hidden; text-overflow:ellipsis;"><?= esc($k['name']) ?></span>
                        <span class="kermesse-status-badge" style="flex-shrink:0;"><?= esc($roleLabels[$k['role']] ?? $k['role']) ?></span>
                    </div>

                    <?php if (!empty($k['event_date']) || !empty($k['location'])): ?>
                    <div style="font-size:14px; color:var(--color-text-muted); display:flex; flex-direction:column; gap:4px;">
                        <?php if (!empty($k['event_date'])): ?>
                        <span>📅 <?= esc($k['event_date']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($k['location'])): ?>
                        <span>📍 <?= esc($k['location']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
