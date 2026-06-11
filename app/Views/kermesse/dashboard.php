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
        ?>

        <!-- Liste des stands actifs -->
        <?php if (! empty($stands)): ?>
        <ul class="stands-list">
            <?php foreach ($stands as $stand): ?>
            <li class="stands-list__item">
                <span class="stands-list__name"><?= esc($stand['name']) ?></span>

                <!-- Formulaire de renommage -->
                <form method="post"
                      action="<?= site_url("kermesse/{$kermesse['id']}/stands/{$stand['id']}") ?>"
                      class="stands-list__rename-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="rename-stand-<?= (int) $stand['id'] ?>" class="sr-only">
                            Nouveau nom
                        </label>
                        <input type="text"
                               id="rename-stand-<?= (int) $stand['id'] ?>"
                               name="name"
                               class="form-control<?= ($standForm === 'edit' && $editingStandId === (int) $stand['id']) ? ' is-invalid' : '' ?>"
                               value="<?= esc(($standForm === 'edit' && $editingStandId === (int) $stand['id']) ? $standName : $stand['name']) ?>"
                               placeholder="Nom du stand"
                               required
                               aria-describedby="<?= ($standForm === 'edit' && $editingStandId === (int) $stand['id']) ? "stand-error-{$stand['id']}" : '' ?>">

                        <?php if ($standForm === 'edit' && $editingStandId === (int) $stand['id'] && $standError !== null): ?>
                        <span id="stand-error-<?= (int) $stand['id'] ?>" class="form-error">
                            <?= esc($standError) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm">Renommer</button>
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
