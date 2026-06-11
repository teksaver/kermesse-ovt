<?= $this->extend('layouts/public') ?>
<?= $this->section('content') ?>
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



        <?php if (isset($errors['_service'])): ?>
        <div class="form-service-error" role="alert">
            <?= esc($errors['_service']) ?>
        </div>
        <?php endif; ?>

        <form method="post"
            action="<?= esc(site_url("k/{$summary['publicSlug']}/slots/{$summary['slotId']}/signup"), 'attr') ?>"
            novalidate>
            <?= csrf_field() ?>

            <?php $hasFirstNameError = isset($errors['first_name']); ?>
            <div class="form-group<?= $hasFirstNameError ? ' form-group--error' : '' ?>">
                <label for="first_name">Prénom <span aria-hidden="true">*</span></label>
                <input id="first_name" type="text" name="first_name"
                    value="<?= esc($fields['first_name'] ?? '') ?>"
                    placeholder="Ex : Marie"
                    autocomplete="given-name"
                    <?= $hasFirstNameError ? 'aria-invalid="true" aria-describedby="first_name-error"' : '' ?>
                    required>
                <?php if ($hasFirstNameError): ?>
                <p id="first_name-error" class="field-error" role="alert"><?= esc($errors['first_name']) ?></p>
                <?php endif; ?>
            </div>

            <?php $hasLastNameError = isset($errors['last_name']); ?>
            <div class="form-group<?= $hasLastNameError ? ' form-group--error' : '' ?>">
                <label for="last_name">Nom <span aria-hidden="true">*</span></label>
                <input id="last_name" type="text" name="last_name"
                    value="<?= esc($fields['last_name'] ?? '') ?>"
                    placeholder="Ex : Dupont"
                    autocomplete="family-name"
                    <?= $hasLastNameError ? 'aria-invalid="true" aria-describedby="last_name-error"' : '' ?>
                    required>
                <?php if ($hasLastNameError): ?>
                <p id="last_name-error" class="field-error" role="alert"><?= esc($errors['last_name']) ?></p>
                <?php endif; ?>
            </div>

            <?php $hasEmailError = isset($errors['email']); ?>
            <div class="form-group<?= $hasEmailError ? ' form-group--error' : '' ?>">
                <label for="email">Email <span aria-hidden="true">*</span></label>
                <input id="email" type="email" name="email"
                    value="<?= esc($fields['email'] ?? '') ?>"
                    placeholder="Ex : marie@exemple.fr"
                    autocomplete="email"
                    <?= $hasEmailError ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                    required>
                <?php if ($hasEmailError): ?>
                <p id="email-error" class="field-error" role="alert"><?= esc($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <?php $hasPhoneError = isset($errors['phone']); ?>
            <div class="form-group<?= $hasPhoneError ? ' form-group--error' : '' ?>">
                <label for="phone">Téléphone <span class="signup-optional">(facultatif)</span></label>
                <input id="phone" type="tel" name="phone"
                    value="<?= esc($fields['phone'] ?? '') ?>"
                    placeholder="Ex : 06 12 34 56 78"
                    autocomplete="tel"
                    <?= $hasPhoneError ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>>
                <?php if ($hasPhoneError): ?>
                <p id="phone-error" class="field-error" role="alert"><?= esc($errors['phone']) ?></p>
                <?php endif; ?>
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

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">S'inscrire</button>
            </div>
        </form>

        <div class="signup-back">
            <a href="<?= site_url('k/' . esc($summary['publicSlug'], 'attr')) ?>" class="back-link">← Retour aux créneaux</a>
        </div>

    </section>
</main>
<?= $this->endSection() ?>
