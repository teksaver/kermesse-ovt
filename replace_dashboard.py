import re

with open('app/Views/kermesse/dashboard.php', 'r') as f:
    content = f.read()

replacement = """    <section class="kermesse-dashboard__section" id="stands">
        <h2 class="section-title">Stands</h2>

        <?php if (! empty($success = session()->getFlashdata('success'))): ?>
        <p class="form-success"><?= esc($success) ?></p>
        <?php endif; ?>

        <?php
            $standError     = session()->getFlashdata('stand_error')    ?? ($stand_error ?? null);
            $standForm      = session()->getFlashdata('stand_form')     ?? ($stand_form ?? null);
            $standName      = session()->getFlashdata('stand_name')     ?? ($stand_name ?? '');
            $editingStandId = session()->getFlashdata('editing_stand_id') ?? ($editing_stand_id ?? null);
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
                            <details class="stand-rename" <?= ($standForm === 'edit' && $editingStandId === $sid) ? 'open' : '' ?>>
                                <summary class="btn btn--sm" style="width:100%;">Renommer</summary>
                                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;" style="margin-top:8px;">
                                    <?= csrf_field() ?>
                                    <input type="text" name="name" class="form-control" value="<?= esc(($standForm === 'edit' && $editingStandId === $sid) ? $standName : $stand['name']) ?>" required>
                                    <button type="submit" class="btn btn--primary btn--sm" style="margin-top:4px; width:100%;">Valider</button>
                                </form>
                            </details>
                            
                            <!-- Dupliquer -->
                            <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/duplicate") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--sm" style="width:100%;">Dupliquer</button>
                            </form>
                            
                            <!-- Supprimer -->
                            <?php
                                $requiresStrong = ! empty($stand['requires_strong_confirm']);
                                $deleteError    = session()->getFlashdata('delete_error_' . $sid);
                                $hasDeleteError = $deleteError !== null;
                            ?>
                            <details class="stand-delete"<?= $hasDeleteError ? ' open' : '' ?>>
                                <summary class="btn btn--danger btn--sm" style="width:100%;">Supprimer</summary>
                                <div class="stand-delete__confirm" style="margin-top:8px; border-top:1px solid #eee; padding-top:8px;">
                                    <?php if ($requiresStrong): ?>
                                    <p class="stand-delete__warning" style="font-size:12px;">Bénévoles inscrits. Saisissez <strong>SUPPRIMER</strong> :</p>
                                    <?php else: ?>
                                    <p class="stand-delete__warning" style="font-size:12px;">Confirmer la suppression ?</p>
                                    <?php endif; ?>
            
                                    <?php if ($hasDeleteError): ?>
                                    <p class="form-error" role="alert" id="delete-error-<?= $sid ?>" style="font-size:12px;"><?= esc($deleteError) ?></p>
                                    <?php endif; ?>
            
                                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/delete") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                        <?= csrf_field() ?>
                                        <?php if ($requiresStrong): ?>
                                        <input type="text" id="confirm-delete-<?= $sid ?>" name="confirm" class="form-control" placeholder="SUPPRIMER" autocomplete="off" data-delete-confirm="delete-btn-<?= $sid ?>" style="margin-bottom:8px;">
                                        <button type="submit" id="delete-btn-<?= $sid ?>" class="btn btn--danger btn--sm" style="width:100%;" disabled>Confirmer</button>
                                        <?php else: ?>
                                        <button type="submit" class="btn btn--danger btn--sm" style="width:100%;">Confirmer</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </details>
                </div>

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
                            <!-- Modifier (Crayon) -->
                            <?php $isEditingThis = ($slotForm === 'edit' && $slotFormSlotId === $slotId); ?>
                            <details class="slot-edit" <?= $isEditingThis ? 'open' : '' ?>>
                                <summary class="btn btn--sm" title="Modifier" style="padding:4px 8px;">✏️</summary>
                                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$slotId}") ?>" style="position:absolute; right:0; top:32px; background:#fff; padding:12px; border:1px solid var(--border-color, #ddd); box-shadow:0 4px 6px rgba(0,0,0,0.1); border-radius:8px; z-index:10; width:250px;" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                    <?= csrf_field() ?>
                                    <?php
                                        $editStartsAt  = $isEditingThis ? old('starts_at', date('H:i', strtotime($slot['starts_at']))) : date('H:i', strtotime($slot['starts_at']));
                                        $editEndsAt    = $isEditingThis ? old('ends_at', date('H:i', strtotime($slot['ends_at'])))     : date('H:i', strtotime($slot['ends_at']));
                                        $editCapacity  = $isEditingThis ? old('capacity', $slot['capacity'])   : $slot['capacity'];
                                    ?>
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label class="form-label" style="font-size:12px;">Début</label>
                                        <input type="time" name="starts_at" class="form-control" value="<?= esc($editStartsAt) ?>" required>
                                    </div>
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label class="form-label" style="font-size:12px;">Fin</label>
                                        <input type="time" name="ends_at" class="form-control" value="<?= esc($editEndsAt) ?>" required>
                                    </div>
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label class="form-label" style="font-size:12px;">Capacité</label>
                                        <input type="number" name="capacity" class="form-control" value="<?= esc($editCapacity) ?>" min="1" required>
                                    </div>
                                    <button type="submit" class="btn btn--primary btn--sm" style="width:100%;">Enregistrer</button>
                                </form>
                            </details>

                            <!-- Supprimer (Corbeille) -->
                            <?php
                                $deleteSlotError = session()->getFlashdata('delete_slot_error_' . $slotId);
                                $hasDeleteSlotError = $deleteSlotError !== null;
                            ?>
                            <details class="slot-delete" <?= $hasDeleteSlotError ? 'open' : '' ?>>
                                <summary class="btn btn--danger btn--sm" title="Supprimer" style="padding:4px 8px;">🗑️</summary>
                                <div style="position:absolute; right:0; top:32px; background:#fff; padding:12px; border:1px solid var(--border-color, #ddd); box-shadow:0 4px 6px rgba(0,0,0,0.1); border-radius:8px; z-index:10; width:250px;">
                                    <?php if ($hasDeleteSlotError): ?>
                                    <p class="form-error" role="alert" id="delete-slot-error-<?= $slotId ?>" style="font-size:12px;"><?= esc($deleteSlotError) ?></p>
                                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$slotId}/delete") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                        <?= csrf_field() ?>
                                        <input type="text" id="confirm-delete-slot-<?= $slotId ?>" name="confirm" class="form-control" placeholder="SUPPRIMER" autocomplete="off" data-delete-confirm="delete-slot-btn-<?= $slotId ?>" style="margin-bottom:8px;">
                                        <button type="submit" id="delete-slot-btn-<?= $slotId ?>" class="btn btn--danger btn--sm" style="width:100%;" disabled>Confirmer</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$slotId}/delete") ?>" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                                        <?= csrf_field() ?>
                                        <p style="font-size:12px; margin-bottom:8px;">Confirmer la suppression du créneau ?</p>
                                        <button type="submit" class="btn btn--danger btn--sm" style="width:100%;">Supprimer</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="slots-list--empty" style="margin-top:16px;">Aucun créneau pour ce stand.</p>
                <?php endif; ?>

                <!-- Bouton Ajouter un créneau -->
                <?php $isAddingHere = ($slotForm === 'add' && $slotFormStandId === $sid); ?>
                <details class="slot-add" <?= $isAddingHere ? 'open' : '' ?> style="margin-top:16px;">
                    <summary class="btn btn--sm btn--secondary">Ajouter un créneau</summary>
                    <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/slots") ?>" class="slot-add-form" style="margin-top:16px; padding:16px; border:1px solid #eee; border-radius:8px; background:#fafafa;" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label">Début</label>
                            <input type="time" name="starts_at" class="form-control" value="<?= $isAddingHere ? esc(old('starts_at', '')) : '' ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fin</label>
                            <input type="time" name="ends_at" class="form-control" value="<?= $isAddingHere ? esc(old('ends_at', '')) : '' ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Capacité</label>
                            <input type="number" name="capacity" class="form-control" value="<?= $isAddingHere ? esc(old('capacity', '')) : '' ?>" min="1" required>
                        </div>
                        <button type="submit" class="btn btn--primary btn--sm">Ajouter</button>
                    </form>
                </details>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- Bouton Ajouter un stand (en bas de liste) -->
        <div style="margin-top:24px;">
            <details class="stand-add" <?= ($standForm === 'add') ? 'open' : '' ?>>
                <summary class="btn btn--primary">Ajouter un stand</summary>
                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands") ?>" class="stand-add-form" style="margin-top:16px; padding:16px; border:1px solid #eee; border-radius:8px; background:#fafafa;" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="stand-name" class="form-label">Nom du stand</label>
                        <input type="text" id="stand-name" name="name" class="form-control<?= ($standForm === 'add' && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc($standForm === 'add' ? $standName : '') ?>" placeholder="Ex. : Stand Buvette" required>
                        <?php if ($standForm === 'add' && $standError !== null): ?>
                        <span id="stand-add-error" class="form-error"><?= esc($standError) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn--primary">Créer le stand</button>
                </form>
            </details>
        </div>

        <?php else: ?>
        <div class="stands-empty-state" style="text-align:center; padding:48px 0; border:2px dashed #eee; border-radius:8px; margin-top:24px;">
            <p style="font-size:18px; color:#666; margin-bottom:24px;">Créez votre premier stand</p>
            <details class="stand-add" <?= ($standForm === 'add') ? 'open' : '' ?>>
                <summary class="btn btn--primary">Ajouter un stand</summary>
                <form method="post" action="<?= site_url("kermesse/{$kermesse['id']}/stands") ?>" class="stand-add-form" style="margin-top:16px; text-align:left; max-width:400px; margin-left:auto; margin-right:auto; padding:16px; border:1px solid #eee; border-radius:8px; background:#fafafa;" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="stand-name" class="form-label">Nom du stand</label>
                        <input type="text" id="stand-name" name="name" class="form-control<?= ($standForm === 'add' && $standError !== null) ? ' is-invalid' : '' ?>" value="<?= esc($standForm === 'add' ? $standName : '') ?>" placeholder="Ex. : Stand Buvette" required>
                        <?php if ($standForm === 'add' && $standError !== null): ?>
                        <span id="stand-add-error" class="form-error"><?= esc($standError) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn--primary">Créer le stand</button>
                </form>
            </details>
        </div>
        <?php endif; ?>
    </section>"""

new_content = re.sub(r'    <section class="kermesse-dashboard__section" id="stands">.*?</section>', replacement, content, flags=re.DOTALL)

with open('app/Views/kermesse/dashboard.php', 'w') as f:
    f.write(new_content)
