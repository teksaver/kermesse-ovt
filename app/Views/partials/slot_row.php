<?php
/**
 * Public slot row partial.
 *
 * Expects:
 *   $slot = [
 *     'slotId'         => int,
 *     'signupHref'     => string|null,   // null when full
 *     'displayTime'    => string,
 *     'capacity'       => int,
 *     'remainingSpots' => int,
 *     'isFull'         => bool,
 *   ]
 *
 * PRIVACY: renders availability only — no volunteer data, no token, no management link.
 * A full slot stays visible but disabled with a "Complet" label; an available slot is
 * rendered as a tappable <a> link leading to the signup form (Story 3.2).
 */
$isFull     = ! empty($slot['isFull']);
$signupHref = $slot['signupHref'] ?? null;
?>
<?php if (! $isFull && $signupHref): ?>
<a href="<?= esc($signupHref) ?>"
    class="slot-row slot-row--public slot-row--available"
    aria-label="S'inscrire : <?= esc($slot['displayTime']) ?>">
    <div class="slot-row__info">
        <span class="slot-row__time"><?= esc($slot['displayTime']) ?></span>
        <span class="slot-row__capacity">
            <?= esc($slot['remainingSpots']) ?> place<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?> restante<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?>
            sur <?= esc($slot['capacity']) ?>
        </span>
    </div>
</a>
<?php else: ?>
<div class="slot-row slot-row--public slot-row--full" aria-disabled="true">
    <div class="slot-row__info">
        <span class="slot-row__time"><?= esc($slot['displayTime']) ?></span>
        <span class="slot-row__status slot-row__status--full">Complet</span>
        <span class="slot-row__capacity"><?= esc($slot['capacity']) ?> places, 0 restante</span>
    </div>
</div>
<?php endif; ?>
