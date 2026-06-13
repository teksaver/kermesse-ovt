<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="kermesse-dashboard">
    <h1 class="page-title"><?= esc($kermesse['name']) ?></h1>

    <div class="kermesse-dashboard__info kermesse-characteristics" style="position:relative;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <?= view('partials/status_badge', ['status' => $kermesse['status']]) ?>
            </div>
            <?php if (isset($canManageLifecycle) && $canManageLifecycle): ?>
            <button type="button" class="btn btn--sm" title="Modifier la kermesse" onclick="document.getElementById('modal-kermesse-edit').showModal()">✏️ Modifier</button>
            <?php endif; ?>
        </div>

        <div style="margin-top:16px; display:flex; flex-direction:column; gap:8px;">
            <?php if (! empty($kermesse['event_date'])): ?>
            <p class="kermesse-dashboard__date" style="margin:0;">📅 <strong>Date :</strong> <?= esc($kermesse['event_date']) ?></p>
            <?php endif; ?>

            <?php if (! empty($kermesse['location'])): ?>
            <p class="kermesse-dashboard__location" style="margin:0;">📍 <strong>Lieu :</strong> <?= esc($kermesse['location']) ?></p>
            <?php endif; ?>

            <?php if (! empty($kermesse['short_description'])): ?>
            <p class="kermesse-dashboard__description" style="margin:0;">📝 <strong>Description :</strong> <?= esc($kermesse['short_description']) ?></p>
            <?php endif; ?>
            
            <p class="kermesse-dashboard__public-link" style="margin:0; margin-top:8px;">🔗 <strong>Lien à partager pour les inscriptions :</strong> 
                <a href="<?= esc(site_url("k/{$kermesse['public_slug']}")) ?>" target="_blank" rel="noopener noreferrer"><?= esc(site_url("k/{$kermesse['public_slug']}")) ?></a>
                <button type="button" class="btn btn--icon" title="Copier le lien" data-copy-url="<?= esc(site_url("k/{$kermesse['public_slug']}")) ?>" id="copy-link-btn" style="background:transparent; border:none; cursor:pointer; font-size:1.2em; vertical-align:middle; padding: 0 4px;">📋</button>
                <span id="copy-link-feedback" class="copy-feedback" aria-live="polite" style="color: #155724; background: #d4edda; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; font-weight: bold; margin-left: 8px; margin-top: 4px; display: none;">Lien copié avec succès, vous pouvez le coller dans un email ou un message pour partager à vos futurs bénévoles</span>
            </p>
        </div>

        <?php $lifecycleError = session()->getFlashdata('lifecycle_error'); ?>
        <?php if ($lifecycleError !== null): ?>
        <p class="form-error" role="alert"><?= esc($lifecycleError) ?></p>
        <?php endif; ?>

        <!-- Actions lifecycle (UX-DR17) -->
        <div class="kermesse-dashboard__lifecycle">
            <?php if (isset($canManageLifecycle) && $canManageLifecycle): ?>
                <?php if ($kermesse['status'] === 'open'): ?>
                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/close") ?>" onsubmit="this.querySelector('button').disabled = true;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--warning">Fermer les inscriptions</button>
                </form>
                <?php elseif ($kermesse['status'] === 'preparation'): ?>
                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/open") ?>" onsubmit="this.querySelector('button').disabled = true;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--primary">Ouvrir les inscriptions</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>

            <a href="<?= site_url("k/{$kermesse['public_slug']}") ?>"
               target="_blank"
               rel="noopener noreferrer"
               class="btn btn--secondary">Accéder à la page publique</a>

        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    
    <!-- Modale Édition Kermesse -->
    <?php if (isset($canManageLifecycle) && $canManageLifecycle): ?>
    <?php
        $kermesseForm = session()->getFlashdata('kermesse_form');
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

    <!-- Section Stands                                                        -->
    <!-- ------------------------------------------------------------------ -->
    <section class="kermesse-dashboard__section" id="stands">
        <h2 class="section-title">Stands</h2>

        <?php if (! empty($success = session()->getFlashdata('success'))): ?>
        <p class="form-success"><?= esc($success) ?></p>
        <?php endif; ?>

        <?php
            $standError     = session()->getFlashdata('stand_error')    ?? ($stand_error ?? null);
            $standForm      = session()->getFlashdata('stand_form')     ?? ($stand_form ?? null);
            $standName      = session()->getFlashdata('stand_name')     ?? ($stand_name ?? '');
            
            $editingStandIdRaw = session()->getFlashdata('editing_stand_id') ?? ($editing_stand_id ?? null);
            $editingStandId = $editingStandIdRaw !== null ? (int) $editingStandIdRaw : null;
            
            $stands         = $stands ?? [];

            $slotErrors     = session()->getFlashdata('slot_errors')        ?? [];
            $slotForm       = session()->getFlashdata('slot_form')          ?? null;
            $slotFormStandId = (int) (session()->getFlashdata('slot_form_stand_id') ?? 0);
            $slotFormSlotId  = (int) (session()->getFlashdata('slot_form_slot_id')  ?? 0);
        ?>

        <!-- Liste des stands actifs -->
        <?php if (! empty($stands)): ?>
        <ul class="stands-list">
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
                            <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/duplicate") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--sm" style="width:100%;">Dupliquer</button>
                            </form>
                            
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
                            <input type="text" name="name" class="form-control<?= ($standForm === 'edit' && $editingStandId === $sid && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc(($standForm === 'edit' && $editingStandId === $sid) ? $standName : $stand['name']) ?>" required>
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
                            $deleteSlotError = session()->getFlashdata('delete_slot_error_' . $slotId);
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
                    $isAddingHere = ($slotForm === 'add' && $slotFormStandId === $sid);
                    $defaultStartsAt = '';
                    $defaultEndsAt = '';
                    $defaultCapacity = '';
                    if (!empty($stand['slots'])) {
                        $lastSlot = end($stand['slots']);
                        $defaultStartsAt = date('H:i', strtotime($lastSlot['ends_at']));
                        $dur = strtotime($lastSlot['ends_at']) - strtotime($lastSlot['starts_at']);
                        $defaultEndsAt = date('H:i', strtotime($defaultStartsAt) + $dur);
                        $defaultCapacity = $lastSlot['capacity'];
                    }
                ?>
                <dialog id="modal-slot-add-<?= $sid ?>" class="k-modal" <?= $isAddingHere ? 'data-auto-open' : '' ?>>
