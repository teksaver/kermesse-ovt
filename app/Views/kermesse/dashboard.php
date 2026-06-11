<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="kermesse-dashboard">
    <h1 class="page-title"><?= esc($kermesse['name']) ?></h1>

    <div class="kermesse-dashboard__info">
        <?= $this->include('partials/status_badge', ['status' => $kermesse['status']]) ?>

        <?php if (! empty($kermesse['event_date'])): ?>
        <p class="kermesse-dashboard__date"><?= esc($kermesse['event_date']) ?></p>
        <?php endif; ?>

        <?php if (! empty($kermesse['location'])): ?>
        <p class="kermesse-dashboard__location"><?= esc($kermesse['location']) ?></p>
        <?php endif; ?>

        <?php if (! empty($kermesse['short_description'])): ?>
        <p class="kermesse-dashboard__description"><?= esc($kermesse['short_description']) ?></p>
        <?php endif; ?>
    </div>

    <!-- ------------------------------------------------------------------ -->
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
                <span class="stands-list__name"><?= esc($stand['name']) ?></span>

                <!-- Formulaire de renommage -->
                <form method="post"
                      action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}") ?>"
                      class="stands-list__rename-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="rename-stand-<?= $sid ?>" class="sr-only">
                            Nouveau nom
                        </label>
                        <input type="text"
                               id="rename-stand-<?= $sid ?>"
                               name="name"
                               class="form-control<?= ($standForm === 'edit' && $editingStandId === $sid) ? ' is-invalid' : '' ?>"
                               value="<?= esc(($standForm === 'edit' && $editingStandId === $sid) ? $standName : $stand['name']) ?>"
                               placeholder="Nom du stand"
                               required
                               aria-describedby="<?= ($standForm === 'edit' && $editingStandId === $sid) ? "stand-error-{$sid}" : '' ?>">

                        <?php if ($standForm === 'edit' && $editingStandId === $sid && $standError !== null): ?>
                        <span id="stand-error-<?= $sid ?>" class="form-error">
                            <?= esc($standError) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm">Renommer</button>
                </form>

                <!-- Liste des créneaux du stand -->
                <?php if (! empty($stand['slots'])): ?>
                <ul class="slots-list">
                    <?php foreach ($stand['slots'] as $slot): ?>
                    <?php $slotId = (int) $slot['id']; ?>
                    <li class="slots-list__item">
                        <span class="slots-list__time">
                            <?= esc(date('H:i', strtotime($slot['starts_at']))) ?> –
                            <?= esc(date('H:i', strtotime($slot['ends_at']))) ?>
                        </span>
                        <span class="slots-list__capacity"><?= (int) $slot['capacity'] ?> place<?= (int) $slot['capacity'] > 1 ? 's' : '' ?></span>

                        <!-- Formulaire de modification de créneau -->
                        <form method="post"
                              action="<?= site_url("kermesse/{$kermesse['id']}/slots/{$slotId}") ?>"
                              class="slot-edit-form">
                            <?= csrf_field() ?>
                            <?php
                                $isEditingThis = ($slotForm === 'edit' && $slotFormSlotId === $slotId);
                                $editStartsAt  = $isEditingThis ? old('starts_at', date('Y-m-d\TH:i', strtotime($slot['starts_at']))) : date('Y-m-d\TH:i', strtotime($slot['starts_at']));
                                $editEndsAt    = $isEditingThis ? old('ends_at', date('Y-m-d\TH:i', strtotime($slot['ends_at'])))     : date('Y-m-d\TH:i', strtotime($slot['ends_at']));
                                $editCapacity  = $isEditingThis ? old('capacity', $slot['capacity'])   : $slot['capacity'];
                            ?>
                            <div class="form-group">
                                <label for="slot-starts-<?= $slotId ?>" class="form-label">Début</label>
                                <input type="datetime-local"
                                       id="slot-starts-<?= $slotId ?>"
                                       name="starts_at"
                                       class="form-control<?= $isEditingThis && isset($slotErrors['starts_at']) ? ' is-invalid' : '' ?>"
                                       value="<?= esc($editStartsAt) ?>"
                                       required>
                                <?php if ($isEditingThis && isset($slotErrors['starts_at'])): ?>
                                <span class="form-error"><?= esc($slotErrors['starts_at']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="slot-ends-<?= $slotId ?>" class="form-label">Fin</label>
                                <input type="datetime-local"
                                       id="slot-ends-<?= $slotId ?>"
                                       name="ends_at"
                                       class="form-control<?= $isEditingThis && isset($slotErrors['ends_at']) ? ' is-invalid' : '' ?>"
                                       value="<?= esc($editEndsAt) ?>"
                                       required>
                                <?php if ($isEditingThis && isset($slotErrors['ends_at'])): ?>
                                <span class="form-error"><?= esc($slotErrors['ends_at']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="slot-capacity-<?= $slotId ?>" class="form-label">Capacité</label>
                                <input type="number"
                                       id="slot-capacity-<?= $slotId ?>"
                                       name="capacity"
                                       class="form-control<?= $isEditingThis && isset($slotErrors['capacity']) ? ' is-invalid' : '' ?>"
                                       value="<?= esc($editCapacity) ?>"
                                       min="1"
                                       required>
                                <?php if ($isEditingThis && isset($slotErrors['capacity'])): ?>
                                <span class="form-error"><?= esc($slotErrors['capacity']) ?></span>
                                <?php endif; ?>
                                <?php if ($isEditingThis && isset($slotErrors['general'])): ?>
                                <span class="form-error"><?= esc($slotErrors['general']) ?></span>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn--primary btn--sm">Modifier</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="slots-list--empty">Aucun créneau pour ce stand.</p>
                <?php endif; ?>

                <!-- Formulaire d'ajout de créneau -->
                <?php $isAddingHere = ($slotForm === 'add' && $slotFormStandId === $sid); ?>
                <form method="post"
                      action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$sid}/slots") ?>"
                      class="slot-add-form">
                    <?= csrf_field() ?>
                    <h4 class="slot-add-form__title">Ajouter un créneau</h4>
                    <div class="form-group">
                        <label for="slot-add-starts-<?= $sid ?>" class="form-label">Début</label>
                        <input type="datetime-local"
                               id="slot-add-starts-<?= $sid ?>"
                               name="starts_at"
                               class="form-control<?= $isAddingHere && isset($slotErrors['starts_at']) ? ' is-invalid' : '' ?>"
                               value="<?= $isAddingHere ? esc(old('starts_at', '')) : '' ?>"
                               required>
                        <?php if ($isAddingHere && isset($slotErrors['starts_at'])): ?>
                        <span class="form-error"><?= esc($slotErrors['starts_at']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="slot-add-ends-<?= $sid ?>" class="form-label">Fin</label>
                        <input type="datetime-local"
                               id="slot-add-ends-<?= $sid ?>"
                               name="ends_at"
                               class="form-control<?= $isAddingHere && isset($slotErrors['ends_at']) ? ' is-invalid' : '' ?>"
                               value="<?= $isAddingHere ? esc(old('ends_at', '')) : '' ?>"
                               required>
                        <?php if ($isAddingHere && isset($slotErrors['ends_at'])): ?>
                        <span class="form-error"><?= esc($slotErrors['ends_at']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="slot-add-capacity-<?= $sid ?>" class="form-label">Capacité</label>
                        <input type="number"
                               id="slot-add-capacity-<?= $sid ?>"
                               name="capacity"
                               class="form-control<?= $isAddingHere && isset($slotErrors['capacity']) ? ' is-invalid' : '' ?>"
                               value="<?= $isAddingHere ? esc(old('capacity', '')) : '' ?>"
                               min="1"
                               required>
                        <?php if ($isAddingHere && isset($slotErrors['capacity'])): ?>
                        <span class="form-error"><?= esc($slotErrors['capacity']) ?></span>
                        <?php endif; ?>
                        <?php if ($isAddingHere && isset($slotErrors['general'])): ?>
                        <span class="form-error"><?= esc($slotErrors['general']) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm">Ajouter le créneau</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="stands-list--empty">Aucun stand pour le moment.</p>
        <?php endif; ?>

        <!-- Formulaire d'ajout d'un stand -->
        <form method="post"
              action="<?= site_url("kermesse/{$kermesse['id']}/stands") ?>"
              class="stand-add-form">
            <?= csrf_field() ?>
            <h3 class="stand-add-form__title">Ajouter un stand</h3>
            <div class="form-group">
                <label for="stand-name" class="form-label">Nom du stand</label>
                <input type="text"
                       id="stand-name"
                       name="name"
                       class="form-control<?= ($standForm === 'add' && $standError !== null) ? ' is-invalid' : '' ?>"
                       value="<?= esc($standForm === 'add' ? $standName : '') ?>"
                       placeholder="Ex. : Stand Buvette"
                       required
                       <?php if ($standForm === 'add' && $standError !== null): ?>
                       aria-describedby="stand-add-error"
                       <?php endif; ?>>

                <?php if ($standForm === 'add' && $standError !== null): ?>
                <span id="stand-add-error" class="form-error"><?= esc($standError) ?></span>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn--primary">Ajouter le stand</button>
        </form>
    </section>

    <div class="kermesse-dashboard__actions">
        <a href="<?= site_url('/') ?>" class="btn btn--secondary">Retour à mes kermesses</a>
    </div>
</div>

<?= $this->endSection() ?>
