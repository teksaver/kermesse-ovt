<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inscription – <?= esc($summary['kermesseName']) ?></title>
    <meta name="description" content="Formulaire d'inscription bénévole pour la kermesse.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="app-shell app-shell--public">
    <section class="form-panel public-page" aria-labelledby="signup-title">

        <h1 id="signup-title" class="page-title">Inscription bénévole</h1>

        <div class="signup-summary" aria-label="Résumé du créneau">
            <dl class="signup-summary__list preview-meta">
                <div>
                    <dt>Kermesse</dt>
                    <dd><?= esc($summary['kermesseName']) ?></dd>
                </div>
                <div>
                    <dt>Stand</dt>
                    <dd><?= esc($summary['standName']) ?></dd>
                </div>
                <div>
                    <dt>Créneau</dt>
                    <dd><?= esc($summary['displayTime']) ?></dd>
                </div>
                <div>
                    <dt>Places restantes</dt>
                    <dd><?= esc($summary['remainingSpots']) ?> sur <?= esc($summary['capacity']) ?></dd>
                </div>
            </dl>
        </div>

        <?php if (count($errors) >= 2): ?>
        <div class="form-error-summary" role="alert" aria-label="Erreurs du formulaire">
            <p>Veuillez corriger les erreurs suivantes :</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?= esc($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post"
            action="<?= site_url('k/' . esc($summary['publicSlug'], 'attr') . '/slots/' . esc($summary['slotId'], 'attr') . '/signup') ?>"
            novalidate>
            <?= csrf_field() ?>

            <div class="form-group<?= isset($errors['first_name']) ? ' form-group--error' : '' ?>">
                <label for="first_name">Prénom <span aria-hidden="true">*</span></label>
                <input id="first_name" type="text" name="first_name"
                    value="<?= esc($fields['first_name'] ?? '') ?>"
                    placeholder="Ex : Marie"
                    autocomplete="given-name"
                    required>
                <?php if (isset($errors['first_name'])): ?>
                <p class="field-error" role="alert"><?= esc($errors['first_name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group<?= isset($errors['last_name']) ? ' form-group--error' : '' ?>">
                <label for="last_name">Nom <span aria-hidden="true">*</span></label>
                <input id="last_name" type="text" name="last_name"
                    value="<?= esc($fields['last_name'] ?? '') ?>"
                    placeholder="Ex : Dupont"
                    autocomplete="family-name"
                    required>
                <?php if (isset($errors['last_name'])): ?>
                <p class="field-error" role="alert"><?= esc($errors['last_name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group<?= isset($errors['email']) ? ' form-group--error' : '' ?>">
                <label for="email">Email <span aria-hidden="true">*</span></label>
                <input id="email" type="email" name="email"
                    value="<?= esc($fields['email'] ?? '') ?>"
                    placeholder="Ex : marie@exemple.fr"
                    autocomplete="email"
                    required>
                <?php if (isset($errors['email'])): ?>
                <p class="field-error" role="alert"><?= esc($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-group<?= isset($errors['phone']) ? ' form-group--error' : '' ?>">
                <label for="phone">Téléphone <span class="signup-optional">(facultatif)</span></label>
                <input id="phone" type="tel" name="phone"
                    value="<?= esc($fields['phone'] ?? '') ?>"
                    placeholder="Ex : 06 12 34 56 78"
                    autocomplete="tel">
                <?php if (isset($errors['phone'])): ?>
                <p class="field-error" role="alert"><?= esc($errors['phone']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">S'inscrire</button>
            </div>
        </form>

        <div class="signup-back">
            <a href="<?= site_url('k/' . esc($summary['publicSlug'], 'attr')) ?>" class="back-link">← Retour aux créneaux</a>
        </div>

    </section>
</main>
</body>
</html>
