<?php
/**
 * Shared tab bar for a single tender record. Included by tender_view.php,
 * tender_edit.php, tender_boq.php and later Phase B/C/D/F pages so the record
 * always shows the same set of tabs no matter which one you're on.
 *
 * Expects $id (int, tender_id) and $tenderNavActive (string) to be set by the
 * including page before requiring this file.
 */
$tenderNavActive = $tenderNavActive ?? '';
$tenderNavTabs = [
    'view' => ['label' => 'Details',            'icon' => 'bi-file-earmark-text', 'route' => 'tender_view'],
    'edit' => ['label' => 'Edit',                'icon' => 'bi-pencil',            'route' => 'tender_edit'],
    'boq'  => ['label' => 'Bills of Quantities', 'icon' => 'bi-receipt-cutoff',    'route' => 'tender_boq'],
    'materials' => ['label' => 'Materials Schedule', 'icon' => 'bi-boxes',         'route' => 'tender_materials'],
    'checklist' => ['label' => 'Checklist',          'icon' => 'bi-check2-square', 'route' => 'tender_checklist'],
];
?>
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <?php foreach ($tenderNavTabs as $key => $tab): ?>
        <a href="<?= getUrl($tab['route']) ?>?id=<?= (int)$id ?>"
           class="btn btn-sm <?= $tenderNavActive === $key ? 'btn-primary' : 'btn-outline-primary' ?>">
            <i class="bi <?= $tab['icon'] ?> me-1"></i><?= $tab['label'] ?>
        </a>
    <?php endforeach; ?>
</div>