>
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
                    <input type="text" id="stand-name" name="name" class="form-control<?= ($standForm === 'add' && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc($standForm === 'add' ? $standName : '') ?>" placeholder="Ex. : Stand Buvette" required>
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


    <div class="kermesse-dashboard__actions">
        <a href="<?= site_url('/') ?>" class="btn btn--secondary">Retour à mes kermesses</a>
    </div>
</div>


<style>
.k-modal {
    border: none;
    border-radius: 8px;
    padding: 0;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.k-modal::backdrop {
    background-color: rgba(0,0,0,0.5);
}
.k-modal__form {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.k-modal__title {
    margin: 0;
    font-size: 1.25rem;
}
.k-modal__text {
    margin: 0;
    font-size: 0.95rem;
    color: var(--text-color);
}
.k-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 8px;
}

.form-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
    font-weight: bold;
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-success::before {
    content: "✅";
}
.kermesse-characteristics {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 32px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('[data-delete-confirm]').forEach(function (input) {
    var btnId = input.getAttribute('data-delete-confirm');
    var btn   = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = input.value.trim().toUpperCase() !== 'SUPPRIMER';
    input.addEventListener('input', function () {
        btn.disabled = this.value.trim().toUpperCase() !== 'SUPPRIMER';
    });
});

(function () {
    var btn      = document.getElementById('copy-link-btn');
    var feedback = document.getElementById('copy-link-feedback');
    if (!btn || !feedback) return;
    
    function showSuccess() {
        feedback.style.display = 'inline-block';
        if (btn.timer) clearTimeout(btn.timer);
        btn.timer = setTimeout(function () { feedback.style.display = 'none'; }, 5000);
    }
    
    function fallbackCopyTextToClipboard(text) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showSuccess();
            } else {
                alert('Erreur lors de la copie manuelle. Veuillez copier le lien manuellement.');
            }
        } catch (err) {
            console.error('Erreur lors de la copie fallback', err);
            alert('Erreur lors de la copie. Copie manuelle requise.');
        }
        document.body.removeChild(textArea);
    }

    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy-url');
        if (!navigator.clipboard) {
            fallbackCopyTextToClipboard(text);
            return;
        }
        navigator.clipboard.writeText(text).then(function () {
            showSuccess();
        }).catch(function(err) {
            console.warn('Erreur navigator.clipboard, essai du fallback:', err);
            fallbackCopyTextToClipboard(text);
        });
    });
}());
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var autoOpenModals = document.querySelectorAll('dialog[data-auto-open]');
    autoOpenModals.forEach(function(dialog) {
        dialog.showModal();
    });
});
</script>
<?= $this->endSection() ?>
