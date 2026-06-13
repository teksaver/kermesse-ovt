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
$isSignedUp = ! empty($slot['isSignedUp']);
$isOverlapping = ! empty($slot['isOverlapping']);
$signupHref = $slot['signupHref'] ?? null;
$standName  = $standName ?? '';

// Determine disabled state
$isDisabled = $isFull || $isSignedUp || $isOverlapping;
?>
<?php if (! $isDisabled && $signupHref): ?>
<a href="<?= esc($signupHref) ?>" class="slot-row slot-row--public slot-row--available slot-action" style="display: flex; justify-content: space-between; align-items: center; text-decoration: none; color: inherit;">
    <div class="slot-row__info">
        <span class="slot-row__time"><?= esc($slot['displayTime']) ?></span>
        <span class="slot-row__capacity">
            <?= esc($slot['remainingSpots']) ?> place<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?> restante<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?> sur <?= esc($slot['capacity']) ?>
        </span>
    </div>
    <div class="slot-row__action slot-desktop-only">
        <span class="btn btn--primary btn--sm" style="pointer-events: none;">M'inscrire</span>
    </div>
</a>
<?php else: ?>
<?php 
if ($isSignedUp) {
    $disabledReason = "Vous êtes déjà inscrit sur ce créneau";
    $statusLabel = "Inscrit(e) ✅";
    $statusClass = "slot-row__status--signed-up";
    $divClass    = "slot-row--signed-up";
} elseif ($isOverlapping) {
    $disabledReason = "Créneau incompatible avec vos inscriptions";
    $statusLabel = "Incompatible ⚠️";
    $statusClass = "slot-row__status--overlapping";
    $divClass    = "slot-row--overlapping";
} else {
    $disabledReason = "Plus de place pour ce créneau";
    $statusLabel = "Complet 🔒";
    $statusClass = "slot-row__status--full";
    $divClass    = "slot-row--full";
}
?>
<div class="slot-row slot-row--public slot-row--disabled <?= $divClass ?>" aria-disabled="true" tabindex="0" onclick="this.classList.toggle('show-tooltip')" style="display: flex; justify-content: space-between; align-items: center; opacity: 0.65; cursor: not-allowed; position: relative;">
    <div class="slot-row__info">
        <span class="slot-row__time" style="text-decoration: <?= $isFull && !$isSignedUp ? 'line-through' : 'none' ?>;"><?= esc($slot['displayTime']) ?></span>
        <span class="slot-row__status <?= $statusClass ?> slot-mobile-only" style="font-weight: bold; display: inline-block; margin-left: 8px;"><?= esc($statusLabel) ?></span>
        <span class="slot-row__capacity">
            <?= esc($slot['remainingSpots']) ?> place<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?> restante<?= (int) $slot['remainingSpots'] > 1 ? 's' : '' ?> sur <?= esc($slot['capacity']) ?>
        </span>
    </div>
    <div class="slot-row__explanation slot-desktop-only" style="text-align: right;">
        <div class="slot-row__status <?= $statusClass ?>" style="font-weight: bold; margin-bottom: 2px;"><?= esc($statusLabel) ?></div>
        <div style="font-size: 0.85em; color: #555;"><?= esc($disabledReason) ?></div>
    </div>
    <div class="slot-tooltip slot-mobile-only" style="display: none; position: absolute; bottom: -30px; left: 50%; transform: translateX(-50%); background: #333; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 0.8em; white-space: nowrap; z-index: 10;">
        <?= esc($disabledReason) ?>
    </div>
</div>
<?php endif; ?>
