<?php
/**
 * includes/tables/delivery_notes_table.php
 * Reusable Delivery Notes table — markup + wiring for
 * assets/js/tables/bms-delivery-notes-table.js.
 *
 * Hosts:
 *   app/bms/grn/delivery_notes.php         → full list, inbound/outbound tabs
 *   app/bms/Suppliers/supplier_details.php → Delivery Notes tab, one supplier
 *
 * Config contract matches includes/tables/grn_table.php — see that file's header.
 * Extra keys here:
 *   'party_label'   heading for the counterparty column
 *   'on_type_counts' JS fn receiving {inbound, outbound}
 */

$tbl = array_merge([
    'id'             => 'dnTable',
    'supplier_id'    => 0,
    'hide'           => [],
    'card'           => null,
    'filters_js'     => 'function () { return {}; }',
    'on_stats'       => null,
    'on_count'       => null,
    'on_type_counts' => null,
    'party_label'    => 'Supplier / Sub-Contractor',
    'page_length'    => 25,
    'dom'            => 'rtp',
    'defer_pane'     => null,
], $tbl ?? []);

if (!getSetting('enable_projects', 0)) {
    $tbl['hide'][] = 'project';
}
$dn_tbl_hide = array_values(array_unique($tbl['hide']));

// Emit each shared asset once per request, however many tables the page hosts.
if (empty($GLOBALS['__bms_table_assets']['utils'])) {
    $GLOBALS['__bms_table_assets']['utils'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-table-utils.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') . '"></script>';
}
if (empty($GLOBALS['__bms_table_assets']['delivery_notes'])) {
    $GLOBALS['__bms_table_assets']['delivery_notes'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-delivery-notes-table.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-delivery-notes-table.js') . '"></script>';
}

// Keys MUST stay in the same order as columns() in the JS module.
$dn_tbl_columns = [
    'sno'           => ['label' => 'S/NO',              'attrs' => 'style="width:50px;" class="ps-3"'],
    'dn_number'     => ['label' => 'DN Number',         'attrs' => ''],
    'dn_type'       => ['label' => 'Type',              'attrs' => 'style="width:90px;" class="d-print-none"'],
    'delivery_date' => ['label' => 'Date',              'attrs' => ''],
    'party'         => ['label' => $tbl['party_label'], 'attrs' => 'id="dnPartyColHeading"'],
    'project'       => ['label' => 'Project',           'attrs' => ''],
    'total_items'   => ['label' => 'Items',             'attrs' => ''],
    'warehouse'     => ['label' => 'Warehouse',         'attrs' => ''],
    'status'        => ['label' => 'Status',            'attrs' => ''],
    'actions'       => ['label' => 'Actions',           'attrs' => 'style="width:80px;" class="d-print-none"'],
];
?>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="<?= htmlspecialchars($tbl['id']) ?>" style="width:100%">
        <thead class="bg-light sticky-header">
            <tr>
                <?php foreach ($dn_tbl_columns as $key => $col): ?>
                    <?php if (in_array($key, $dn_tbl_hide, true)) continue; ?>
                    <th <?= $col['attrs'] ?>><?= htmlspecialchars($col['label']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody><!-- loaded via AJAX --></tbody>
    </table>
</div>

<script>
$(function () {
    var cfg = {
        tableId: <?= json_encode($tbl['id']) ?>,
        apiUrl:  <?= json_encode(buildUrl('api/get_delivery_notes_list.php')) ?>,
        urls: {
            view:          <?= json_encode(getUrl('dn_view')) ?>,
            editInbound:   <?= json_encode(getUrl('dn_create')) ?>,
            editOutbound:  <?= json_encode(getUrl('dn_outbound')) ?>,
            grnCreate:     <?= json_encode(getUrl('grn_create')) ?>,
            review:        <?= json_encode(buildUrl('api/review_dn.php')) ?>,
            approve:       <?= json_encode(buildUrl('api/approve_dn.php')) ?>,
            del:           <?= json_encode(buildUrl('api/delete_dn.php')) ?>
        },
        perms: {
            canReview:    <?= json_encode((bool) canReview('dn')) ?>,
            canApprove:   <?= json_encode((bool) canApprove('dn')) ?>,
            canDelete:    <?= json_encode((bool) (isAdmin() || canDelete('grn'))) ?>,
            canCreateGrn: <?= json_encode((bool) (isAdmin() || canCreate('grn'))) ?>,
            isAdmin:      <?= json_encode((bool) isAdmin()) ?>
        },
        hide:  <?= json_encode($dn_tbl_hide) ?>,
        fixed: <?= json_encode($tbl['supplier_id'] > 0 ? ['supplier' => (int) $tbl['supplier_id']] : (object) []) ?>,
        cardContainer: <?= json_encode($tbl['card']) ?>,
        filters: <?= $tbl['filters_js'] ?>,
        onStats: <?= $tbl['on_stats'] ? $tbl['on_stats'] : 'null' ?>,
        onCount: <?= $tbl['on_count'] ? $tbl['on_count'] : 'null' ?>,
        onTypeCounts: <?= $tbl['on_type_counts'] ? $tbl['on_type_counts'] : 'null' ?>,
        pageLength: <?= (int) $tbl['page_length'] ?>,
        dom: <?= json_encode($tbl['dom']) ?>
    };

<?php if ($tbl['defer_pane']): ?>
    // Inside a tab — don't query the server or measure columns until it is opened.
    BMSTbl.defer(<?= json_encode($tbl['defer_pane']) ?>, function () { return BMSDnTable.init(cfg); });
<?php else: ?>
    BMSDnTable.init(cfg);
<?php endif; ?>
});
</script>
