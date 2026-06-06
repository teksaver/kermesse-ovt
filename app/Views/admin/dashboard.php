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
            <a href="<?= esc($previewUrl) ?>" class="btn btn-secondary">
                Prévisualiser
            </a>
            <?php if ($isOpen): ?>
            <form method="post" action="<?= esc($closeActionUrl) ?>" class="admin-action-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secondary">Fermer les inscriptions</button>
            </form>
            <?php elseif ($canOpenSignups): ?>
            <form method="post" action="<?= esc($openActionUrl) ?>" class="admin-action-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Ouvrir les inscriptions</button>
            </form>
            <?php else: ?>
            <button type="button" class="btn btn-secondary disabled-action" disabled title="<?= esc($disabledReason) ?>">
                Ouvrir les inscriptions
            </button>
            <?php endif; ?>
        </div>

        <!-- Public volunteer link: visible for manual copy without JS; the copy
             button is a progressive enhancement revealed by app.js. -->
        <div class="admin-share" aria-label="Lien public bénévole">
            <label for="public-volunteer-link">Lien public bénévole</label>
            <div class="admin-share__row">
                <input type="text" id="public-volunteer-link" class="admin-share__input"
                       value="<?= esc($publicVolunteerUrl) ?>" readonly
                       data-copy-source onclick="this.select()">
                <button type="button" class="btn btn-secondary admin-share__copy"
                        data-copy-button data-copy-target="public-volunteer-link"
                        data-copy-success="Lien copié." hidden>
                    Copier le lien
                </button>
            </div>
            <p class="admin-share__feedback" role="status" aria-live="polite" data-copy-feedback hidden></p>
        </div>

        <?php if (! empty($flashSuccess)): ?>
        <div class="info-box info-box--success info-box--spaced" role="status">
            <p><?= esc($flashSuccess) ?></p>
        </div>
        <?php endif; ?>

        <?php if (! empty($lifecycleError)): ?>
        <div class="info-box info-box--error info-box--spaced" role="alert">
            <p><?= esc($lifecycleError) ?></p>
        </div>
        <?php endif; ?>

        <?php if (! $canOpenSignups && ! $isOpen && empty($lifecycleError)): ?>
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

                <!-- Slot list -->
                <?php if (! empty($stand['slots'])): ?>
                <div class="slot-list" aria-label="Créneaux de <?= esc($stand['name']) ?>">
                    <?php foreach ($stand['slots'] as $slot): ?>
                    <?php
                        $isSlotEditError  = isset($slotEditSlotId) && (int) $slotEditSlotId === (int) $slot['id'];
                        $slotEditErrors   = $isSlotEditError ? ($slotErrors ?? []) : [];
                        $slotEditInputs   = $isSlotEditError ? ($slotInputs ?? []) : [];
                        $editStartValue   = $isSlotEditError && ! empty($slotEditInputs['start_time'])
                            ? esc($slotEditInputs['start_time'])
                            : esc(substr($slot['starts_at'], 11, 5));
                        $editEndValue     = $isSlotEditError && ! empty($slotEditInputs['end_time'])
                            ? esc($slotEditInputs['end_time'])
                            : esc(substr($slot['ends_at'], 11, 5));
                        $editCapValue     = $isSlotEditError && isset($slotEditInputs['capacity'])
                            ? esc($slotEditInputs['capacity'])
                            : esc($slot['capacity']);
                    ?>
                    <div class="slot-row" id="slot-<?= esc($slot['id']) ?>">
                        <div class="slot-row__info">
                            <span class="slot-row__time"><?= esc($slot['displayTime']) ?></span>
                            <span class="slot-row__capacity">
                                <?= esc($slot['capacity']) ?> places au total,
                                <?= esc($slot['remainingSpots']) ?> places restantes
                            </span>
                        </div>
                        <form method="post"
                              action="<?= site_url("admin/kermesses/{$kermesse['id']}/stands/{$stand['id']}/slots/{$slot['id']}") ?>"
                              class="slot-form slot-form--edit"
                              aria-label="Modifier le créneau <?= esc($slot['displayTime']) ?>">
                            <?= csrf_field() ?>
                            <?php if (isset($slotEditErrors['general'])): ?>
                            <p class="field-error" role="alert"><?= esc($slotEditErrors['general']) ?></p>
                            <?php endif; ?>
                            <div class="slot-form__fields">
                                <div class="form-group <?= isset($slotEditErrors['end_time']) ? 'form-group--error' : '' ?>">
                                    <label for="slot-edit-start-<?= esc($slot['id']) ?>">Heure de début</label>
                                    <input type="time" id="slot-edit-start-<?= esc($slot['id']) ?>"
                                           name="start_time" value="<?= $editStartValue ?>">
                                </div>
                                <div class="form-group <?= isset($slotEditErrors['end_time']) ? 'form-group--error' : '' ?>">
                                    <label for="slot-edit-end-<?= esc($slot['id']) ?>">Heure de fin</label>
                                    <input type="time" id="slot-edit-end-<?= esc($slot['id']) ?>"
                                           name="end_time" value="<?= $editEndValue ?>"
                                           aria-describedby="<?= isset($slotEditErrors['end_time']) ? "slot-edit-time-error-{$slot['id']}" : '' ?>">
                                    <?php if (isset($slotEditErrors['end_time'])): ?>
                                    <p id="slot-edit-time-error-<?= esc($slot['id']) ?>" class="field-error" role="alert">
                                        <?= esc($slotEditErrors['end_time']) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group <?= isset($slotEditErrors['capacity']) ? 'form-group--error' : '' ?>">
                                    <label for="slot-edit-cap-<?= esc($slot['id']) ?>">Capacité</label>
                                    <input type="number" id="slot-edit-cap-<?= esc($slot['id']) ?>"
                                           name="capacity" value="<?= $editCapValue ?>" min="1"
                                           aria-describedby="<?= isset($slotEditErrors['capacity']) ? "slot-edit-cap-error-{$slot['id']}" : '' ?>">
                                    <?php if (isset($slotEditErrors['capacity'])): ?>
                                    <p id="slot-edit-cap-error-<?= esc($slot['id']) ?>" class="field-error" role="alert">
                                        <?= esc($slotEditErrors['capacity']) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn--slot">Enregistrer le créneau</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <!-- Empty slot state -->
                <div class="slot-empty-state" aria-label="Créneaux de <?= esc($stand['name']) ?>">
                    <p class="slot-empty-state__text">Aucun créneau pour le moment</p>
                </div>
                <?php endif; ?>

                <!-- Add slot form -->
                <?php
                    $isSlotAddError   = isset($slotAddStandId) && (int) $slotAddStandId === (int) $stand['id'];
                    $slotAddErrors    = $isSlotAddError ? ($slotErrors ?? []) : [];
                    $slotAddInputs    = $isSlotAddError ? ($slotInputs ?? []) : [];
                    $addStartValue    = esc($slotAddInputs['start_time'] ?? '');
                    $addEndValue      = esc($slotAddInputs['end_time'] ?? '');
                    $addCapValue      = esc($slotAddInputs['capacity'] ?? '');
                ?>
                <form method="post"
                      action="<?= site_url("admin/kermesses/{$kermesse['id']}/stands/{$stand['id']}/slots") ?>"
                      class="slot-form slot-form--add"
                      aria-label="Ajouter un créneau à <?= esc($stand['name']) ?>">
                    <?= csrf_field() ?>
                    <?php if (isset($slotAddErrors['general'])): ?>
                    <p class="field-error" role="alert"><?= esc($slotAddErrors['general']) ?></p>
                    <?php endif; ?>
                    <div class="slot-form__fields">
                        <div class="form-group <?= isset($slotAddErrors['end_time']) ? 'form-group--error' : '' ?>">
                            <label for="slot-add-start-<?= esc($stand['id']) ?>">Heure de début</label>
                            <input type="time" id="slot-add-start-<?= esc($stand['id']) ?>"
                                   name="start_time" value="<?= $addStartValue ?>">
                        </div>
                        <div class="form-group <?= isset($slotAddErrors['end_time']) ? 'form-group--error' : '' ?>">
                            <label for="slot-add-end-<?= esc($stand['id']) ?>">Heure de fin</label>
                            <input type="time" id="slot-add-end-<?= esc($stand['id']) ?>"
                                   name="end_time" value="<?= $addEndValue ?>"
                                   aria-describedby="<?= isset($slotAddErrors['end_time']) ? "slot-add-time-error-{$stand['id']}" : '' ?>">
                            <?php if (isset($slotAddErrors['end_time'])): ?>
                            <p id="slot-add-time-error-<?= esc($stand['id']) ?>" class="field-error" role="alert">
                                <?= esc($slotAddErrors['end_time']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group <?= isset($slotAddErrors['capacity']) ? 'form-group--error' : '' ?>">
                            <label for="slot-add-cap-<?= esc($stand['id']) ?>">Capacité</label>
                            <input type="number" id="slot-add-cap-<?= esc($stand['id']) ?>"
                                   name="capacity" value="<?= $addCapValue ?>" min="1"
                                   aria-describedby="<?= isset($slotAddErrors['capacity']) ? "slot-add-cap-error-{$stand['id']}" : '' ?>">
                            <?php if (isset($slotAddErrors['capacity'])): ?>
                            <p id="slot-add-cap-error-<?= esc($stand['id']) ?>" class="field-error" role="alert">
                                <?= esc($slotAddErrors['capacity']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn--slot">Ajouter le créneau</button>
                </form>

                <!-- Delete confirmation zone -->
                <?php
                    $isDeleteError         = isset($deleteStandId) && (int) $deleteStandId === (int) $stand['id'];
                    $deleteConfirmationMode = (string) ($stand['deleteConfirmationMode'] ?? '');
                    $hasSignups            = $deleteConfirmationMode === 'strong';
                    $deleteFormClass       = $hasSignups ? 'stand-delete-form stand-delete-form--strong' : 'stand-delete-form';
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
