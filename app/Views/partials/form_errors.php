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
<?php endif; ?>
<?php if (count($errors) > (isset($errors['_service']) ? 1 : 0)): ?>
<ul class="form-error-list" role="alert">
    <?php foreach ($errors as $field => $msg): ?>
        <?php if ($field !== '_service'): ?>
        <li class="form-error-list__item"><?= esc($msg) ?></li>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
