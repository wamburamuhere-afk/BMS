<?php
/**
 * includes/tables/grn_table.php
 * Reusable Goods Received Notes table — markup + wiring for assets/js/tables/bms-grn-table.js.
 *
 * ONE implementation, included by every page that needs a GRN list:
 *   app/bms/grn/grn.php                    → full list (all columns, page filter bar)
 *   app/bms/Suppliers/supplier_details.php → GRN tab, locked to one supplier
 *
 * The host sets $tbl immediately before including this file:
 *
 *   $tbl = [
 *       'id'          => 'grnTable',     // DOM id — must be unique on the page
 *       'supplier_id' => 0,              // > 0 locks the table to that supplier
 *       'hide'        => ['supplier'],   // column keys to drop (th + column together)
 *       'card'        => '#card-view-container',  // mobile card container, or null
 *       'filters_js'  => 'function () { return { status: $("select[name=status]").val() }; }',
 *       'on_stats'    => 'updateStats',  // JS fn name receiving the stats object, or null
 *       'on_count'    => null,           // JS fn name receiving the filtered row count
 *       'page_length' => 10,
 *       'dom'         => 'rtip',
 *   ];
 *   include ROOT_DIR . '/includes/tables/grn_table.php';
 *
 * Permissions are resolved HERE, not by the host, so every copy of the table
 * enforces the same gates.
 */

$tbl = array_merge([
    'id'          => 'grnTable',
    'supplier_id' => 0,
    'hide'        => [],
    'card'        => null,
    'filters_js'  => 'function () { return {}; }',
    'on_stats'    => null,
    'on_count'    => null,
    'page_length' => 10,
    'dom'         => 'rtip',
    'defer_pane'  => null,   // e.g. '#pane-grn' — hold init until that tab opens
], $tbl ?? []);

// Projects column only exists when the projects module is switched on.
if (!getSetting('enable_projects', 0)) {
    $tbl['hide'][] = 'project';
}
$grn_tbl_hide = array_values(array_unique($tbl['hide']));

// Emit each shared asset once per request, however many tables the page hosts.
if (empty($GLOBALS['__bms_table_assets']['utils'])) {
    $GLOBALS['__bms_table_assets']['utils'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-table-utils.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') . '"></script>';
}
if (empty($GLOBALS['__bms_table_assets']['grn'])) {
    $GLOBALS['__bms_table_assets']['grn'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-grn-table.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-grn-table.js') . '"></script>';
}

// Column catalogue — keys MUST stay in the same order as columns() in
// assets/js/tables/bms-grn-table.js, or headers drift from data.
$grn_tbl_columns = [
    'sno'            => ['label' => 'S/NO',        'attrs' => 'class="text-center" style="width:50px;"'],
    'receipt_number' => ['label' => 'GRN #',       'attrs' => 'class="text-center"'],
    'receipt_date'   => ['label' => 'Date',        'attrs' => 'class="text-center"'],
    'supplier'       => ['label' => 'Supplier',    'attrs' => 'class="text-center"'],
    'order_number'   => ['label' => 'PO #',        'attrs' => 'class="text-center"'],
    'project'        => ['label' => 'Project',     'attrs' => 'class="text-center"'],
    'warehouse'      => ['label' => 'Warehouse',   'attrs' => 'class="text-center"'],
    'total_items'    => ['label' => 'Items',       'attrs' => 'class="text-center"'],
    'total_value'    => ['label' => 'Total Value', 'attrs' => 'class="text-center"'],
    'received_by'    => ['label' => 'Received By', 'attrs' => 'class="text-center"'],
    'status'         => ['label' => 'Status',      'attrs' => 'class="text-center"'],
    'actions'        => ['label' => 'Actions',     'attrs' => 'class="text-center d-print-none"'],
];
?>
<div class="table-responsive">
    <table class="table table-striped table-hover align-middle" id="<?= htmlspecialchars($tbl['id']) ?>" style="width:100%">
        <thead class="bg-light">
            <tr>
                <?php foreach ($grn_tbl_columns as $key => $col): ?>
                    <?php if (in_array($key, $grn_tbl_hide, true)) continue; ?>
                    <th <?= $col['attrs'] ?>><?= $col['label'] ?></th>
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
        apiUrl:  <?= json_encode(buildUrl('api/get_grns.php')) ?>,
        urls: {
            view:         <?= json_encode(getUrl('grn_view')) ?>,
            edit:         <?= json_encode(getUrl('grn_edit')) ?>,
            print:        <?= json_encode(getUrl('grn_print')) ?>,
            po:           <?= json_encode(getUrl('purchase_order_details')) ?>,
            review:       <?= json_encode(buildUrl('api/review_grn.php')) ?>,
            approve:      <?= json_encode(buildUrl('api/approve_grn.php')) ?>,
            updateStatus: <?= json_encode(buildUrl('api/update_grn_status.php')) ?>,
            del:          <?= json_encode(buildUrl('api/delete_grn.php')) ?>
        },
        perms: {
            canReview:  <?= json_encode((bool) canReview('grn')) ?>,
            canApprove: <?= json_encode((bool) canApprove('grn')) ?>,
            canDelete:  <?= json_encode((bool) (isAdmin() || canDelete('grn'))) ?>,
            isAdmin:    <?= json_encode((bool) isAdmin()) ?>
        },
        hide:  <?= json_encode($grn_tbl_hide) ?>,
        fixed: <?= json_encode($tbl['supplier_id'] > 0 ? ['supplier' => (int) $tbl['supplier_id']] : (object) []) ?>,
        cardContainer: <?= json_encode($tbl['card']) ?>,
        filters: <?= $tbl['filters_js'] ?>,
        onStats: <?= $tbl['on_stats'] ? $tbl['on_stats'] : 'null' ?>,
        onCount: <?= $tbl['on_count'] ? $tbl['on_count'] : 'null' ?>,
        pageLength: <?= (int) $tbl['page_length'] ?>,
        dom: <?= json_encode($tbl['dom']) ?>
    };

<?php if ($tbl['defer_pane']): ?>
    // Inside a tab — don't query the server or measure columns until it is opened.
    BMSTbl.defer(<?= json_encode($tbl['defer_pane']) ?>, function () { return BMSGrnTable.init(cfg); });
<?php else: ?>
    BMSGrnTable.init(cfg);
<?php endif; ?>
});
</script>
