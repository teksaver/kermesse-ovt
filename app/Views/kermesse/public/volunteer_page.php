<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= esc($kermesse['name']) ?> – Bénévoles</title>
    <meta name="description" content="Page bénévole de la kermesse : consultez les créneaux et inscrivez-vous quand les inscriptions sont ouvertes.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell app-shell--public">
    <section class="form-panel public-page" aria-labelledby="public-title">

        <div class="admin-header">
            <h1 id="public-title" class="page-title"><?= esc($kermesse['name']) ?></h1>
            <span class="status-badge <?= esc($statusClass) ?>" aria-label="Statut : <?= esc($statusLabel) ?>">
                <?= esc($statusLabel) ?>
            </span>
        </div>

        <?php if (session()->has('success')): ?>
        <div class="alert alert--success" role="alert">
            <?= esc(session('success')) ?>
        </div>
        <?php endif; ?>

        <?php if (session()->has('error')): ?>
        <div class="alert alert--error" role="alert">
            <?= esc(session('error')) ?>
        </div>
        <?php endif; ?>

        <?php if (! empty($kermesse['event_date']) || ! empty($kermesse['location']) || ! empty($kermesse['short_description'])): ?>
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
        <?php endif; ?>

        <?php if ($status === 'preparation'): ?>
        <div class="empty-state" role="status" aria-label="Inscriptions à venir">
            <p class="empty-state__title">Les inscriptions ne sont pas encore ouvertes</p>
            <p class="empty-state__text">Revenez bientôt : les créneaux seront affichés ici dès l'ouverture des inscriptions.</p>
        </div>

        <?php elseif ($status === 'closed'): ?>
        <div class="empty-state" role="status" aria-label="Inscriptions clôturées">
            <p class="empty-state__title">Les inscriptions sont clôturées</p>
            <p class="empty-state__text">Il n'est plus possible de s'inscrire pour cette kermesse. Merci de votre intérêt !</p>
        </div>

        <?php elseif ($hasSlots): ?>
        <p class="public-intro" role="note">Choisissez un créneau pour vous inscrire.</p>
        <div class="stand-list" aria-label="Stands et créneaux">
            <?php foreach ($stands as $stand): ?>
            <?= view('partials/stand_group', ['stand' => $stand]) ?>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div class="empty-state" role="status" aria-label="Aucun créneau">
            <p class="empty-state__title">Aucun créneau disponible pour le moment</p>
            <p class="empty-state__text">Les créneaux seront bientôt mis en ligne. Revenez un peu plus tard.</p>
        </div>
        <?php endif; ?>

    </section>
</main>
</body>
</html>
