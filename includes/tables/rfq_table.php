<?php
/**
 * includes/tables/rfq_table.php
 * Reusable RFQ table — markup + wiring for assets/js/tables/bms-rfq-table.js.
 *
 * Hosts:
 *   app/bms/purchase/rfq.php               → full list, filter bar, stat cards
 *   app/bms/Suppliers/supplier_details.php → RFQs tab, one supplier
 *
 * Config contract matches includes/tables/grn_table.php — see that file's header.
 */

$tbl = array_merge([
    'id'          => 'rfqTable',
    'supplier_id' => 0,
    'hide'        => [],
    'filters_js'  => 'function () { return {}; }',
    'on_stats'    => null,
    'on_count'    => null,
    'page_length' => 10,
    'dom'         => 'rtip',
    'defer_pane'  => null,
], $tbl ?? []);

if (!getSetting('enable_projects', 0)) {
    $tbl['hide'][] = 'project';
}
$rfq_tbl_hide = array_values(array_unique($tbl['hide']));

// Emit each shared asset once per request, however many tables the page hosts.
if (empty($GLOBALS['__bms_table_assets']['utils'])) {
    $GLOBALS['__bms_table_assets']['utils'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-table-utils.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') . '"></script>';
}
if (empty($GLOBALS['__bms_table_assets']['rfq'])) {
    $GLOBALS['__bms_table_assets']['rfq'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-rfq-table.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-rfq-table.js') . '"></script>';
}

// Keys MUST stay in the same order as columns() in the JS module.
$rfq_tbl_columns = [
    'sno'        => ['label' => 'S/No',      'attrs' => 'class="ps-4" style="width:55px;"'],
    'rfq_number' => ['label' => 'RFQ #',     'attrs' => ''],
    'rfq_date'   => ['label' => 'Date',      'attrs' => ''],
    'supplier'   => ['label' => 'Supplier',  'attrs' => ''],
    'project'    => ['label' => 'Project',   'attrs' => ''],
    'warehouse'  => ['label' => 'Warehouse', 'attrs' => ''],
    'status'     => ['label' => 'Status',    'attrs' => ''],
    'actions'    => ['label' => 'Actions',   'attrs' => 'class="text-end pe-4 d-print-none"'],
];
$rfq_tbl_visible = array_diff(array_keys($rfq_tbl_columns), $rfq_tbl_hide);
?>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="<?= htmlspecialchars($tbl['id']) ?>" style="width:100%;">
        <thead class="text-uppercase small fw-bold d-print-table-header" style="background:#f8fafc;">
            <tr>
                <?php foreach ($rfq_tbl_columns as $key => $col): ?>
                    <?php if (in_array($key, $rfq_tbl_hide, true)) continue; ?>
                    <th <?= $col['attrs'] ?>><?= $col['label'] ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody></tbody>
        <tfoot class="d-none d-print-table-footer"><tr><td colspan="<?= count($rfq_tbl_visible) ?>"></td></tr></tfoot>
    </table>
</div>

<script>
$(function () {
    var cfg = {
        tableId: <?= json_encode($tbl['id']) ?>,
        apiUrl:  <?= json_encode(buildUrl('api/get_rfqs.php')) ?>,
        urls: {
            view:     <?= json_encode(getUrl('rfq_view')) ?>,
            edit:     <?= json_encode(getUrl('rfq_create')) ?>,
            poCreate: <?= json_encode(getUrl('purchase_order_create')) ?>,
            approve:  <?= json_encode(buildUrl('api/approve_rfq.php')) ?>,
            del:      <?= json_encode(buildUrl('api/delete_rfq.php')) ?>,
            print: {
                standard:  <?= json_encode(getUrl('print_rfq')) ?>,
                navy:      <?= json_encode(getUrl('print_rfq_navy')) ?>,
                corporate: <?= json_encode(getUrl('print_rfq_corporate')) ?>,
                banded:    <?= json_encode(getUrl('print_rfq_banded')) ?>
            }
        },
        hide:  <?= json_encode($rfq_tbl_hide) ?>,
        fixed: <?= json_encode($tbl['supplier_id'] > 0 ? ['supplier' => (int) $tbl['supplier_id']] : (object) []) ?>,
        filters: <?= $tbl['filters_js'] ?>,
        onStats: <?= $tbl['on_stats'] ? $tbl['on_stats'] : 'null' ?>,
        onCount: <?= $tbl['on_count'] ? $tbl['on_count'] : 'null' ?>,
        pageLength: <?= (int) $tbl['page_length'] ?>,
        dom: <?= json_encode($tbl['dom']) ?>
    };

<?php if ($tbl['defer_pane']): ?>
    // Inside a tab — don't query the server or measure columns until it is opened.
    BMSTbl.defer(<?= json_encode($tbl['defer_pane']) ?>, function () { return BMSRfqTable.init(cfg); });
<?php else: ?>
    BMSRfqTable.init(cfg);
<?php endif; ?>
});
</script>
