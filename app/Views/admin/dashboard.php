<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= esc($kermesse['name']) ?> – Espace admin – Kermesse</title>
    <meta name="description" content="Espace d'administration de votre kermesse.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell">
    <section class="form-panel" aria-labelledby="page-title">

        <div class="admin-header">
            <h1 id="page-title" class="page-title"><?= esc($kermesse['name']) ?></h1>
            <span class="status-badge <?= esc($statusClass) ?>" aria-label="Statut : <?= esc($statusLabel) ?>">
                <?= esc($statusLabel) ?>
            </span>
        </div>

        <div class="admin-actions" aria-label="Actions de la kermesse">
            <a href="#" class="btn btn-secondary disabled-action" aria-disabled="true" tabindex="-1">
                Prévisualiser
            </a>
            <a href="#" class="btn btn-secondary disabled-action" aria-disabled="true" tabindex="-1">
                Copier le lien
            </a>
            <?php if ($isOpen): ?>
            <a href="#" class="btn btn-secondary disabled-action" aria-disabled="true" tabindex="-1">
                Fermer les inscriptions
            </a>
            <?php else: ?>
            <span class="btn btn-secondary disabled-action" aria-disabled="true" title="<?= esc($disabledReason) ?>">
                Ouvrir les inscriptions
            </span>
            <?php endif; ?>
        </div>

        <?php if (! $hasStands): ?>
        <div class="empty-state" role="region" aria-label="Aucun stand">
            <p class="empty-state__title">Aucun stand pour le moment</p>
            <p class="empty-state__text">Ajoutez un premier stand pour commencer à construire votre planning.</p>
            <span class="btn btn-primary disabled-action" aria-disabled="true">
                Ajouter un stand
            </span>
        </div>
        <?php endif; ?>

        <?php if (! $hasStands && ! $isOpen): ?>
        <div class="info-box info-box--spaced" role="note">
            <p><?= esc($disabledReason) ?></p>
        </div>
        <?php endif; ?>

    </section>
</main>
</body>
</html>
