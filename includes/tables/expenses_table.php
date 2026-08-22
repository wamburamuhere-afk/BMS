<?php
/**
 * includes/tables/expenses_table.php
 * Reusable Expenses table — markup + wiring for
 * assets/js/tables/bms-expenses-table.js.
 *
 * Hosts:
 *   app/constant/accounts/expenses.php     → full list, category tree, filters,
 *                                            stat cards, modals, exports
 *   app/bms/Suppliers/supplier_details.php → Expenses tab, filtered to what was
 *                                            paid to that supplier
 *
 * Config contract matches includes/tables/grn_table.php — see that file's header.
 * Extra keys:
 *   'paid_to_type' / 'paid_to_id'  lock the list to one payee
 *   'buttons_js'                   DataTables Buttons config (export/print chrome)
 */

$tbl = array_merge([
    'id'           => 'expensesTable',
    'paid_to_type' => '',
    'paid_to_id'   => 0,
    'hide'         => [],
    'card'         => null,
    'filters_js'   => 'function () { return {}; }',
    'on_stats'     => null,
    'on_count'     => null,
    'buttons_js'   => null,
    'page_length'  => 25,
    'dom'          => 'rtip',
    'defer_pane'   => null,
], $tbl ?? []);

if (getSetting('enable_projects', 0) != '1') {
    $tbl['hide'][] = 'project';
}
$exp_tbl_hide = array_values(array_unique($tbl['hide']));

// Emit each shared asset once per request, however many tables the page hosts.
if (empty($GLOBALS['__bms_table_assets']['utils'])) {
    $GLOBALS['__bms_table_assets']['utils'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-table-utils.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') . '"></script>';
}
if (empty($GLOBALS['__bms_table_assets']['expenses'])) {
    $GLOBALS['__bms_table_assets']['expenses'] = true;
    echo '<script src="' . getUrl('assets/js/tables/bms-expenses-table.js') . '?v='
       . @filemtime(ROOT_DIR . '/assets/js/tables/bms-expenses-table.js') . '"></script>';
}

// Company details for the payment voucher the Print Voucher action builds.
$exp_c_logo = getSetting('company_logo', '');
$exp_c_name = getSetting('company_name', 'BMS');
$exp_proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$exp_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$exp_logo_url  = !empty($exp_c_logo) ? $exp_proto . '://' . $exp_host . '/' . ltrim($exp_c_logo, '/') : '';
$exp_logo_html = !empty($exp_logo_url)
    ? '<img src="' . htmlspecialchars($exp_logo_url) . '" alt="' . htmlspecialchars($exp_c_name) . '" style="max-height:70px; width:auto; display:block; margin-bottom:4px;">'
    : '';

// Keys MUST stay in the same order as columns() in the JS module.
$exp_tbl_columns = [
    'sno'          => ['label' => 'S/NO',        'attrs' => 'style="width:70px;"'],
    'expense_date' => ['label' => 'Date',        'attrs' => ''],
    'description'  => ['label' => 'Description', 'attrs' => ''],
    'categories'   => ['label' => 'Category',    'attrs' => ''],
    'project'      => ['label' => 'Project',     'attrs' => ''],
    'amount'       => ['label' => 'Amount',      'attrs' => ''],
    'paid_to'      => ['label' => 'Paid To',     'attrs' => ''],
    'status'       => ['label' => 'Status',      'attrs' => ''],
    'actions'      => ['label' => 'Actions',     'attrs' => 'class="text-end d-print-none"'],
];
?>
<div class="table-responsive">
    <table id="<?= htmlspecialchars($tbl['id']) ?>" class="table table-hover align-middle" style="width:100%">
        <thead class="bg-light text-muted small uppercase">
            <tr>
                <?php foreach ($exp_tbl_columns as $key => $col): ?>
                    <?php if (in_array($key, $exp_tbl_hide, true)) continue; ?>
                    <th <?= $col['attrs'] ?>><?= $col['label'] ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody class="small"><!-- loaded via AJAX --></tbody>
    </table>
</div>

<script>
$(function () {
    var cfg = {
        tableId: <?= json_encode($tbl['id']) ?>,
        apiUrl:  <?= json_encode(buildUrl('api/get_expenses.php')) ?>,
        urls: {
            view:   <?= json_encode(getUrl('expenses/details')) ?>,
            list:   <?= json_encode(getUrl('expenses')) ?>,
            get:    <?= json_encode(buildUrl('api/get_expense.php')) ?>,
            status: <?= json_encode(buildUrl('api/update_expense_status.php')) ?>,
            del:    <?= json_encode(buildUrl('api/delete_expense.php')) ?>
        },
        perms: {
            canEdit:   <?= json_encode((bool) canEdit('expenses')) ?>,
            canDelete: <?= json_encode((bool) canDelete('expenses')) ?>
        },
        voucher: {
            printedBy:   <?= json_encode(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?>,
            printedRole: <?= json_encode($_SESSION['user_role'] ?? 'User') ?>,
            logoHtml:    <?= json_encode($exp_logo_html) ?>,
            companyName: <?= json_encode($exp_c_name) ?>
        },
        hide:  <?= json_encode($exp_tbl_hide) ?>,
        fixed: <?= json_encode(($tbl['paid_to_type'] !== '' && $tbl['paid_to_id'] > 0)
                    ? ['paid_to_type' => $tbl['paid_to_type'], 'paid_to_id' => (int) $tbl['paid_to_id']]
                    : (object) []) ?>,
        cardContainer: <?= json_encode($tbl['card']) ?>,
        filters: <?= $tbl['filters_js'] ?>,
        onStats: <?= $tbl['on_stats'] ? $tbl['on_stats'] : 'null' ?>,
        onCount: <?= $tbl['on_count'] ? $tbl['on_count'] : 'null' ?>,
        buttons: <?= $tbl['buttons_js'] ? $tbl['buttons_js'] : 'null' ?>,
        pageLength: <?= (int) $tbl['page_length'] ?>,
        dom: <?= json_encode($tbl['dom']) ?>
    };

<?php if ($tbl['defer_pane']): ?>
    // Inside a tab — don't query the server or measure columns until it is opened.
    BMSTbl.defer(<?= json_encode($tbl['defer_pane']) ?>, function () { return BMSExpensesTable.init(cfg); });
<?php else: ?>
    BMSExpensesTable.init(cfg);
<?php endif; ?>
});
</script>
