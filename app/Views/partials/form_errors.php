<?php
/**
 * Form error summary.
 *
 * Expected variables:
 *   $errors (array<string, string>)  — field name => error message
 *
 * The service error key '_service' is displayed as a standalone banner.
 */
if (empty($errors)) return;
?>
<?php if (!empty($errors['_service'])): ?>
<div class="form-error-banner" role="alert">
    <?= esc($errors['_service']) ?>
</div>
<?php else: ?>
<ul class="form-error-list" role="alert">
    <?php foreach ($errors as $field => $msg): ?>
    <li class="form-error-list__item"><?= esc($msg) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
