<?php
/**
 * Reusable form field partial.
 *
 * Expected variables:
 *   string $name     - field name
 *   string $label    - visible label text
 *   string $type     - input type (text, email, date, textarea)
 *   bool   $required - whether the field is required
 *   array  $oldInput - previously submitted values
 *   array  $errors   - validation error messages keyed by field name
 *   array  $attrs    - additional HTML attributes keyed by attribute name
 */

$name = (string) ($name ?? '');
if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) {
    throw new InvalidArgumentException('Invalid form field name.');
}

$allowedTypes = ['text', 'email', 'date', 'textarea'];
$type = in_array($type ?? '', $allowedTypes, true) ? $type : 'text';

$fieldId   = 'field-' . $name;
$errorId   = 'error-' . $name;
$hasError  = isset($errors[$name]);
$value     = (string) ($oldInput[$name] ?? '');
$reqAttr   = $required ? 'required' : '';
$attrs     = is_array($attrs ?? null) ? $attrs : [];

$renderAttrs = static function (array $attrs): string {
    $html = [];
	    $reserved = [
	        'id',
	        'name',
	        'required',
        'type',
        'value',
    ];

    foreach ($attrs as $attrName => $attrValue) {
        if (! is_string($attrName) || ! preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.\\-]*$/', $attrName)) {
            continue;
        }

        $lowerAttrName = strtolower($attrName);
        if (in_array($lowerAttrName, $reserved, true) || str_starts_with($lowerAttrName, 'on')) {
            continue;
        }

        if ($attrValue === true) {
            $html[] = esc($attrName, 'attr');
            continue;
        }

        if ($attrValue === false || $attrValue === null || is_array($attrValue) || is_object($attrValue)) {
            continue;
        }

        $html[] = esc($attrName, 'attr') . '="' . esc((string) $attrValue, 'attr') . '"';
    }

    return implode(' ', $html);
};

$renderedAriaAttrs = $hasError
    ? 'aria-describedby="' . esc($errorId, 'attr') . '" aria-invalid="true"'
    : '';
$renderedAttrs = $renderAttrs($attrs);
?>
<div class="form-group<?= $hasError ? ' form-group--error' : '' ?>">
    <label for="<?= esc($fieldId, 'attr') ?>"><?= esc($label) ?><?php if ($required): ?> <span aria-hidden="true">*</span><?php endif; ?></label>
    <?php if ($type === 'textarea'): ?>
    <textarea id="<?= esc($fieldId, 'attr') ?>"
              name="<?= esc($name, 'attr') ?>"
              <?= $reqAttr ?> <?= $renderedAriaAttrs ?> <?= $renderedAttrs ?>><?= esc($value) ?></textarea>
    <?php else: ?>
    <input type="<?= esc($type, 'attr') ?>"
           id="<?= esc($fieldId, 'attr') ?>"
           name="<?= esc($name, 'attr') ?>"
           value="<?= esc($value, 'attr') ?>"
           <?= $reqAttr ?> <?= $renderedAriaAttrs ?> <?= $renderedAttrs ?>>
    <?php endif; ?>
    <?php if ($hasError): ?>
    <p class="field-error" id="<?= esc($errorId, 'attr') ?>" role="alert"><?= esc($errors[$name]) ?></p>
    <?php endif; ?>
</div>
