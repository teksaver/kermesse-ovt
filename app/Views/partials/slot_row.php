<?php
/**
 * Public slot row partial.
 *
 * Expects:
 *   $slot = ['displayTime' => string, 'capacity' => int, 'remainingSpots' => int, 'isFull' => bool]
 *
 * PRIVACY: renders availability only — no volunteer data, no token, no management link.
 * A full slot stays visible but disabled with a "Complet" label; an available slot is
 * rendered as a tappable row (44px tap target) ready for the signup flow (Story 3.2).
 */
$isFull = ! empty($slot['isFull']);
?>
<div class="slot-row slot-row--public <?= $isFull ? 'slot-row--full' : 'slot-row--available' ?>"
    <?= $isFull ? 'aria-disabled="true"' : 'role="button" tabindex="0"' ?>>
    <div class="slot-row__info">
        <span class="slot-row__time"><?= esc($slot['displayTime']) ?></span>
        <?php if ($isFull): ?>
        <span class="slot-row__status slot-row__status--full">Complet</span>
        <span class="slot-row__capacity"><?= esc($slot['capacity']) ?> places, 0 restante</span>
        <?php else: ?>
        <span class="slot-row__capacity">
            <?= esc($slot['remainingSpots']) ?> place<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?> restante<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?>
            sur <?= esc($slot['capacity']) ?>
        </span>
        <?php endif; ?>
    </div>
</div>
