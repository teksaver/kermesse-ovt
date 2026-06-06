<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Prévisualisation – <?= esc($kermesse['name']) ?> – Kermesse</title>
    <meta name="description" content="Prévisualisation admin en lecture seule du planning de la kermesse.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="form-panel" aria-labelledby="preview-title">

        <p class="preview-note" role="note">
            Prévisualisation en lecture seule. Cette page est réservée à l'organisation et ne remplace pas la page publique.
        </p>

        <div class="admin-header">
            <h1 id="preview-title" class="page-title"><?= esc($kermesse['name']) ?></h1>
            <span class="status-badge <?= esc($statusClass) ?>" aria-label="Statut : <?= esc($statusLabel) ?>">
                <?= esc($statusLabel) ?>
            </span>
        </div>

        <dl class="preview-meta">
            <?php if (! empty($kermesse['event_date'])): ?>
            <div>
                <dt>Date</dt>
                <dd><?= esc($kermesse['event_date']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (! empty($kermesse['location'])): ?>
            <div>
                <dt>Lieu</dt>
                <dd><?= esc($kermesse['location']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (! empty($kermesse['short_description'])): ?>
            <div>
                <dt>Description</dt>
                <dd><?= esc($kermesse['short_description']) ?></dd>
            </div>
            <?php endif; ?>
        </dl>

        <div class="preview-totals" role="status">
            <span><?= esc($totalCapacity) ?> places au total</span>
            <span><?= esc($totalRemaining) ?> places restantes</span>
        </div>

        <?php if ($hasStands): ?>
        <div class="stand-list" aria-label="Stands et créneaux">
            <?php foreach ($stands as $stand): ?>
            <div class="stand-group">
                <div class="stand-group__header">
                    <span class="stand-group__name"><?= esc($stand['name']) ?></span>
                </div>

                <?php if (! empty($stand['slots'])): ?>
                <div class="slot-list" aria-label="Créneaux de <?= esc($stand['name']) ?>">
                    <?php foreach ($stand['slots'] as $slot): ?>
                    <div class="slot-row slot-row--preview">
                        <div class="slot-row__info">
                            <span class="slot-row__time"><?= esc($slot['displayTime']) ?></span>
                            <span class="slot-row__capacity">
                                <?= esc($slot['capacity']) ?> places au total,
                                <?= esc($slot['remainingSpots']) ?> places restantes
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="slot-empty-state" aria-label="Créneaux de <?= esc($stand['name']) ?>">
                    <p class="slot-empty-state__text">Aucun créneau pour le moment</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" role="region" aria-label="Aucun stand">
            <p class="empty-state__title">Aucun stand pour le moment</p>
            <p class="empty-state__text">Ajoutez des stands et des créneaux pour construire votre planning.</p>
        </div>
        <?php endif; ?>

        <div class="preview-actions">
            <a href="<?= esc($dashboardUrl) ?>" class="btn btn-secondary">Retour à l'administration</a>
        </div>

    </section>
</main>
</body>
</html>
