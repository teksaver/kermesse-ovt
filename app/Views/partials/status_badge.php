<?php
/**
 * Status badge for kermesse lifecycle, slot, or signup state.
 *
 * Expected variables:
 *   $status (string)  — 'preparation' | 'open' | 'closed' | 'active' | 'full'
 */
$labels = [
    'preparation' => 'Préparation',
    'open'        => 'Inscriptions ouvertes',
    'closed'      => 'Inscriptions fermées',
    'active'      => 'Actif',
    'full'        => 'Complet',
];
$label = $labels[$status ?? ''] ?? esc($status ?? 'Inconnu');
?>
<span class="kermesse-status-badge kermesse-status-badge--<?= esc($status ?? 'unknown') ?>"><?= $label ?></span>
