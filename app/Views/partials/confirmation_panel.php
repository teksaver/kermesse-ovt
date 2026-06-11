<?php
/**
 * Confirmation panel — rendered after a destructive or important action.
 *
 * Expected variables:
 *   $message (string)  — main confirmation message
 *   $action  (string)  — URL for the primary action button
 *   $label   (string)  — button label
 */
?>
<div class="confirmation-panel" role="region" aria-label="Confirmation">
    <p class="confirmation-panel__message"><?= esc($message ?? '') ?></p>
    <?php if (!empty($action)): ?>
    <a href="<?= esc($action) ?>" class="btn btn--primary"><?= esc($label ?? 'Confirmer') ?></a>
    <?php endif; ?>
</div>
