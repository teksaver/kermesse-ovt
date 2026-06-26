<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="home-connected">
    <h1 class="page-title">Mes kermesses</h1>
    <p style="margin-top:-16px; margin-bottom:24px; color:var(--color-text-muted);">Avec Padlapin, évitez les lapins dans vos événements ! Organisez vos kermesses et gérez les inscriptions des bénévoles facilement.</p>

    <?php if (! empty($participationNotice = session()->getFlashdata('participation_notice'))): ?>
    <p class="form-success"><?= esc($participationNotice) ?></p>
    <?php endif; ?>
    <?php if (! empty($participationError = session()->getFlashdata('participation_error'))): ?>
    <p class="form-error" role="alert"><?= esc($participationError) ?></p>
    <?php endif; ?>

    <?php if (empty($kermesses)): ?>

        <div class="kermesse-empty-state">
            <p class="kermesse-empty-state__title">Vous n'avez aucune kermesse pour le moment</p>
            <p class="kermesse-empty-state__text">Créez votre première kermesse pour commencer à organiser vos bénévoles.</p>
        </div>

    <?php else: ?>

        <ul class="kermesse-list" role="list">
            <?php foreach ($kermesses as $k): ?>
                <li class="kermesse-card kermesse-card--stacked">
                    <div class="kermesse-card__header">
                        <span class="kermesse-card__name"><?= esc($k['name']) ?></span>
                        <span class="kermesse-status-badge kermesse-card__role-badge"><?= esc($roleLabels[$k['role']] ?? $k['role']) ?></span>
                    </div>

                    <div class="kermesse-card__meta-row">
                        <span class="kermesse-status-badge kermesse-status-badge--<?= esc($k['status']) ?>"><?= esc($k['status_label'] ?? $k['status']) ?></span>
                    </div>

                    <?php if (!empty($k['event_date']) || !empty($k['location'])): ?>
                    <div class="kermesse-card__details">
                        <?php if (!empty($k['event_date'])): ?>
                        <span>📅 <?= esc($k['event_date']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($k['location'])): ?>
                        <span>📍 <?= esc($k['location']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="kermesse-card__actions">
                        <a href="<?= site_url("kermesse/{$k['id']}") ?>" class="btn btn--sm btn--primary"><?= $k['role'] === 'benevole' ? 'Mes inscriptions' : 'Administration' ?></a>
                        <a href="<?= site_url("k/{$k['public_slug']}") ?>" class="btn btn--sm btn--secondary"><?= $k['role'] === 'benevole' ? 'Nouvelle inscription' : 'Page publique' ?></a>
                        <?php if (! empty($k['canLeave'])): ?>
                        <form method="post" action="<?= site_url("kermesse/{$k['id']}/leave") ?>" onsubmit="if (!confirm('Voulez-vous quitter cette kermesse ?')) { return false; } this.querySelector('button[type=submit]').disabled = true;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--sm btn--secondary">Quitter cette kermesse</button>
                        </form>
                        <?php endif; ?>
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
