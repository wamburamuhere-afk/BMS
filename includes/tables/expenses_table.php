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

// projectsModuleActive() (core/project_scope.php) — NOT the raw
// 'enable_projects' setting alone: that only reflects the tenant's own
// toggle, not the superadmin's platform grant, so a tenant whose Projects
// module was revoked (but who still had the toggle on beforehand) would
// keep seeing this column. projectsModuleActive() is the same AND of both
// checks the Add Expense modal's own Project field already uses.
if (!projectsModuleActive()) {
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
    'sno'          => ['label' => t('S/NO'),        'attrs' => 'style="width:70px;"'],
    'expense_date' => ['label' => t('Date'),        'attrs' => ''],
    'description'  => ['label' => t('Description'), 'attrs' => ''],
    'categories'   => ['label' => t('Category'),    'attrs' => ''],
    'project'      => ['label' => t('Project'),     'attrs' => ''],
    'amount'       => ['label' => t('Amount'),      'attrs' => ''],
    'paid_to'      => ['label' => t('Paid To'),     'attrs' => ''],
    'status'       => ['label' => t('Status'),      'attrs' => ''],
    'actions'      => ['label' => t('Actions'),     'attrs' => 'class="text-end d-print-none"'],
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
        // JS-side translated strings — bms-expenses-table.js is a plain .js
        // file (no PHP), so it cannot call t() itself; every string it renders
        // is resolved here, once, and read off cfg.i18n.*.
        i18n: {
            editExpense:      <?= json_encode(t('Edit Expense')) ?>,
            markAsReviewed:   <?= json_encode(t('Mark as Reviewed')) ?>,
            approve:          <?= json_encode(t('Approve')) ?>,
            reject:           <?= json_encode(t('Reject')) ?>,
            markAsPaid:       <?= json_encode(t('Mark as Paid')) ?>,
            delete:           <?= json_encode(t('Delete')) ?>,
            viewDetails:      <?= json_encode(t('View Details')) ?>,
            printVoucher:     <?= json_encode(t('Print Voucher')) ?>,
            unknown:          <?= json_encode(t('Unknown')) ?>,
            dayPrefix:        <?= json_encode(t('Day:')) ?>,
            supplier:         <?= json_encode(t('Supplier')) ?>,
            staff:            <?= json_encode(t('Staff')) ?>,
            na:               <?= json_encode(t('N/A')) ?>,
            updateStatusTitle:<?= json_encode(t('Update Status?')) ?>,
            markAsConfirm:    <?= json_encode(t('Are you sure you want to mark this as')) ?>,
            yesProceed:       <?= json_encode(t('Yes, Proceed')) ?>,
            updated:          <?= json_encode(t('Updated!')) ?>,
            deleteExpenseTitle: <?= json_encode(t('Delete Expense?')) ?>,
            deleteExpenseText: <?= json_encode(t('Permanently delete this expense? This action cannot be undone.')) ?>,
            yesDelete:        <?= json_encode(t('Yes, Delete')) ?>,
            deleted:          <?= json_encode(t('Deleted!')) ?>,
            couldNotLoadExpense: <?= json_encode(t('Could not load expense')) ?>,
            error:            <?= json_encode(t('Error')) ?>,
            paymentVoucher:   <?= json_encode(t('Payment Voucher')) ?>,
            voucherNo:        <?= json_encode(t('Voucher No:')) ?>,
            dateLabel:        <?= json_encode(t('Date:')) ?>,
            amountPaid:       <?= json_encode(t('Amount Paid')) ?>,
            inWords:          <?= json_encode(t('In Words:')) ?>,
            paidTo:           <?= json_encode(t('Paid To')) ?>,
            description:      <?= json_encode(t('Description')) ?>,
            expenseAccount:   <?= json_encode(t('Expense Account')) ?>,
            paidFromBank:     <?= json_encode(t('Paid From (Bank)')) ?>,
            referenceNo:      <?= json_encode(t('Reference No.')) ?>,
            notes:            <?= json_encode(t('Notes')) ?>,
            status:           <?= json_encode(t('Status')) ?>,
            preparedBy:       <?= json_encode(t('Prepared By')) ?>,
            approvedBy:       <?= json_encode(t('Approved By')) ?>,
            receivedBy:       <?= json_encode(t('Received By')) ?>,
            voucherNoteLabel: <?= json_encode(t('Note:')) ?>,
            voucherNoteText:  <?= json_encode(t('This is a computer-generated payment voucher. Please verify all details before processing payment.')) ?>,
            statusLabels: {
                pending:  <?= json_encode(t('Pending')) ?>,
                reviewed: <?= json_encode(t('Reviewed')) ?>,
                approved: <?= json_encode(t('Approved')) ?>,
                rejected: <?= json_encode(t('Rejected')) ?>,
                paid:     <?= json_encode(t('Paid')) ?>,
            },
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
