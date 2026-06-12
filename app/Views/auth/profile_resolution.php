<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<section class="form-panel profile-resolution" aria-labelledby="resolution-title">

    <h1 id="resolution-title" class="page-title">Vérification de votre profil</h1>

    <p class="profile-resolution__intro">
        Lors d'une inscription récente en tant que bénévole, vous avez renseigné des coordonnées
        différentes de celles de votre profil. Souhaitez-vous mettre à jour votre profil ?
    </p>

    <?php if (session()->getFlashdata('error')): ?>
        <div class="form-service-error" role="alert">
            <?= esc(session()->getFlashdata('error')) ?>
        </div>
    <?php endif; ?>

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

</section>

<?= $this->endSection() ?>
