<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="kermesse-dashboard">
    <h1 class="page-title" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:8px;">
        <?= esc($kermesse['name']) ?>
        <span style="font-size:0.5em; font-weight:normal; margin-top:6px;"><?= view('partials/status_badge', ['status' => $kermesse['status']]) ?></span>
    </h1>

    <!-- En-tête kermesse : infos, lien public — toujours visible (tous rôles). -->
    <div class="kermesse-jumbotron">
        <div style="display:flex; flex-direction:column; gap:12px;">
            <div style="display:flex; flex-wrap:wrap; gap:16px;">
                <?php if (! empty($kermesse['event_date'])): ?>
                <p class="kermesse-dashboard__date" style="margin:0;">📅 <strong>Date :</strong> <?= esc($kermesse['event_date']) ?></p>
                <?php endif; ?>

                <?php if (! empty($kermesse['location'])): ?>
                <p class="kermesse-dashboard__location" style="margin:0;">📍 <strong>Lieu :</strong> <?= esc($kermesse['location']) ?></p>
                <?php endif; ?>
            </div>

            <?php if (! empty($kermesse['short_description'])): ?>
            <p class="kermesse-dashboard__description" style="margin:0;">📝 <strong>Description :</strong> <?= esc($kermesse['short_description']) ?></p>
            <?php endif; ?>

            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:16px; margin-top:8px;">
                <?php if (empty($isBenevole)): ?>
                <p class="kermesse-dashboard__public-link" style="margin:0; display:flex; align-items:center; flex-wrap:wrap; gap:8px;">🔗 <strong>Lien public :</strong>
                    <span style="display:inline-flex; align-items:center; gap:4px; min-width:0;">
                        <a href="<?= esc(site_url("k/{$kermesse['public_slug']}")) ?>" target="_blank" rel="noopener noreferrer" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:min(280px,60vw);"><?= esc(site_url("k/{$kermesse['public_slug']}")) ?></a>
                        <button type="button" class="btn btn--icon" title="Copier le lien" data-copy-url="<?= esc(site_url("k/{$kermesse['public_slug']}")) ?>" id="copy-link-btn" style="background:transparent; border:none; cursor:pointer; font-size:1.2em; padding:0 4px; flex-shrink:0; display:inline-flex; align-items:center;">📋</button>
                        <span id="copy-link-feedback" class="copy-feedback" aria-live="polite" style="color: #155724; background: #d4edda; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; font-weight: bold; display: none;">Copié !</span>
                    </span>
                </p>
                <?php endif; ?>

                <a href="<?= site_url("k/{$kermesse['public_slug']}") ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="btn btn--secondary btn--sm"><?= empty($isBenevole) ? 'Accéder à la page' : 'Nouvelle inscription' ?></a>
            </div>

            <!-- Actions admin (Owner/Admin) : ⚙️ Paramètres + cycle de vie — dans le jumbotron. -->
            <?php if (! empty($canModify)): ?>
            <div class="kermesse-header-actions">
                <button type="button" class="btn btn--secondary btn--sm" title="Paramètres généraux" onclick="document.getElementById('modal-kermesse-edit').showModal()">⚙️ Paramètres</button>

                <?php if ($kermesse['status'] === 'open'): ?>
                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/close") ?>" onsubmit="this.querySelector('button').disabled = true;" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--warning btn--sm">Fermer les inscriptions</button>
                </form>
                <?php elseif (in_array($kermesse['status'], ['preparation', 'closed'], true)): ?>
                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/open") ?>" onsubmit="this.querySelector('button').disabled = true;" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--primary btn--sm"><?= $kermesse['status'] === 'closed' ? 'Rouvrir les inscriptions' : 'Ouvrir les inscriptions' ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php $lifecycleErrorHeader = session()->getFlashdata('lifecycle_error'); ?>
            <?php if ($lifecycleErrorHeader !== null): ?>
            <p class="form-error" role="alert" style="margin-top:8px;"><?= esc($lifecycleErrorHeader) ?></p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modales kermesse — rendues ici pour accessibilité DOM (hors flux onglets). -->
    <?php if (! empty($canModify)): ?>
    <?php
        $kermesseForm  = session()->getFlashdata('kermesse_form');
        $kermesseError = session()->getFlashdata('kermesse_edit_error');
    ?>
    <dialog id="modal-kermesse-edit" class="k-modal" <?= ($kermesseForm === 'edit') ? 'data-auto-open' : '' ?>>
        <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/edit") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
            <h3 class="k-modal__title">Modifier la kermesse</h3>
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Nom</label>
                <input type="text" name="name" class="form-control" value="<?= esc($kermesseForm === 'edit' ? old('name', $kermesse['name']) : $kermesse['name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                <input type="text" name="event_date" class="form-control" value="<?= esc($kermesseForm === 'edit' ? old('event_date', $kermesse['event_date'] ?? '') : ($kermesse['event_date'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Lieu</label>
                <input type="text" name="location" class="form-control" value="<?= esc($kermesseForm === 'edit' ? old('location', $kermesse['location'] ?? '') : ($kermesse['location'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="short_description" class="form-control" rows="3"><?= esc($kermesseForm === 'edit' ? old('short_description', $kermesse['short_description'] ?? '') : ($kermesse['short_description'] ?? '')) ?></textarea>
            </div>
            <?php if ($kermesseError): ?>
            <p class="form-error"><?= esc($kermesseError) ?></p>
            <?php endif; ?>
            <div class="k-modal__actions">
                <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                <button type="submit" class="btn btn--primary">Enregistrer</button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Navigation par onglets (Story 5.2, AC1 — UX-DR16 / NFR4)          -->
    <!-- Seuls les onglets autorisés pour le rôle courant sont rendus.       -->
    <!-- Le premier onglet est actif par défaut (côté serveur + JS).         -->
    <!-- ------------------------------------------------------------------ -->

    <?php $hasSidebar = count($tabs) > 1; ?>
    <div class="<?= $hasSidebar ? 'dashboard-layout' : 'dashboard-single' ?>">
        <?php if ($hasSidebar): ?>
        <nav class="sidebar-nav" aria-label="Sections du tableau de bord">
            <?php foreach ($tabs as $i => $tab): ?>
            <button
                type="button"
                class="sidebar-nav__btn<?= $tab['id'] === $defaultTab ? ' is-active' : '' ?>"
                data-tab="<?= esc($tab['id']) ?>"
                aria-expanded="<?= $tab['id'] === $defaultTab ? 'true' : 'false' ?>"
                aria-controls="tab-panel-<?= esc($tab['id']) ?>"
            ><?= esc($tab['label']) ?></button>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <div class="dashboard-content">

    <!-- ================================================================== -->
    <!-- Onglet : Gestion des stands (Owner/Admin uniquement — Story 4.1 / 5.2). -->
    <!-- ================================================================== -->
    <?php if (! empty($canModify)): ?>
    <section
        id="tab-panel-modification"
        class="kermesse-dashboard__section tab-panel"
        data-tab-content="modification"
    >
        <?php if ($hasSidebar): ?>
        <button type="button" class="accordion-header" data-tab="modification" aria-expanded="<?= $defaultTab === 'modification' ? 'true' : 'false' ?>" aria-controls="tab-panel-modification">
            <span class="accordion-icon"><?= $defaultTab === 'modification' ? '▼' : '▶' ?></span> Stands et créneaux
        </button>
        <?php endif; ?>
        <?php if (! empty($success = session()->getFlashdata('success'))): ?>
        <p class="form-success"><?= esc($success) ?></p>
        <?php endif; ?>

        <h2 class="section-title">Stands et créneaux</h2>
        <h3 class="subsection-title">Stands</h3>

        <?php
            $standError      = session()->getFlashdata('stand_error')    ?? ($stand_error ?? null);
            $standForm       = session()->getFlashdata('stand_form')     ?? ($stand_form ?? null);
            $standName       = session()->getFlashdata('stand_name')     ?? ($stand_name ?? '');
            $editingStandIdRaw = session()->getFlashdata('editing_stand_id') ?? ($editing_stand_id ?? null);
            $editingStandId  = $editingStandIdRaw !== null ? (int) $editingStandIdRaw : null;
            $stands          = $stands ?? [];
            $slotErrors      = session()->getFlashdata('slot_errors')        ?? [];
            $slotForm        = session()->getFlashdata('slot_form')          ?? null;
            $slotFormStandId = (int) (session()->getFlashdata('slot_form_stand_id') ?? 0);
            $slotFormSlotId  = (int) (session()->getFlashdata('slot_form_slot_id')  ?? 0);
        ?>

        <!-- Liste des stands actifs -->
        <?php if (! empty($stands)): ?>
        <ul class="stands-list desktop-grid">
            <?php foreach ($stands as $stand): ?>
            <?php $sid = (int) $stand['id']; ?>
            <li class="stands-list__item" id="slots-stand-<?= $sid ?>">
                <div class="stands-list__header" style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="stands-list__name"><strong><?= esc($stand['name']) ?></strong></span>

                    <!-- Menu contextuel Stand -->
                    <details class="dropdown" style="position:relative;">
                        <summary class="btn btn--sm btn--secondary">Options</summary>
                        <div class="dropdown__menu" style="position:absolute; right:0; display:flex; flex-direction:column; gap:8px; margin-top:8px; border:1px solid var(--border-color, #ddd); padding:12px; border-radius:8px; background:#fff; z-index:10; min-width:200px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                            <!-- Renommer -->
                            <button type="button" class="btn btn--sm" style="width:100%;" onclick="document.getElementById('modal-stand-rename-<?= $sid ?>').showModal()">Renommer</button>

                            <!-- Dupliquer -->
                            <button type="button" class="btn btn--sm" style="width:100%;" onclick="document.getElementById('modal-stand-duplicate-<?= $sid ?>').showModal()">Dupliquer</button>

                            <!-- Supprimer -->
                            <button type="button" class="btn btn--danger btn--sm" style="width:100%;" onclick="document.getElementById('modal-stand-delete-<?= $sid ?>').showModal()">Supprimer</button>
                        </div>
                    </details>
                </div>

                <!-- Modale Renommer Stand -->
                <dialog id="modal-stand-rename-<?= $sid ?>" class="k-modal" <?= ($standForm === 'edit' && $editingStandId === $sid) ? 'data-auto-open' : '' ?>>
                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                        <h3 class="k-modal__title">Renommer le stand</h3>
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label">Nouveau nom</label>
                            <input type="text" name="name" class="form-control<?= ($standForm === 'edit' && $editingStandId === $sid && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc(($standForm === 'edit' && $editingStandId === $sid) ? $standName : $stand['name']) ?>" maxlength="255" required>
                            <?php if ($standForm === 'edit' && $editingStandId === $sid && $standError !== null): ?>
                            <span class="form-error"><?= esc($standError) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="k-modal__actions">
                            <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                            <button type="submit" class="btn btn--primary">Enregistrer</button>
                        </div>
                    </form>
                </dialog>

                <!-- Modale Dupliquer Stand (Story 5.6) -->
                <?php $isDuplicatingThis = ($standForm === 'duplicate' && $editingStandId === $sid); ?>
                <dialog id="modal-stand-duplicate-<?= $sid ?>" class="k-modal" <?= $isDuplicatingThis ? 'data-auto-open' : '' ?>>
                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/duplicate") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                        <h3 class="k-modal__title">Dupliquer le stand</h3>
                        <?= csrf_field() ?>
                        <p class="k-modal__text">Les créneaux de « <?= esc($stand['name']) ?> » (horaires et capacités) seront recopiés. Aucun inscrit n'est repris : le nouveau stand part avec zéro inscrit.</p>
                        <div class="form-group">
                            <label for="duplicate-name-<?= $sid ?>" class="form-label">Nom du nouveau stand</label>
                            <input type="text" id="duplicate-name-<?= $sid ?>" name="name" class="form-control<?= ($isDuplicatingThis && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc($isDuplicatingThis ? $standName : '') ?>" placeholder="Ex. : <?= esc($stand['name']) ?> (copie)" maxlength="255" autocomplete="off" required data-require-nonempty="duplicate-btn-<?= $sid ?>">
                            <?php if ($isDuplicatingThis && $standError !== null): ?>
                            <span class="form-error"><?= esc($standError) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="k-modal__actions">
                            <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                            <button type="submit" id="duplicate-btn-<?= $sid ?>" class="btn btn--primary" <?= ($isDuplicatingThis && $standName !== '') ? '' : 'disabled' ?>>Dupliquer</button>
                        </div>
                    </form>
                </dialog>

                <!-- Modale Supprimer Stand -->
                <?php
                    $requiresStrong = ! empty($stand['requires_strong_confirm']);
                    $deleteError    = session()->getFlashdata('delete_error_' . $sid);
                    $hasDeleteError = $deleteError !== null;
                ?>
                <dialog id="modal-stand-delete-<?= $sid ?>" class="k-modal" <?= $hasDeleteError ? 'data-auto-open' : '' ?>>
                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/delete") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                        <h3 class="k-modal__title">Supprimer le stand</h3>
                        <?= csrf_field() ?>
                        <?php if ($requiresStrong): ?>
                        <p class="k-modal__text">Ce stand a des bénévoles inscrits. Saisissez <strong>SUPPRIMER</strong> pour confirmer la suppression :</p>
                        <div class="form-group">
                            <input type="text" id="confirm-delete-<?= $sid ?>" name="confirm" class="form-control" placeholder="SUPPRIMER" autocomplete="off" data-delete-confirm="delete-btn-<?= $sid ?>">
                        </div>
                        <?php else: ?>
                        <p class="k-modal__text">Êtes-vous sûr de vouloir supprimer le stand « <?= esc($stand['name']) ?> » ?</p>
                        <?php endif; ?>

                        <?php if ($hasDeleteError): ?>
                        <p class="form-error" role="alert"><?= esc($deleteError) ?></p>
                        <?php endif; ?>

                        <div class="k-modal__actions">
                            <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                            <?php if ($requiresStrong): ?>
                            <button type="submit" id="delete-btn-<?= $sid ?>" class="btn btn--danger" disabled>Supprimer</button>
                            <?php else: ?>
                            <button type="submit" class="btn btn--danger">Supprimer</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </dialog>

                <!-- Liste des créneaux du stand -->
                <?php if (! empty($stand['slots'])): ?>
                <ul class="slots-list" style="margin-top:16px;">
                    <?php foreach ($stand['slots'] as $slot): ?>
                    <?php $slotId = (int) $slot['id']; ?>
                    <li class="slots-list__item" style="position:relative; display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; border-bottom:1px solid #eee; padding-bottom:8px;">
                        <div class="slots-list__info">
                            <span class="slots-list__time">
                                <?= esc(date('H:i', strtotime($slot['starts_at']))) ?> –
                                <?= esc(date('H:i', strtotime($slot['ends_at']))) ?>
                            </span>
                            <span class="slots-list__capacity" style="margin-left:8px; color:#666; font-size:14px;"><?= (int) $slot['capacity'] ?> place<?= (int) $slot['capacity'] > 1 ? 's' : '' ?></span>
                        </div>

                        <div class="slots-list__actions" style="display:flex; gap:8px;">
                            <button type="button" class="btn btn--sm" title="Modifier" style="padding:4px 8px;" onclick="document.getElementById('modal-slot-edit-<?= $slotId ?>').showModal()">✏️</button>
                            <button type="button" class="btn btn--danger btn--sm" title="Supprimer" style="padding:4px 8px;" onclick="document.getElementById('modal-slot-delete-<?= $slotId ?>').showModal()">🗑️</button>
                        </div>

                        <!-- Modale Modifier Créneau -->
                        <?php $isEditingThis = ($slotForm === 'edit' && $slotFormSlotId === $slotId); ?>
                        <dialog id="modal-slot-edit-<?= $slotId ?>" class="k-modal" <?= $isEditingThis ? 'data-auto-open' : '' ?>>
                            <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$slotId}") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                <h3 class="k-modal__title">Modifier le créneau</h3>
                                <?= csrf_field() ?>
                                <?php
                                    $editStartsAt  = $isEditingThis ? old('starts_at', date('H:i', strtotime($slot['starts_at']))) : date('H:i', strtotime($slot['starts_at']));
                                    $editEndsAt    = $isEditingThis ? old('ends_at', date('H:i', strtotime($slot['ends_at'])))     : date('H:i', strtotime($slot['ends_at']));
                                    $editCapacity  = $isEditingThis ? old('capacity', $slot['capacity'])   : $slot['capacity'];
                                ?>
                                <div class="form-group">
                                    <label class="form-label">Début</label>
                                    <input type="time" name="starts_at" class="form-control<?= $isEditingThis && isset($slotErrors['starts_at']) ? ' is-invalid' : '' ?>" value="<?= esc($editStartsAt) ?>" required>
                                    <?php if ($isEditingThis && isset($slotErrors['starts_at'])): ?><span class="form-error"><?= esc($slotErrors['starts_at']) ?></span><?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Fin</label>
                                    <input type="time" name="ends_at" class="form-control<?= $isEditingThis && isset($slotErrors['ends_at']) ? ' is-invalid' : '' ?>" value="<?= esc($editEndsAt) ?>" required>
                                    <?php if ($isEditingThis && isset($slotErrors['ends_at'])): ?><span class="form-error"><?= esc($slotErrors['ends_at']) ?></span><?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Capacité</label>
                                    <input type="number" name="capacity" class="form-control<?= $isEditingThis && isset($slotErrors['capacity']) ? ' is-invalid' : '' ?>" value="<?= esc($editCapacity) ?>" min="1" required>
                                    <?php if ($isEditingThis && isset($slotErrors['capacity'])): ?><span class="form-error"><?= esc($slotErrors['capacity']) ?></span><?php endif; ?>
                                </div>
                                <?php if ($isEditingThis && isset($slotErrors['general'])): ?><p class="form-error"><?= esc($slotErrors['general']) ?></p><?php endif; ?>
                                <div class="k-modal__actions">
                                    <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                                    <button type="submit" class="btn btn--primary">Enregistrer</button>
                                </div>
                            </form>
                        </dialog>

                        <!-- Modale Supprimer Créneau -->
                        <?php
                            $deleteSlotError    = session()->getFlashdata('delete_slot_error_' . $slotId);
                            $hasDeleteSlotError = $deleteSlotError !== null;
                        ?>
                        <dialog id="modal-slot-delete-<?= $slotId ?>" class="k-modal" <?= $hasDeleteSlotError ? 'data-auto-open' : '' ?>>
                            <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$slotId}/delete") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                <h3 class="k-modal__title">Supprimer le créneau</h3>
                                <?= csrf_field() ?>
                                <?php if ($hasDeleteSlotError): ?>
                                <p class="k-modal__text form-error" role="alert" id="delete-slot-error-<?= $slotId ?>"><?= esc($deleteSlotError) ?></p>
                                <div class="form-group">
                                    <input type="text" id="confirm-delete-slot-<?= $slotId ?>" name="confirm" class="form-control" placeholder="SUPPRIMER" autocomplete="off" data-delete-confirm="delete-slot-btn-<?= $slotId ?>">
                                </div>
                                <div class="k-modal__actions">
                                    <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                                    <button type="submit" id="delete-slot-btn-<?= $slotId ?>" class="btn btn--danger" disabled>Confirmer</button>
                                </div>
                                <?php else: ?>
                                <p class="k-modal__text">Êtes-vous sûr de vouloir supprimer le créneau ?</p>
                                <div class="k-modal__actions">
                                    <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                                    <button type="submit" class="btn btn--danger">Supprimer</button>
                                </div>
                                <?php endif; ?>
                            </form>
                        </dialog>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="slots-list--empty" style="margin-top:16px;">Aucun créneau pour ce stand.</p>
                <?php endif; ?>

                <!-- Bouton Ajouter un créneau -->
                <button type="button" class="btn btn--sm btn--secondary" style="margin-top:16px;" onclick="document.getElementById('modal-slot-add-<?= $sid ?>').showModal()">Ajouter un créneau</button>

                <!-- Modale Ajouter Créneau -->
                <?php
                    $isAddingHere    = ($slotForm === 'add' && $slotFormStandId === $sid);
                    $defaultStartsAt = '';
                    $defaultEndsAt   = '';
                    $defaultCapacity = '';
                    if (! empty($stand['slots'])) {
                        $lastSlot        = end($stand['slots']);
                        $defaultStartsAt = date('H:i', strtotime($lastSlot['ends_at']));
                        $dur             = strtotime($lastSlot['ends_at']) - strtotime($lastSlot['starts_at']);
                        $defaultEndsAt   = date('H:i', strtotime($defaultStartsAt) + $dur);
                        $defaultCapacity = $lastSlot['capacity'];
                    }
                ?>
                <dialog id="modal-slot-add-<?= $sid ?>" class="k-modal" <?= $isAddingHere ? 'data-auto-open' : '' ?>>
                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/slots") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                        <h3 class="k-modal__title">Ajouter un créneau</h3>
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label">Début</label>
                            <input type="time" name="starts_at" class="form-control<?= $isAddingHere && isset($slotErrors['starts_at']) ? ' is-invalid' : '' ?>" value="<?= $isAddingHere ? esc(old('starts_at', '')) : esc($defaultStartsAt) ?>" required>
                            <?php if ($isAddingHere && isset($slotErrors['starts_at'])): ?><span class="form-error"><?= esc($slotErrors['starts_at']) ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fin</label>
                            <input type="time" name="ends_at" class="form-control<?= $isAddingHere && isset($slotErrors['ends_at']) ? ' is-invalid' : '' ?>" value="<?= $isAddingHere ? esc(old('ends_at', '')) : esc($defaultEndsAt) ?>" required>
                            <?php if ($isAddingHere && isset($slotErrors['ends_at'])): ?><span class="form-error"><?= esc($slotErrors['ends_at']) ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Capacité</label>
                            <input type="number" name="capacity" class="form-control<?= $isAddingHere && isset($slotErrors['capacity']) ? ' is-invalid' : '' ?>" value="<?= $isAddingHere ? esc(old('capacity', '')) : esc($defaultCapacity) ?>" min="1" required>
                            <?php if ($isAddingHere && isset($slotErrors['capacity'])): ?><span class="form-error"><?= esc($slotErrors['capacity']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($isAddingHere && isset($slotErrors['general'])): ?><p class="form-error"><?= esc($slotErrors['general']) ?></p><?php endif; ?>
                        <div class="k-modal__actions">
                            <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                            <button type="submit" class="btn btn--primary">Ajouter</button>
                        </div>
                    </form>
                </dialog>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- Bouton Ajouter un stand (en bas de liste) -->
        <div style="margin-top:24px;">
            <button type="button" class="btn btn--primary" onclick="document.getElementById('modal-stand-add').showModal()">Ajouter un stand</button>
        </div>

        <?php else: ?>
        <div class="stands-empty-state" style="text-align:center; padding:48px 0; border:2px dashed #eee; border-radius:8px; margin-top:24px;">
            <p style="font-size:18px; color:#666; margin-bottom:24px;">Créez votre premier stand</p>
            <button type="button" class="btn btn--primary" onclick="document.getElementById('modal-stand-add').showModal()">Ajouter un stand</button>
        </div>
        <?php endif; ?>

        <!-- Modale Ajouter Stand -->
        <dialog id="modal-stand-add" class="k-modal" <?= ($standForm === 'add') ? 'data-auto-open' : '' ?>>
            <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands") ?>" class="k-modal__form" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                <h3 class="k-modal__title">Ajouter un stand</h3>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="stand-name" class="form-label">Nom du stand</label>
                    <input type="text" id="stand-name" name="name" class="form-control<?= ($standForm === 'add' && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc($standForm === 'add' ? $standName : '') ?>" placeholder="Ex. : Stand Buvette" maxlength="255" required>
                    <?php if ($standForm === 'add' && $standError !== null): ?>
                    <span id="stand-add-error" class="form-error"><?= esc($standError) ?></span>
                    <?php endif; ?>
                </div>
                <div class="k-modal__actions">
                    <button type="button" class="btn btn--secondary" onclick="this.closest('dialog').close()">Annuler</button>
                    <button type="submit" class="btn btn--primary">Créer le stand</button>
                </div>
            </form>
        </dialog>
    </section>
    <?php endif; ?>

    <?php if (! empty($canManageParticipants)): ?>
    <!-- ================================================================== -->
    <!-- Onglet : Gestion des inscrits (Owner/Admin/Gestionnaire — 4.4/5.3) -->
    <!-- ================================================================== -->
    <section
        id="tab-panel-inscrits"
        class="kermesse-dashboard__section tab-panel"
        data-tab-content="inscrits"
    >
        <?php if ($hasSidebar): ?>
        <button type="button" class="accordion-header" data-tab="inscrits" aria-expanded="<?= $defaultTab === 'inscrits' ? 'true' : 'false' ?>" aria-controls="tab-panel-inscrits">
            <span class="accordion-icon">▶</span> Gestion des inscrits
        </button>
        <?php endif; ?>
        <h2 class="section-title">Gestion des inscrits</h2>

        <?php if (! empty($pSuccess = session()->getFlashdata('participants_success'))): ?>
        <p class="form-success" role="status"><?= esc($pSuccess) ?></p>
        <?php endif; ?>
        <?php if (! empty($pError = session()->getFlashdata('participants_error'))): ?>
        <p class="form-error-banner" role="alert"><?= esc($pError) ?></p>
        <?php endif; ?>

        <?php $addFieldErrors = session()->getFlashdata('participants_add_errors') ?? []; ?>
        <?php $participantStands = $participantStands ?? []; ?>
        <?php if (empty($participantStands)): ?>
        <p class="section-placeholder">Aucun stand n'a encore été créé pour cette kermesse.</p>
        <?php else: ?>
        <?php foreach ($participantStands as $pStand): ?>
        <div class="participants-stand">
            <h3 class="subsection-title"><?= esc($pStand['name']) ?></h3>

            <?php if (empty($pStand['slots'])): ?>
            <p class="section-placeholder">Aucun créneau pour ce stand.</p>
            <?php else: ?>
            <div class="participants-stand__slots">
            <?php foreach ($pStand['slots'] as $pSlot): ?>
            <div class="participants-slot">
                <div class="participants-slot__header">
                    <span class="participants-slot__when">
                        <span aria-hidden="true">📅</span> <?= esc($pSlot['date']) ?>
                        · <span aria-hidden="true">🕐</span> <?= esc($pSlot['start_time']) ?> – <?= esc($pSlot['end_time']) ?>
                    </span>
                    <span class="participants-slot__fill">Occupé : <?= (int) $pSlot['occupied'] ?> / <?= (int) $pSlot['capacity'] ?> · Restant : <?= (int) $pSlot['remaining'] ?></span>
                </div>

                <?php if (empty($pSlot['volunteers'])): ?>
                <p class="participants-slot__empty">Aucun bénévole inscrit sur ce créneau.</p>
                <?php else: ?>
                <ul class="participants-list">
                    <?php foreach ($pSlot['volunteers'] as $vol): ?>
                    <li class="participants-list__item participants-list__item--admin">
                        <span class="participants-list__name"><strong><?= esc($vol['last_name']) ?> <?= esc($vol['first_name']) ?></strong></span>
                        <div class="participants-list__icon-bar">
                            <!-- Modifier la fiche -->
                            <details class="admin-edit-details">
                                <summary class="btn-icon" title="Modifier la fiche" aria-label="Modifier la fiche de <?= esc($vol['first_name']) ?> <?= esc($vol['last_name']) ?>">✏️</summary>
                                <div class="admin-edit-details__panel">
                                    <form method="post"
                                          action="<?= site_url("kermesse/{$kermesse['id']}/slot-signups/{$vol['signup_id']}/admin-edit") ?>"
                                          class="admin-edit-form">
                                        <?= csrf_field() ?>

                                        <?php if ($vol['locked']): ?>
                                            <div class="admin-locked-display">
                                                <p class="participants-list__locked-note">Coordonnées validées par le bénévole — modifiables uniquement par lui depuis son profil.</p>
                                                <dl class="admin-locked-display__fields">
                                                    <dt class="admin-locked-display__label">Prénom</dt><dd><?= esc($vol['first_name']) ?></dd>
                                                    <dt class="admin-locked-display__label">Nom</dt><dd><?= esc($vol['last_name']) ?></dd>
                                                    <?php if ($vol['email'] !== ''): ?>
                                                    <dt class="admin-locked-display__label">Email</dt><dd><?= esc($vol['email']) ?></dd>
                                                    <?php endif; ?>
                                                    <?php if ($vol['phone'] !== ''): ?>
                                                    <dt class="admin-locked-display__label">Tél.</dt><dd><?= esc($vol['phone']) ?></dd>
                                                    <?php endif; ?>
                                                </dl>
                                            </div>
                                            <!-- Hidden fields to satisfy controller validation for identity fields -->
                                            <input type="hidden" name="first_name" value="<?= esc($vol['first_name'], 'attr') ?>">
                                            <input type="hidden" name="last_name" value="<?= esc($vol['last_name'], 'attr') ?>">
                                            <input type="hidden" name="email" value="<?= esc($vol['email'], 'attr') ?>">
                                            <input type="hidden" name="phone" value="<?= esc($vol['phone'], 'attr') ?>">
                                        <?php else: ?>
                                            <div class="form-group form-group--sm">
                                                <label class="form-label form-label--sm">Prénom</label>
                                                <input type="text" name="first_name" value="<?= esc($vol['first_name'], 'attr') ?>"
                                                       class="form-input form-input--admin" maxlength="100" autocomplete="off" required>
                                            </div>
                                            <div class="form-group form-group--sm">
                                                <label class="form-label form-label--sm">Nom</label>
                                                <input type="text" name="last_name" value="<?= esc($vol['last_name'], 'attr') ?>"
                                                       class="form-input form-input--admin" maxlength="100" autocomplete="off" required>
                                            </div>
                                            <div class="form-group form-group--sm">
                                                <label class="form-label form-label--sm">Email</label>
                                                <input type="email" name="email" value="<?= esc($vol['email'], 'attr') ?>"
                                                       class="form-input form-input--admin" maxlength="255" autocomplete="off">
                                            </div>
                                            <div class="form-group form-group--sm">
                                                <label class="form-label form-label--sm">Téléphone</label>
                                                <input type="tel" name="phone" value="<?= esc($vol['phone'], 'attr') ?>"
                                                       class="form-input form-input--admin-phone" maxlength="30" autocomplete="off">
                                            </div>
                                        <?php endif; ?>

                                        <div class="form-group form-group--sm">
                                            <label class="form-label form-label--sm">Notes internes (visibles uniquement par l'équipe)</label>
                                            <textarea name="admin_notes" class="form-input form-input--admin" rows="2" maxlength="5000" placeholder="Ex: Remplacé par Maman de Léo..."><?= esc($vol['admin_notes']) ?></textarea>
                                        </div>

                                        <div class="admin-form__buttons">
                                            <button type="submit" class="btn btn--primary btn--sm">Enregistrer</button>
                                            <button type="button" class="btn btn--secondary btn--sm"
                                                    onclick="this.closest('details').removeAttribute('open')">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                            <span role="img"
                                  title="<?= esc($vol['status_label']) ?>"
                                  aria-label="Statut : <?= esc($vol['status_label']) ?>"
                                  class="signup-status-icon"><?= $vol['status_icon'] ?></span>
                            <?php if ($vol['modifier_first_name'] !== null && $vol['modifier_date'] !== null): ?>
                            <?php $badgeLabel = 'Modifié par ' . esc($vol['modifier_first_name']) . ' le ' . esc($vol['modifier_date']); ?>
                            <span class="badge badge--modified" role="status" aria-label="<?= $badgeLabel ?>"><?= $badgeLabel ?></span>
                            <?php endif; ?>
                        <span class="participants-list__contact">
                            <?php if ($vol['phone'] !== ''): ?>
                            <a class="participants-list__phone" href="tel:<?= esc($vol['phone'], 'attr') ?>" title="<?= esc($vol['phone']) ?>"><span aria-hidden="true">📞</span><span class="contact-text"> <?= esc($vol['phone']) ?></span></a>
                            <?php endif; ?>
                            <?php if ($vol['email'] !== ''): ?>
                            <a class="participants-list__email" href="mailto:<?= esc($vol['email'], 'attr') ?>" title="<?= esc($vol['email']) ?>"><span aria-hidden="true">✉️</span><span class="contact-text"> <?= esc($vol['email']) ?></span></a>
                            <?php endif; ?>
                        </span>

                        <!-- Actions admin : déplacer et annuler (Story 5.10/5.12) — édition déplacée dans vol-header -->
                        <div class="participants-list__actions">
                            <!-- Annuler l'inscription -->
                            <details class="admin-cancel-details">
                                <summary class="btn-icon btn-icon--danger" title="Annuler l'inscription" aria-label="Annuler l'inscription de <?= esc($vol['first_name']) ?> <?= esc($vol['last_name']) ?>">🗑️</summary>
                                <div class="admin-cancel-details__panel">
                                    <p class="admin-cancel__confirm-text">Annuler l'inscription de <strong><?= esc($vol['first_name']) ?> <?= esc($vol['last_name']) ?></strong> ?</p>
                                    <form method="post"
                                          action="<?= site_url("kermesse/{$kermesse['id']}/slot-signups/{$vol['signup_id']}/admin-cancel") ?>"
                                          class="admin-cancel-form">
                                        <?= csrf_field() ?>
                                        <label class="admin-cancel__notify-label">
                                            <input type="checkbox" name="notify" value="1" class="admin-cancel__notify-checkbox">
                                            Notifier <?= esc($vol['email']) ?>
                                        </label>
                                        <div class="admin-form__buttons">
                                            <button type="submit" class="btn btn--danger btn--sm">Confirmer</button>
                                            <button type="button" class="btn btn--secondary btn--sm"
                                                    onclick="this.closest('details').removeAttribute('open')">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </details>

                            <!-- Déplacer l'inscription (Story 5.12) -->
                            <?php $moveTargets = $vol['move_targets']; ?>
                            <?php if (! empty($moveTargets)): ?>
                            <details class="admin-move-details">
                                <summary class="btn-icon" title="Déplacer vers un autre créneau" aria-label="Déplacer <?= esc($vol['first_name']) ?> <?= esc($vol['last_name']) ?> vers un autre créneau">↗️</summary>
                                <div class="admin-move-details__panel">
                                    <form method="post"
                                          action="<?= site_url("kermesse/{$kermesse['id']}/slot-signups/{$vol['signup_id']}/admin-move-slot-signup") ?>"
                                          class="admin-move-form">
                                        <?= csrf_field() ?>
                                        <div class="form-group form-group--sm">
                                            <label class="form-label form-label--sm" for="move-target-<?= (int) $vol['signup_id'] ?>">
                                                Créneau cible <span class="form-required" aria-hidden="true">*</span>
                                            </label>
                                            <select id="move-target-<?= (int) $vol['signup_id'] ?>"
                                                    name="target_slot_id"
                                                    class="form-input form-input--admin"
                                                    required>
                                                <option value="">— Choisir un créneau —</option>
                                                <?php foreach ($moveTargets as $mt): ?>
                                                <option value="<?= (int) $mt['slot_id'] ?>">
                                                    <?= esc($mt['stand_name']) ?> · <?= esc($mt['date']) ?> <?= esc($mt['start_time']) ?>–<?= esc($mt['end_time']) ?>
                                                    (<?= (int) $mt['remaining'] ?> place<?= (int) $mt['remaining'] > 1 ? 's' : '' ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group form-group--sm">
                                            <label class="form-label form-label--sm form-label--checkbox">
                                                <input type="checkbox" name="send_notification_email" value="1">
                                                Notifier <?= esc($vol['email']) ?>
                                            </label>
                                        </div>
                                        <div class="admin-form__buttons">
                                            <button type="submit" class="btn btn--primary btn--sm">Confirmer le déplacement</button>
                                            <button type="button" class="btn btn--secondary btn--sm"
                                                    onclick="this.closest('details').removeAttribute('open')">Annuler</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div><!-- /.participants-list__actions -->
                        </div><!-- /.participants-list__icon-bar -->
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <!-- Bouton Ajouter un bénévole (Story 5.11) -->
                <?php $addModalId = 'modal-add-signup-' . (int) $pSlot['slot_id']; ?>
                <?php $addFormId  = 'form-add-signup-'  . (int) $pSlot['slot_id']; ?>
                <?php $addBtnId   = 'btn-add-signup-'   . (int) $pSlot['slot_id']; ?>
                <?php $errorSlot  = (old('_error_slot_id') === (string) $pSlot['slot_id']); ?>
                <div class="participants-slot__add-action">
                    <?php if ((int) $pSlot['remaining'] > 0): ?>
                    <button type="button" class="btn btn--sm btn--secondary"
                            onclick="document.getElementById('<?= esc($addModalId, 'attr') ?>').showModal()">
                        + Ajouter un bénévole
                    </button>
                    <?php else: ?>
                    <span class="participants-slot__full">Créneau complet</span>
                    <?php endif; ?>
                </div>

                <!-- Modale Ajouter un bénévole (Story 5.11) -->
                <dialog id="<?= esc($addModalId, 'attr') ?>" class="k-modal">
                    <div class="k-modal__body">
                        <button type="button" class="k-modal__close"
                                onclick="this.closest('dialog').close()" aria-label="Fermer">×</button>
                        <h3 class="k-modal__title">Ajouter un bénévole</h3>
                        <p class="k-modal__subtitle">
                            <?= esc($pStand['name']) ?> · <?= esc($pSlot['date']) ?> · <?= esc($pSlot['start_time']) ?> – <?= esc($pSlot['end_time']) ?>
                        </p>
                        <form method="post"
                              action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$pSlot['slot_id']}/admin-add-slot-signup") ?>"
                              class="k-modal__form"
                              id="<?= esc($addFormId, 'attr') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_error_slot_id" value="<?= (int) $pSlot['slot_id'] ?>">

                            <div class="form-group form-group--sm">
                                <label class="form-label form-label--sm" for="<?= esc($addFormId, 'attr') ?>-fn">
                                    Prénom <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="<?= esc($addFormId, 'attr') ?>-fn"
                                       name="first_name"
                                       value="<?= $errorSlot ? esc(old('first_name'), 'attr') : '' ?>"
                                       class="form-input form-input--admin"
                                       maxlength="100"
                                       autocomplete="given-name"
                                       required>
                                <?php if ($errorSlot && isset($addFieldErrors['first_name'])): ?>
                                <span class="form-error"><?= esc($addFieldErrors['first_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="form-group form-group--sm">
                                <label class="form-label form-label--sm" for="<?= esc($addFormId, 'attr') ?>-ln">
                                    Nom <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="<?= esc($addFormId, 'attr') ?>-ln"
                                       name="last_name"
                                       value="<?= $errorSlot ? esc(old('last_name'), 'attr') : '' ?>"
                                       class="form-input form-input--admin"
                                       maxlength="100"
                                       autocomplete="family-name"
                                       required>
                                <?php if ($errorSlot && isset($addFieldErrors['last_name'])): ?>
                                <span class="form-error"><?= esc($addFieldErrors['last_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="form-group form-group--sm">
                                <label class="form-label form-label--sm" for="<?= esc($addFormId, 'attr') ?>-email">
                                    Email <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input type="email"
                                       id="<?= esc($addFormId, 'attr') ?>-email"
                                       name="email"
                                       value="<?= $errorSlot ? esc(old('email'), 'attr') : '' ?>"
                                       class="form-input form-input--admin"
                                       maxlength="255"
                                       autocomplete="email"
                                       required>
                                <?php if ($errorSlot && isset($addFieldErrors['email'])): ?>
                                <span class="form-error"><?= esc($addFieldErrors['email']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="form-group form-group--sm">
                                <label class="form-label form-label--sm" for="<?= esc($addFormId, 'attr') ?>-phone">
                                    Téléphone
                                </label>
                                <input type="tel"
                                       id="<?= esc($addFormId, 'attr') ?>-phone"
                                       name="phone"
                                       value="<?= $errorSlot ? esc(old('phone'), 'attr') : '' ?>"
                                       class="form-input form-input--admin-phone"
                                       maxlength="30"
                                       autocomplete="tel">
                                <?php if ($errorSlot && isset($addFieldErrors['phone'])): ?>
                                <span class="form-error"><?= esc($addFieldErrors['phone']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="form-group form-group--sm">
                                <label class="form-label form-label--sm form-label--checkbox">
                                    <input type="checkbox"
                                           name="send_confirmation_email"
                                           value="1"
                                           <?= ($errorSlot && old('send_confirmation_email') === '1') ? 'checked' : '' ?>>
                                    Envoyer un email de confirmation au bénévole
                                </label>
                            </div>

                            <div class="admin-form__buttons">
                                <button type="submit"
                                        class="btn btn--primary btn--sm"
                                        id="<?= esc($addBtnId, 'attr') ?>"
                                        disabled>
                                    Inscrire
                                </button>
                                <button type="button"
                                        class="btn btn--secondary btn--sm"
                                        onclick="this.closest('dialog').close()">
                                    Annuler
                                </button>
                            </div>
                        </form>
                        <script>
                        (function () {
                            var f = document.getElementById('<?= esc($addFormId) ?>');
                            var b = document.getElementById('<?= esc($addBtnId) ?>');
                            function sync() { b.disabled = !f.checkValidity(); }
                            f.addEventListener('input', sync);
                            sync();
                        }());
                        </script>
                        <?php if ($errorSlot): ?>
                        <script>document.getElementById('<?= esc($addModalId) ?>').showModal();</script>
                        <?php endif; ?>
                    </div>
                </dialog>

                <!-- Historique des inscriptions annulées/supprimées (Story 5.10) -->
                <?php if (! empty($pSlot['history'])): ?>
                <details class="participants-slot__history">
                    <summary class="participants-slot__history-toggle">Historique (<?= count($pSlot['history']) ?>)</summary>
                    <ul class="participants-list participants-list--history">
                        <?php foreach ($pSlot['history'] as $hist): ?>
                        <li class="participants-list__item participants-list__item--history">
                            <span class="participants-list__name"><strong><?= esc($hist['last_name']) ?> <?= esc($hist['first_name']) ?></strong></span>
                            <?php if ($hist['status'] === 'removed'): ?>
                            <span class="badge badge--removed">Supprimé par l'admin</span>
                            <?php else: ?>
                            <span class="badge badge--cancelled">Annulé</span>
                            <?php endif; ?>
                            <?php if ($hist['modifier_first_name'] !== null && $hist['modifier_date'] !== null): ?>
                            <span class="participants-list__history-meta">par <?= esc($hist['modifier_first_name']) ?> le <?= esc($hist['modifier_date']) ?></span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- Onglet : Équipe d'organisation (Owner/Admin — Story 4.5 / 5.2)     -->
    <!-- ================================================================== -->
    <?php if (! empty($canInvite)): ?>
    <section
        id="tab-panel-equipe"
        class="kermesse-dashboard__section tab-panel"
        data-tab-content="equipe"
    >
        <?php if ($hasSidebar): ?>
        <button type="button" class="accordion-header" data-tab="equipe" aria-expanded="<?= $defaultTab === 'equipe' ? 'true' : 'false' ?>" aria-controls="tab-panel-equipe">
            <span class="accordion-icon">▶</span> Équipe d'organisation
        </button>
        <?php endif; ?>
        <h2 class="section-title">Gestion de l'équipe d'organisation</h2>

        <div class="team-members">
            <h3 class="subsection-title">Membres actifs</h3>
            <?php
                $activeMembers = $teamMembers['active'] ?? [];
                $pendingMembers = $teamMembers['pending'] ?? [];
                $hasActiveMembers = false;
                foreach ($activeMembers as $group) {
                    if (!empty($group)) {
                        $hasActiveMembers = true;
                        break;
                    }
                }
            ?>
            <?php if (!$hasActiveMembers && empty($pendingMembers)): ?>
                <p class="section-placeholder">Aucun membre dans l'équipe pour le moment.</p>
            <?php else: ?>
                <?php
                    $roleLabels = [
                        'owner'        => 'Propriétaire',
                        'admin'        => 'Administrateurs',
                        'gestionnaire' => 'Gestionnaires',
                    ];
                ?>
                <?php foreach (['owner', 'admin', 'gestionnaire'] as $roleKey): ?>
                    <?php if (!empty($activeMembers[$roleKey])): ?>
                        <div style="margin-bottom:24px;">
                            <h4 class="form-label" style="margin-bottom:12px; color:#495057; font-weight:500;">
                                <?= esc($roleLabels[$roleKey]) ?>
                                <span style="color:#999; font-size:0.9em; margin-left:8px;">(<?= count($activeMembers[$roleKey]) ?>)</span>
                            </h4>
                            <ul class="participants-list" style="border: 1px solid #e9ecef; border-radius: 8px; background: #fff;">
                                <?php foreach ($activeMembers[$roleKey] as $member): ?>
                                <li class="participants-list__item" style="flex-direction:row; justify-content:space-between; align-items:center;">
                                    <div>
                                        <span class="participants-list__name">
                                            <strong><?= esc(trim($member['first_name'] . ' ' . $member['last_name']) ?: 'Sans nom') ?></strong>
                                        </span>
                                        <div class="participants-list__contact" style="margin-top:4px;">
                                            <span aria-hidden="true">✉️</span> <?= esc($member['email']) ?>
                                        </div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <!-- Actions -->
                                        <?php if ($member['role'] !== 'owner'): ?>
                                            <?php $isSelf = (int)$member['user_id'] === $currentUserId; ?>
                                            <?php if ($isSelf): ?>
                                                <span class="kermesse-status-badge kermesse-status-badge--vous">Vous</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn--secondary btn--sm" data-member="<?= esc(json_encode($member)) ?>" onclick="openEditMemberModal(JSON.parse(this.dataset.member))" title="<?= $isSelf ? 'Modifier mon compte' : 'Éditer le membre' ?>" aria-label="<?= $isSelf ? 'Modifier mon compte' : 'Éditer le membre' ?>">✏️</button>
                                            <?php if ($isSelf && $canLeave): ?>
                                                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/leave") ?>" style="margin:0;" onsubmit="return confirm('Voulez-vous vraiment quitter cette organisation ? Le propriétaire sera notifié.');">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn--secondary btn--sm" style="color:#6c757d;" title="Quitter l'organisation" aria-label="Quitter l'organisation">↪️ Quitter</button>
                                                </form>
                                            <?php elseif (!$isSelf): ?>
                                                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/team/{$member['user_id']}/delete") ?>" style="margin:0;" onsubmit="return confirm('Voulez-vous vraiment révoquer le rôle de ce membre ?');">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn--secondary btn--sm" style="color:#dc3545;" title="Révoquer" aria-label="Révoquer le rôle">🗑️</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Pending Invitations Section -->
            <?php if (!empty($pendingMembers)): ?>
                <div style="margin-top:32px; margin-bottom:24px;">
                    <h3 class="subsection-title">Invitations en attente</h3>
                    <p class="form-label" style="color:#666; font-size:0.95em; margin-bottom:12px;">
                        Ces personnes ont reçu une invitation mais n'ont pas encore accédé au tableau de bord.
                    </p>
                    <ul class="participants-list" style="border: 1px solid #fff3cd; border-radius: 8px; background: #fffbf0;">
                        <?php foreach ($pendingMembers as $member): ?>
                        <li class="participants-list__item" style="flex-direction:row; justify-content:space-between; align-items:center;">
                            <div>
                                <span class="participants-list__name">
                                    <strong><?= esc(trim($member['first_name'] . ' ' . $member['last_name']) ?: 'Sans nom') ?></strong>
                                </span>
                                <div class="participants-list__contact" style="margin-top:4px;">
                                    <span aria-hidden="true">✉️</span> <?= esc($member['email']) ?>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span class="kermesse-status-badge kermesse-status-badge--pending">Invitation envoyée</span>
                                <span class="kermesse-status-badge"><?= esc(ucfirst($member['role'])) ?></span>
                                <!-- Defense in depth: an Owner is never "pending" (no invited_at), but the
                                     management actions stay gated on role exactly like the active section. -->
                                <?php if ($member['role'] !== 'owner'): ?>
                                <?php $isSelf = (int)$member['user_id'] === $currentUserId; ?>
                                <?php if ($isSelf): ?>
                                    <span class="kermesse-status-badge kermesse-status-badge--vous">Vous</span>
                                <?php endif; ?>
                                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/team/{$member['user_id']}/resend") ?>" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--secondary btn--sm" title="Relancer l'invitation" aria-label="Relancer l'invitation" style="font-size:1rem; padding:4px 8px;">🔄</button>
                                </form>
                                <button type="button" class="btn btn--secondary btn--sm" data-member="<?= esc(json_encode($member)) ?>" onclick="openEditMemberModal(JSON.parse(this.dataset.member))" title="Éditer le membre" aria-label="Éditer le membre">✏️</button>
                                <?php if (!$isSelf): ?>
                                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/team/{$member['user_id']}/delete") ?>" style="margin:0;" onsubmit="return confirm('Voulez-vous vraiment révoquer ce membre ?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--secondary btn--sm" style="color:#dc3545;" title="Révoquer" aria-label="Révoquer le membre">🗑️</button>
                                </form>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Edit Member Modal -->
                <dialog id="edit-member-modal" class="k-modal">
                    <form method="post" id="edit-member-form" class="k-modal__content">
                        <?= csrf_field() ?>
                        <h3 class="k-modal__title">Éditer le membre</h3>
                        <p class="form-warning" id="edit-member-warning" style="display:none; margin-bottom:16px;">
                            L'invitation ayant été acceptée, ce membre gère désormais son propre compte. Vous ne pouvez modifier que son rôle.
                        </p>

                        <div class="form-group">
                            <label for="edit-member-role" class="form-label">Rôle attribué</label>
                            <select id="edit-member-role" name="role" class="form-control" required>
                                <option value="admin">Administrateur</option>
                                <option value="gestionnaire">Gestionnaire</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-member-first-name" class="form-label">Prénom</label>
                            <input type="text" id="edit-member-first-name" name="first_name" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="edit-member-last-name" class="form-label">Nom</label>
                            <input type="text" id="edit-member-last-name" name="last_name" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="edit-member-email" class="form-label">Adresse email</label>
                            <input type="email" id="edit-member-email" name="email" class="form-control" required>
                        </div>

                        <div class="k-modal__actions">
                            <button type="button" class="btn btn--secondary" onclick="document.getElementById('edit-member-modal').close()">Annuler</button>
                            <button type="submit" class="btn btn--primary">Enregistrer</button>
                        </div>
                    </form>
                </dialog>

                <script>
                function openEditMemberModal(member) {
                    var modal = document.getElementById('edit-member-modal');
                    var form  = document.getElementById('edit-member-form');
                    var kermesseId = <?= json_encode($kermesse['id']) ?>;

                    form.action = '<?= site_url() ?>kermesse/' + kermesseId + '/team/' + member.user_id + '/edit';

                    document.getElementById('edit-member-role').value       = member.role;
                    document.getElementById('edit-member-first-name').value = member.first_name || '';
                    document.getElementById('edit-member-last-name').value  = member.last_name  || '';
                    document.getElementById('edit-member-email').value      = member.email      || '';

                    var isAccepted = member.accepted_at !== null;
                    document.getElementById('edit-member-warning').style.display = isAccepted ? 'block' : 'none';

                    document.getElementById('edit-member-first-name').readOnly = isAccepted;
                    document.getElementById('edit-member-last-name').readOnly  = isAccepted;
                    document.getElementById('edit-member-email').readOnly      = isAccepted;

                    ['edit-member-first-name', 'edit-member-last-name', 'edit-member-email'].forEach(function(id) {
                        var el = document.getElementById(id);
                        if (isAccepted) {
                            el.style.backgroundColor = '#e9ecef';
                            el.title = "Non modifiable par l'Owner car l'invitation a été acceptée.";
                        } else {
                            el.style.backgroundColor = '';
                            el.title = '';
                        }
                    });

                    modal.showModal();
                }
                </script>
        </div>

        <div class="invite-form">
            <h3 class="subsection-title">Inviter un administrateur ou un gestionnaire</h3>

            <?php if (! empty($inviteSuccess = session()->getFlashdata('invite_success'))): ?>
            <p class="form-success" role="status"><?= esc($inviteSuccess) ?></p>
            <?php endif; ?>
            <?php if (! empty($inviteWarning = session()->getFlashdata('invite_warning'))): ?>
            <p class="form-warning" role="alert"><?= esc($inviteWarning) ?></p>
            <?php endif; ?>
            <?php if (! empty($inviteError = session()->getFlashdata('invite_error'))): ?>
            <p class="form-error" role="alert"><?= esc($inviteError) ?></p>
            <?php endif; ?>

            <?php $oldEmail = old('email'); $oldEmail = is_array($oldEmail) ? '' : (string) $oldEmail; ?>
            <?php $oldRole = old('role'); $oldRole = is_array($oldRole) ? '' : (string) $oldRole; ?>
            <?php $oldFirstName = old('first_name'); $oldFirstName = is_array($oldFirstName) ? '' : (string) $oldFirstName; ?>
            <?php $oldLastName = old('last_name'); $oldLastName = is_array($oldLastName) ? '' : (string) $oldLastName; ?>
            <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/invitations") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                <?= csrf_field() ?>
                <div class="form-group" style="display: flex; gap: 16px;">
                    <div style="flex: 1;">
                        <label for="invite-firstname" class="form-label">Prénom</label>
                        <input type="text" id="invite-firstname" name="first_name" class="form-control" value="<?= esc($oldFirstName) ?>" placeholder="Ex. : Jean" required>
                    </div>
                    <div style="flex: 1;">
                        <label for="invite-lastname" class="form-label">Nom</label>
                        <input type="text" id="invite-lastname" name="last_name" class="form-control" value="<?= esc($oldLastName) ?>" placeholder="Ex. : Dupont" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="invite-email" class="form-label">Email de la personne à inviter</label>
                    <input type="email" id="invite-email" name="email" class="form-control" value="<?= esc($oldEmail) ?>" placeholder="Ex. : prenom.nom@exemple.fr" required>
                </div>
                <div class="form-group">
                    <label for="invite-role" class="form-label">Rôle attribué</label>
                    <select id="invite-role" name="role" class="form-control" required>
                        <option value="admin"<?= $oldRole === 'admin' ? ' selected' : '' ?>>Administrateur</option>
                        <option value="gestionnaire"<?= $oldRole === 'gestionnaire' ? ' selected' : '' ?>>Gestionnaire</option>
                    </select>
                </div>
                <button type="submit" class="btn btn--primary">Envoyer l'invitation</button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- Onglet : Mes participations — tout rôle (Stories 4.2, 4.3, 5.2).   -->
    <!-- ================================================================== -->
    <section
        id="tab-panel-participations"
        class="kermesse-dashboard__section tab-panel"
        data-tab-content="participations"
    >
        <?php if ($hasSidebar): ?>
        <button type="button" class="accordion-header" data-tab="participations" aria-expanded="<?= $defaultTab === 'participations' ? 'true' : 'false' ?>" aria-controls="tab-panel-participations">
            <span class="accordion-icon">▶</span> Mes participations
        </button>
        <?php endif; ?>
        <h2 class="section-title">Mes participations</h2>

        <?php if (! empty($participationNotice = session()->getFlashdata('participation_notice'))): ?>
        <p class="form-success"><?= esc($participationNotice) ?></p>
        <?php endif; ?>
        <?php if (! empty($participationError = session()->getFlashdata('participation_error'))): ?>
        <p class="form-error" role="alert"><?= esc($participationError) ?></p>
        <?php endif; ?>

        <?php $myParticipations = $myParticipations ?? []; ?>
        <?php $signupsOpen = $signupsOpen ?? false; ?>
        <?php $canLeave = $canLeave ?? false; ?>
        <?php if (empty($myParticipations)): ?>
        <p class="section-placeholder">Vous n'avez aucune inscription active sur cette kermesse.</p>
        <?php if ($canLeave): ?>
        <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/leave") ?>" style="margin-top:16px;"
              onsubmit="if (!confirm('Voulez-vous quitter cette kermesse ?')) { return false; } this.querySelector('button[type=submit]').disabled = true;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--secondary btn--sm">Quitter cette kermesse</button>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <ul class="my-signups-list">
            <?php foreach ($myParticipations as $participation): ?>
            <li class="my-signups-list__item">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <div>
                        <span class="my-signups-list__stand"><strong><?= esc($participation['stand_name']) ?></strong></span>
                        <span class="my-signups-list__when" style="display:block; margin-top:2px;">
                            <span aria-hidden="true">📅</span> <?= esc($participation['date']) ?>
                            · <span aria-hidden="true">🕐</span> <?= esc($participation['start_time']) ?> – <?= esc($participation['end_time']) ?>
                        </span>
                        <?php if (! empty($participation['needs_confirmation'])): ?>
                        <span class="badge badge--warning" style="margin-top:4px; display:inline-block;" aria-label="En attente de confirmation">En attente de confirmation</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                        <?php if (! empty($participation['needs_confirmation'])): ?>
                        <!-- Boutons Accepter / Refuser (Story 5.14 AC2/3/4) -->
                        <form method="post"
                              action="<?= site_url("kermesse/{$kermesse['id']}/slot-signups/{$participation['signup_id']}/accept") ?>"
                              onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--primary btn--sm">Accepter</button>
                        </form>
                        <form method="post"
                              action="<?= site_url("kermesse/{$kermesse['id']}/slot-signups/{$participation['signup_id']}/reject") ?>"
                              onsubmit="if (!confirm('Voulez-vous vraiment refuser cette participation ?')) { return false; } this.querySelector('button[type=submit]').disabled = true;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--danger btn--sm">Refuser</button>
                        </form>
                        <?php elseif ($signupsOpen || ! empty($participation['is_confirmed'])): ?>
                        <form method="post"
                              action="<?= site_url("kermesse/{$kermesse['id']}/slot-signups/{$participation['signup_id']}/cancel") ?>"
                              class="my-signups-list__cancel"
                              onsubmit="if (!confirm('Voulez-vous vraiment annuler cette participation ?')) { return false; } this.querySelector('button[type=submit]').disabled = true;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--danger btn--sm">Annuler ma participation</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

        </div> <!-- Fin de dashboard-content -->
    </div> <!-- Fin de dashboard-layout ou dashboard-single -->

    <div class="kermesse-dashboard__actions">
        <a href="<?= site_url('/') ?>" class="btn btn--secondary">Retour à mes kermesses</a>
    </div>
</div>



<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
/* ---- Navigation Sidebar & Accordéon (Story 5.3) ----------------------- */
(function () {
    var sidebarBtns = document.querySelectorAll('.sidebar-nav__btn');
    var accHeaders  = document.querySelectorAll('.accordion-header');
    var panels      = document.querySelectorAll('.tab-panel');

    if (!panels.length) { return; }

    /* Indique au CSS que le JS est actif (pour l'amélioration progressive) */
    document.body.classList.add('js-active');

    function activateSection(targetId) {
        /* Met à jour la sidebar */
        sidebarBtns.forEach(function (btn) {
            var isActive = btn.getAttribute('data-tab') === targetId;
            if (isActive) {
                btn.classList.add('is-active');
            } else {
                btn.classList.remove('is-active');
            }
            btn.setAttribute('aria-expanded', isActive ? 'true' : 'false');
        });

        /* Met à jour les accordéons et panneaux */
        accHeaders.forEach(function (hdr) {
            var isActive = hdr.getAttribute('data-tab') === targetId;
            hdr.setAttribute('aria-expanded', isActive ? 'true' : 'false');
            var icon = hdr.querySelector('.accordion-icon');
            if (icon) { icon.textContent = isActive ? '▼' : '▶'; }
        });

        panels.forEach(function (p) {
            if (p.getAttribute('data-tab-content') === targetId) {
                p.classList.add('is-open');
            } else {
                p.classList.remove('is-open');
            }
        });
    }

    /* Initialisation : hash URL en priorité, sinon première section */
    var hashTab = window.location.hash ? window.location.hash.slice(1) : null;
    var hashTabValid = hashTab && document.querySelector('[data-tab-content="' + hashTab + '"]');
    if (hashTabValid) {
        activateSection(hashTab);
    } else {
        var activeSidebarBtn = document.querySelector('.sidebar-nav__btn.is-active');
        if (activeSidebarBtn) {
            activateSection(activeSidebarBtn.getAttribute('data-tab'));
        } else if (sidebarBtns.length > 0) {
            activateSection(sidebarBtns[0].getAttribute('data-tab'));
        } else {
            // No sidebar (single-panel view, e.g. benevole): activate the only panel so
            // the CSS rule body.js-active .tab-panel:not(.is-open) doesn't hide it.
            activateSection(panels[0].getAttribute('data-tab-content'));
        }
    }

    /* Event listeners Sidebar */
    sidebarBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateSection(btn.getAttribute('data-tab'));
        });
    });

    /* Event listeners Accordéon — toggle : cliquer une section ouverte la replie */
    accHeaders.forEach(function (hdr) {
        hdr.addEventListener('click', function () {
            var targetId = hdr.getAttribute('data-tab');
            if (hdr.getAttribute('aria-expanded') === 'true') {
                // Repli : retire is-open de tous les panneaux → seuls les en-têtes restent visibles
                sidebarBtns.forEach(function (b) {
                    b.classList.remove('is-active');
                    b.setAttribute('aria-expanded', 'false');
                });
                accHeaders.forEach(function (h) {
                    h.setAttribute('aria-expanded', 'false');
                    var icon = h.querySelector('.accordion-icon');
                    if (icon) { icon.textContent = '▶'; }
                });
                panels.forEach(function (p) { p.classList.remove('is-open'); });
            } else {
                activateSection(targetId);
                hdr.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
}());

/* ---- Confirmation suppression (SUPPRIMER) ----------------------------- */
document.querySelectorAll('[data-delete-confirm]').forEach(function (input) {
    var btnId = input.getAttribute('data-delete-confirm');
    var btn   = document.getElementById(btnId);
    if (!btn) { return; }
    btn.disabled = input.value.trim().toUpperCase() !== 'SUPPRIMER';
    input.addEventListener('input', function () {
        btn.disabled = this.value.trim().toUpperCase() !== 'SUPPRIMER';
    });
});

/* ---- Bouton actif uniquement quand le champ est non vide (duplication) - */
document.querySelectorAll('[data-require-nonempty]').forEach(function (input) {
    var btnId = input.getAttribute('data-require-nonempty');
    var btn   = document.getElementById(btnId);
    if (!btn) { return; }
    var sync = function () { btn.disabled = input.value.trim() === ''; };
    sync();
    input.addEventListener('input', sync);
});

/* ---- Copie du lien public --------------------------------------------- */
(function () {
    var btn      = document.getElementById('copy-link-btn');
    var feedback = document.getElementById('copy-link-feedback');
    if (!btn || !feedback) { return; }

    function showSuccess() {
        feedback.style.display = 'inline-block';
        if (btn.timer) { clearTimeout(btn.timer); }
        btn.timer = setTimeout(function () { feedback.style.display = 'none'; }, 5000);
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:0;left:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { if (document.execCommand('copy')) { showSuccess(); } }
        catch (err) { alert('Erreur lors de la copie. Veuillez copier le lien manuellement.'); }
        document.body.removeChild(ta);
    }

    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy-url');
        if (!navigator.clipboard) { fallbackCopy(text); return; }
        navigator.clipboard.writeText(text).then(showSuccess).catch(function () { fallbackCopy(text); });
    });
}());

/* ---- Ouverture automatique des modales post-redirect (data-auto-open) - */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('dialog[data-auto-open]').forEach(function (d) { d.showModal(); });
});
</script>
<?= $this->endSection() ?>
