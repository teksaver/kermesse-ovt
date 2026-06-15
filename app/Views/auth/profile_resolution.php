<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<section class="form-panel profile-resolution" aria-labelledby="resolution-title">

    <h1 id="resolution-title" class="page-title"><?= esc($title) ?></h1>

    <?php if (isset($errors['global'])): ?>
        <div class="form-service-error" role="alert">
            <?= esc($errors['global']) ?>
        </div>
    <?php elseif (session()->getFlashdata('error')): ?>
        <div class="form-service-error" role="alert">
            <?= esc(session()->getFlashdata('error')) ?>
        </div>
    <?php endif; ?>

<?php if (($mode ?? '') === 'first_login'): ?>

    <?php
    $old    = $old    ?? [];
    $errors = $errors ?? [];
    ?>

    <p class="profile-resolution__intro">
        Bienvenue ! Avant de continuer, vérifiez et complétez vos coordonnées.
        Elles seront utilisées pour toutes vos participations.
    </p>

    <form method="post" action="<?= site_url('auth/profile-resolution') ?>" novalidate>
        <?= csrf_field() ?>

        <div class="form-group<?= isset($errors['first_name']) ? ' form-group--error' : '' ?>">
            <label for="first_name" class="form-label">Prénom <span aria-hidden="true">*</span></label>
            <?php if (isset($errors['first_name'])): ?>
                <p class="form-error" id="first_name-error"><?= esc($errors['first_name']) ?></p>
            <?php endif; ?>
            <input
                type="text"
                id="first_name"
                name="first_name"
                class="form-input"
                value="<?= esc($old['first_name'] ?? $user['first_name'] ?? '') ?>"
                required
                autocomplete="given-name"
                <?= isset($errors['first_name']) ? 'aria-describedby="first_name-error" aria-invalid="true"' : '' ?>
            >
        </div>

        <div class="form-group<?= isset($errors['last_name']) ? ' form-group--error' : '' ?>">
            <label for="last_name" class="form-label">Nom <span aria-hidden="true">*</span></label>
            <?php if (isset($errors['last_name'])): ?>
                <p class="form-error" id="last_name-error"><?= esc($errors['last_name']) ?></p>
            <?php endif; ?>
            <input
                type="text"
                id="last_name"
                name="last_name"
                class="form-input"
                value="<?= esc($old['last_name'] ?? $user['last_name'] ?? '') ?>"
                required
                autocomplete="family-name"
                <?= isset($errors['last_name']) ? 'aria-describedby="last_name-error" aria-invalid="true"' : '' ?>
            >
        </div>

        <div class="form-group">
            <label for="email" class="form-label">Email</label>
            <input
                type="email"
                id="email"
                class="form-input"
                value="<?= esc($user['email'] ?? '') ?>"
                disabled
                aria-describedby="email-hint"
            >
            <p id="email-hint" class="form-hint">L'adresse email ne peut pas être modifiée ici.</p>
        </div>

        <div class="form-group<?= isset($errors['phone']) ? ' form-group--error' : '' ?>">
            <label for="phone" class="form-label">Téléphone</label>
            <?php if (isset($errors['phone'])): ?>
                <p class="form-error" id="phone-error"><?= esc($errors['phone']) ?></p>
            <?php endif; ?>
            <input
                type="tel"
                id="phone"
                name="phone"
                class="form-input"
                value="<?= esc($old['phone'] ?? $user['phone'] ?? '') ?>"
                autocomplete="tel"
                <?= isset($errors['phone']) ? 'aria-describedby="phone-error" aria-invalid="true"' : '' ?>
            >
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Confirmer et continuer</button>
        </div>

    </form>

<?php else: ?>

    <p class="profile-resolution__intro">
        Lors d'une inscription récente en tant que bénévole, vous avez renseigné des coordonnées
        différentes de celles de votre profil. Souhaitez-vous mettre à jour votre profil ?
    </p>

    <div class="profile-resolution__comparison">

        <div class="profile-resolution__column">
            <h2 class="profile-resolution__subtitle">Profil actuel</h2>
            <dl class="profile-resolution__details">
                <div>
                    <dt>Prénom</dt>
                    <dd><?= esc($storedUser['first_name'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Nom</dt>
                    <dd><?= esc($storedUser['last_name'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Téléphone</dt>
                    <dd><?= esc($storedUser['phone'] ?: '—') ?></dd>
                </div>
            </dl>
        </div>

        <div class="profile-resolution__column">
            <h2 class="profile-resolution__subtitle">Informations soumises</h2>
            <dl class="profile-resolution__details">
                <div>
                    <dt>Prénom</dt>
                    <dd><?= esc($divergence['submitted_first_name'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Nom</dt>
                    <dd><?= esc($divergence['submitted_last_name'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Téléphone</dt>
                    <dd><?= esc($divergence['submitted_phone'] ?: '—') ?></dd>
                </div>
            </dl>
        </div>

    </div>

    <form method="post" action="<?= site_url('auth/profile-resolution') ?>" novalidate>
        <?= csrf_field() ?>

        <fieldset class="profile-resolution__choices">
            <legend class="profile-resolution__legend">Que souhaitez-vous faire ?</legend>

            <label class="profile-resolution__choice">
                <input type="radio" name="choice" value="keep" required>
                Garder mon profil actuel
            </label>

            <label class="profile-resolution__choice">
                <input type="radio" name="choice" value="submitted">
                Utiliser les informations soumises
            </label>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Confirmer mon choix</button>
        </div>
    </form>

<?php endif; ?>

</section>

<?= $this->endSection() ?>
