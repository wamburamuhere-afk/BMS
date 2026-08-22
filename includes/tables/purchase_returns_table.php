<?php
/**
 * includes/tables/purchase_returns_table.php
 * Reusable Purchase Returns table — markup + wiring for
 * assets/js/tables/bms-purchase-returns-table.js.
 *
 * Hosts:
 *   app/bms/purchase/purchase_returns.php  → full list, filter bar, add/edit modals
 *   app/bms/Suppliers/supplier_details.php → Purchase Returns tab, one supplier
 *
 * Config contract matches includes/tables/grn_table.php — see that file's header.
 * The row action dropdown is rendered by api/get_purchase_returns.php, which
 * applies the permission gates for every workflow step.
 */

$tbl = array_merge([
    'id'          => 'returnsTable',
    'supplier_id' => 0,
    'hide'        => [],
    'card'        => null,
    'filters_js'  => 'function () { return {}; }',
    'on_count'    => null,
    'on_reload'   => null,   // JS fn run after a successful action (e.g. reload stat cards)
    'page_length' => 25,
    'dom'         => 'rtip',
    'defer_pane'  => null,
], $tbl ?? []);

$pr_tbl_hide = array_values(array_unique($tbl['hide']));

// Emit each shared asset once per request, however many tables the page hosts.
if (empty($GLOBALS['__bms_table_assets']['utils'])) {
    $GLOBALS['__bms_table_assets']['utils'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-table-utils.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') . '"></script>';
}
if (empty($GLOBALS['__bms_table_assets']['purchase_returns'])) {
    $GLOBALS['__bms_table_assets']['purchase_returns'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-purchase-returns-table.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-purchase-returns-table.js') . '"></script>';
}

// Keys MUST stay in the same order as columns() in the JS module.
$pr_tbl_columns = [
    'sno'            => ['label' => 'S/NO',        'attrs' => 'style="width:50px;"'],
    'return_number'  => ['label' => 'Return #',    'attrs' => ''],
    'return_date'    => ['label' => 'Date',        'attrs' => ''],
    'supplier'       => ['label' => 'Supplier',    'attrs' => ''],
    'receipt_number' => ['label' => 'GRN Number',  'attrs' => ''],
    'total_items'    => ['label' => 'Items',       'attrs' => ''],
    'total_amount'   => ['label' => 'Total Value', 'attrs' => ''],
    'reason'         => ['label' => 'Reason',      'attrs' => ''],
    'status'         => ['label' => 'Status',      'attrs' => ''],
    'actions'        => ['label' => 'Actions',     'attrs' => 'class="d-print-none"'],
];
?>
<div class="table-responsive">
    <table id="<?= htmlspecialchars($tbl['id']) ?>" class="table table-striped table-hover w-100">
        <thead>
            <tr>
                <?php foreach ($pr_tbl_columns as $key => $col): ?>
                    <?php if (in_array($key, $pr_tbl_hide, true)) continue; ?>
                    <th <?= $col['attrs'] ?>><?= $col['label'] ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
$(function () {
    var cfg = {
        tableId: <?= json_encode($tbl['id']) ?>,
        apiUrl:  <?= json_encode(buildUrl('api/get_purchase_returns.php')) ?>,
        urls: {
            view:    <?= json_encode(getUrl('purchase_return_view')) ?>,
            list:    <?= json_encode(getUrl('purchase_returns')) ?>,
            review:  <?= json_encode(buildUrl('api/account/review_purchase_return.php')) ?>,
            approve: <?= json_encode(buildUrl('api/account/approve_purchase_return.php')) ?>,
            status:  <?= json_encode(buildUrl('api/update_purchase_return_status.php')) ?>,
            del:     <?= json_encode(buildUrl('api/delete_purchase_return.php')) ?>
        },
        hide:  <?= json_encode($pr_tbl_hide) ?>,
        fixed: <?= json_encode($tbl['supplier_id'] > 0 ? ['supplier_id' => (int) $tbl['supplier_id']] : (object) []) ?>,
        cardContainer: <?= json_encode($tbl['card']) ?>,
        filters: <?= $tbl['filters_js'] ?>,
        onCount: <?= $tbl['on_count'] ? $tbl['on_count'] : 'null' ?>,
        onReload: <?= $tbl['on_reload'] ? $tbl['on_reload'] : 'null' ?>,
        pageLength: <?= (int) $tbl['page_length'] ?>,
        dom: <?= json_encode($tbl['dom']) ?>
    };

<?php if ($tbl['defer_pane']): ?>
    // Inside a tab — don't query the server or measure columns until it is opened.
    BMSTbl.defer(<?= json_encode($tbl['defer_pane']) ?>, function () { return BMSReturnsTable.init(cfg); });
<?php else: ?>
    BMSReturnsTable.init(cfg);
<?php endif; ?>
});
</script>
