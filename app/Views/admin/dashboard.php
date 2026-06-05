<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= esc($kermesse['name']) ?> – Espace admin – Kermesse</title>
    <meta name="description" content="Espace d'administration de votre kermesse.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="<?= base_url('favicon.ico') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <script src="<?= base_url('assets/js/app.js') ?>" defer></script>
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
            <button type="button" class="btn btn-secondary disabled-action" disabled title="<?= esc($disabledReason) ?>">
                Ouvrir les inscriptions
            </button>
            <?php endif; ?>
        </div>

        <?php if (! empty($flashSuccess)): ?>
        <div class="info-box info-box--success info-box--spaced" role="status">
            <p><?= esc($flashSuccess) ?></p>
        </div>
        <?php endif; ?>

        <?php if (! $hasStands && ! $isOpen): ?>
        <div class="info-box info-box--spaced" role="note">
            <p><?= esc($disabledReason) ?></p>
        </div>
        <?php endif; ?>

        <!-- Stand list -->
        <?php if ($hasStands): ?>
        <div class="stand-list" aria-label="Stands de la kermesse">
            <?php foreach ($stands as $stand): ?>
            <div class="stand-group" id="stand-<?= esc($stand['id']) ?>">
                <div class="stand-group__header">
                    <span class="stand-group__name"><?= esc($stand['name']) ?></span>
                </div>

                <!-- Edit form -->
                <?php
                    $isEditing  = isset($standEditId) && (int) $standEditId === (int) $stand['id'];
                    $editErrors = $isEditing ? ($standErrors ?? []) : [];
                    $editValue  = $isEditing ? esc($standInputName ?? $stand['name']) : esc($stand['name']);
                ?>
                <form method="post"
                      action="<?= site_url("admin/kermesses/{$kermesse['id']}/stands/{$stand['id']}") ?>"
                      class="stand-form stand-form--edit"
                      aria-label="Modifier <?= esc($stand['name']) ?>">
                    <?= csrf_field() ?>
                    <div class="form-group <?= isset($editErrors['name']) ? 'form-group--error' : '' ?>">
                        <label for="stand-edit-name-<?= esc($stand['id']) ?>">Nom du stand</label>
                        <input
                            type="text"
                            id="stand-edit-name-<?= esc($stand['id']) ?>"
                            name="name"
                            value="<?= $editValue ?>"
                            maxlength="255"
                            required
                            aria-describedby="<?= isset($editErrors['name']) ? "stand-edit-error-{$stand['id']}" : '' ?>"
                        >
                        <?php if (isset($editErrors['name'])): ?>
                        <p id="stand-edit-error-<?= esc($stand['id']) ?>" class="field-error" role="alert">
                            <?= esc($editErrors['name']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>

                <!-- Empty slot state -->
                <div class="slot-empty-state" aria-label="Créneaux de <?= esc($stand['name']) ?>">
                    <p class="slot-empty-state__text">Aucun créneau pour le moment</p>
                    <button type="button" class="btn btn-secondary disabled-action" disabled>
                        Ajouter un créneau
                    </button>
                </div>

                <!-- Delete confirmation zone -->
                <?php
                    $isDeleteError   = isset($deleteStandId) && (int) $deleteStandId === (int) $stand['id'];
                    $hasSignups      = (int) ($stand['activeSignupCount'] ?? 0) > 0;
                    $deleteFormClass = $hasSignups ? 'stand-delete-form stand-delete-form--strong' : 'stand-delete-form';
                ?>
                <div class="stand-delete-zone">
                    <?php if ($isDeleteError && ! empty($deleteError)): ?>
                    <div class="stand-delete-zone__error" role="alert">
                        <?= esc($deleteError) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasSignups): ?>
                    <form method="post"
                          action="<?= site_url("admin/kermesses/{$kermesse['id']}/stands/{$stand['id']}/delete") ?>"
                          class="<?= $deleteFormClass ?>"
                          aria-label="Supprimer <?= esc($stand['name']) ?>">
                        <?= csrf_field() ?>
                        <p class="stand-delete-zone__warning">
                            Ce stand contient des inscriptions. Tapez <strong>SUPPRIMER</strong> pour confirmer.
                        </p>
                        <div class="form-group <?= $isDeleteError ? 'form-group--error' : '' ?>">
                            <label for="stand-delete-confirm-<?= esc($stand['id']) ?>">
                                Tapez SUPPRIMER pour confirmer
                            </label>
                            <input
                                type="text"
                                id="stand-delete-confirm-<?= esc($stand['id']) ?>"
                                name="confirm_word"
                                value=""
                                autocomplete="off"
                                data-confirm-word="SUPPRIMER"
                                data-target-btn="stand-delete-btn-<?= esc($stand['id']) ?>"
                                aria-describedby="<?= $isDeleteError ? "stand-delete-error-{$stand['id']}" : '' ?>"
                            >
                            <?php if ($isDeleteError): ?>
                            <p id="stand-delete-error-<?= esc($stand['id']) ?>" class="field-error" role="alert">
                                <?= esc($deleteError) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <button
                            type="submit"
                            id="stand-delete-btn-<?= esc($stand['id']) ?>"
                            class="btn btn-danger"
                            disabled
                        >Supprimer le stand</button>
                    </form>
                    <?php else: ?>
                    <form method="post"
                          action="<?= site_url("admin/kermesses/{$kermesse['id']}/stands/{$stand['id']}/delete") ?>"
                          class="<?= $deleteFormClass ?>"
                          aria-label="Supprimer <?= esc($stand['name']) ?>">
                        <?= csrf_field() ?>
                        <p class="stand-delete-zone__info">
                            Ce stand disparaîtra de votre configuration active.
                        </p>
                        <div class="form-group <?= $isDeleteError ? 'form-group--error' : '' ?>">
                            <label class="stand-delete-zone__checkbox-label">
                                <input
                                    type="checkbox"
                                    name="confirm_delete"
                                    value="1"
                                    <?= $isDeleteError ? '' : '' ?>
                                >
                                Je confirme la suppression de ce stand.
                            </label>
                            <?php if ($isDeleteError): ?>
                            <p class="field-error" role="alert"><?= esc($deleteError) ?></p>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-danger">Supprimer le stand</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Add stand form -->
        <?php
            $addErrors = (! isset($standEditId) || $standEditId === null) ? ($standErrors ?? []) : [];
            $addValue  = (! isset($standEditId) || $standEditId === null) ? esc($standInputName ?? '') : '';
        ?>
        <div class="stand-add-section">
            <?php if (! $hasStands): ?>
            <div class="empty-state" role="region" aria-label="Aucun stand">
                <p class="empty-state__title">Aucun stand pour le moment</p>
                <p class="empty-state__text">Ajoutez un premier stand pour commencer à construire votre planning.</p>
            </div>
            <?php endif; ?>

            <form method="post"
                  action="<?= site_url("admin/kermesses/{$kermesse['id']}/stands") ?>"
                  class="stand-form stand-form--add"
                  aria-label="Ajouter un stand">
                <?= csrf_field() ?>
                <div class="form-group <?= isset($addErrors['name']) ? 'form-group--error' : '' ?>">
                    <label for="stand-add-name">Nom du stand</label>
                    <p class="form-group__hint">Exemple : Pêche à la ligne, Buvette, Maquillage.</p>
                    <input
                        type="text"
                        id="stand-add-name"
                        name="name"
                        value="<?= $addValue ?>"
                        maxlength="255"
                        aria-describedby="<?= isset($addErrors['name']) ? 'stand-add-error' : '' ?>"
                    >
                    <?php if (isset($addErrors['name'])): ?>
                    <p id="stand-add-error" class="field-error" role="alert">
                        <?= esc($addErrors['name']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary">Ajouter le stand</button>
            </form>
        </div>

    </section>
</main>
</body>
</html>
