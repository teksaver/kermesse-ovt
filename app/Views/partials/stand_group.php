<?php
/**
 * Public stand group partial.
 *
 * Expects:
 *   $stand = ['name' => string, 'slots' => array<int, array>]
 *
 * Stands are the first level of organisation on the public page. Each slot is
 * rendered through the slot_row partial. No volunteer data is ever passed here.
 */
?>
<div class="stand-group">
    <div class="stand-group__header">
        <span class="stand-group__name"><?= esc($stand['name']) ?></span>
    </div>

    <?php if (! empty($stand['slots'])): ?>
    <div class="slot-list" aria-label="Créneaux de <?= esc($stand['name']) ?>">
        <?php foreach ($stand['slots'] as $slot): ?>
        <?= view('partials/slot_row', ['slot' => $slot]) ?>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="slot-empty-state" aria-label="Créneaux de <?= esc($stand['name']) ?>">
        <p class="slot-empty-state__text">Aucun créneau disponible pour le moment</p>
    </div>
    <?php endif; ?>
</div>
