<?php
/**
 * tests/test_sno_and_page_fixes_cli.php
 *   php tests/test_sno_and_page_fixes_cli.php
 *
 * Regression guard for the 2026-10-28 table + page-fix rounds:
 *
 *   1. S/No — assets/js/bms-serial.js is loaded once from header.php, and every
 *      table converted to the shared sortable S/No column still uses it
 *      (BMSSerial.column count per file must not drop).
 *   2. The lists converted to DataTables keep their DataTable init.
 *   3. Page fixes stay fixed: stock_movements filter escaping, revenue
 *      permission flags at script level, customer-group / edit-expense queries
 *      on the current schema, expense voucher values JSON-encoded, add_journal
 *      partial redirect, payment_create redirects before output, and
 *      includeHeader()/includeFooter() printing once per request.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $rel): string { global $root; return (string)@file_get_contents("$root/$rel"); }
function has(string $rel, string $needle, string $label): void {
    strpos(src($rel), $needle) !== false ? pass("$rel — $label") : fail("$rel — $label (missing `" . substr($needle, 0, 60) . "`)");
}
function hasNot(string $rel, string $needle, string $label): void {
    strpos(src($rel), $needle) === false ? pass("$rel — $label") : fail("$rel — $label (found `" . substr($needle, 0, 60) . "`)");
}

echo "\n\033[1m═══ S/No + page fixes guard ═══\033[0m\n";

section('S/No column (assets/js/bms-serial.js)');
has('assets/js/bms-serial.js', 'window.BMSSerial', 'defines BMSSerial');
has('assets/js/bms-serial.js', "preDraw.dt", 'numbers client-side rows before drawing');
has('header.php', 'assets/js/bms-serial.js', 'header loads bms-serial.js');
preg_match_all('/<script[^>]*bms-serial\.js/', src('header.php')) === 1 ? pass('header.php — loads it exactly once') : fail('header.php — bms-serial.js loaded more than once');
strpos(src('header.php'), 'bms-serial.js') > strpos(src('header.php'), 'jquery') ? pass('header.php — after jQuery') : fail('header.php — bms-serial.js loaded before jQuery');

// file => minimum number of BMSSerial.column uses (one per table)
$serial = [
    'app/activity_log.php' => 1,
    'app/bms/Suppliers/supplier_details.php' => 5,
    'app/bms/crm/crm_dashboard.php' => 3,
    'app/bms/customer/customer_details.php' => 1,
    'app/bms/customer/customers.php' => 1,
    'app/bms/invoice/invoices.php' => 1,
    'app/bms/invoice/received_invoices.php' => 1,
    'app/bms/operations/assets.php' => 1,
    'app/bms/operations/maintenance.php' => 1,
    'app/bms/operations/projects.php' => 1,
    'app/bms/operations/sub_contractor_details.php' => 3,
    'app/bms/pos/announcements.php' => 1,
    'app/bms/pos/employee_contracts.php' => 1,
    'app/bms/pos/employee_details.php' => 1,
    'app/bms/pos/employee_trips.php' => 1,
    'app/bms/pos/employees.php' => 1,
    'app/bms/pos/hr_actions.php' => 1,
    'app/bms/pos/hr_performance.php' => 2,
    'app/bms/pos/meetings.php' => 1,
    'app/bms/pos/pos_dashboard.php' => 3,
    'app/bms/pos/shift_history.php' => 1,
    'app/bms/pos/trainings.php' => 1,
    'app/bms/product/services.php' => 1,
    'app/bms/purchase/nip_materials.php' => 1,
    'app/bms/purchase/purchase_orders.php' => 1,
    'app/bms/purchase/view_material_list.php' => 1,
    'app/bms/sales/credit_notes/credit_notes.php' => 1,
    'app/bms/sales/lpo/lpos.php' => 1,
    'app/bms/sales/sales_orders.php' => 1,
    'app/bms/tenders/tenders.php' => 1,
    'app/constant/accounts/bank_reconciliation.php' => 1,
    'app/constant/accounts/bank_statement.php' => 1,
    'app/constant/accounts/bank_transfers.php' => 1,
    'app/constant/accounts/journals.php' => 1,
    'app/constant/accounts/payment_vouchers.php' => 1,
    'app/constant/reports/customer_statement.php' => 1,
    'app/constant/reports/vendor_statement.php' => 1,
    'app/constant/settings/billing.php' => 1,
    'app/constant/settings/login_history.php' => 1,
    'app/constant/settings/users.php' => 1,
    'assets/js/pos-credit-aging.js' => 1,
    'assets/js/tables/bms-debit-notes-table.js' => 1,
    'assets/js/tables/bms-delivery-notes-table.js' => 1,
    'assets/js/tables/bms-expenses-table.js' => 1,
    'assets/js/tables/bms-grn-table.js' => 1,
    'assets/js/tables/bms-purchase-returns-table.js' => 1,
    'assets/js/tables/bms-rfq-table.js' => 1,
    'assets/js/warehouse-batches.js' => 1,
    'includes/project_view/scripts/pv_js_02.php' => 6,
    'includes/project_view/scripts/pv_js_03.php' => 3,
    'includes/project_view/scripts/pv_js_09.php' => 1,
];
foreach ($serial as $rel => $min) {
    $n = substr_count(src($rel), 'BMSSerial.column');
    $n >= $min ? pass("$rel — $n S/No column(s)") : fail("$rel — S/No columns dropped to $n (expected $min)");
}

section('Lists converted to DataTables');
foreach ([
    'app/constant/accounts/customer_deposits.php', 'app/bms/pos/paye_register.php', 'app/bms/invoice/po_invoice_report.php',
    'app/constant/reports/employee_statement.php', 'app/constant/accounts/petty_cash.php',
    'app/bms/restaurant/modifier_group.php', 'app/bms/restaurant/reservations.php', 'app/bms/restaurant/tables.php',
    'app/bms/sales/sales_returns/sales_returns.php', 'app/bms/stock/stock_movements.php',
] as $rel) {
    if (src($rel) === '') { fail("$rel — file missing"); continue; }
    has($rel, '.DataTable(', 'DataTable initialised');
}

section('Page fixes');
has('app/bms/stock/stock_movements.php', 'htmlspecialchars($date_from)', 'date_from escaped');
has('app/bms/stock/stock_movements.php', 'htmlspecialchars($date_to)', 'date_to escaped');
has('app/bms/stock/stock_movements.php', "['purchase_in', 'sale_out', 'adjustment_in', 'adjustment_out', 'stock_transfer']", 'movement_type whitelisted');
$rev = src('app/constant/accounts/revenue.php');
(preg_match('/<script>\s*\/\/[^\n]*\nconst CAN_REVIEW/', $rev) && strpos($rev, "    const CAN_EDIT") === false)
    ? pass('app/constant/accounts/revenue.php — permission flags at script level (renderCards can see them)')
    : fail('app/constant/accounts/revenue.php — permission flags back inside the ready handler');
foreach (['app/bms/customer/customer_group_details.php', 'app/bms/customer/customer_group_members.php'] as $rel) {
    foreach (['c.entity_type', 'c.first_name', 'c.phone_number', 'c.email_address', 'c.marital_status'] as $old) {
        hasNot($rel, $old, "no `$old` (not a customers column)");
    }
}
hasNot('app/constant/accounts/edit_expense.php', 'ORDER BY category_name', 'expense_categories ordered by its real column');
has('app/constant/accounts/edit_expense.php', 'name AS category_name', 'expense_categories name mapped');
hasNot('app/constant/accounts/expense_details.php', "addslashes(\$expense['description'])", 'description not echoed raw into JS');
has('app/constant/accounts/expense_details.php', "json_decode((string)(\$expense['expense_items']", 'expense_items decoded before reaching JS');
has('app/constant/accounts/add_journal.php', 'empty($journal_modal_partial)', 'direct hit redirects to Journals');
has('app/constant/accounts/journals.php', '$journal_modal_partial = true;', 'marks the modal include');
$pc = src('app/bms/invoice/payment_create.php');
(strpos($pc, 'includeHeader();') > strpos($pc, 'Payment can only be recorded on open invoices'))
    ? pass('app/bms/invoice/payment_create.php — redirects happen before any output') : fail('app/bms/invoice/payment_create.php — header printed before its redirects');
has('app/constant/accounts/budget_details.php', "Return to list</a></div></div>\";\n    includeFooter();", 'not-found path loads the footer');

// includeHeader()/includeFooter() once per request (report partials call them too).
$stub = '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["SCRIPT_NAME"]="/index.php";'
      . ' require ' . var_export("$root/roots.php", true) . ';'
      . ' $r = new ReflectionFunction("includeHeader"); $f = new ReflectionFunction("includeFooter");'
      . ' echo json_encode([array_key_exists("done", $r->getStaticVariables()), array_key_exists("done", $f->getStaticVariables())]);';
$tmp = sys_get_temp_dir() . '/bms_once_' . getmypid() . '.php';
file_put_contents($tmp, $stub);
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
trim(substr($out, (int)strrpos($out, '['))) === '[true,true]'
    ? pass('roots.php — includeHeader()/includeFooter() guard against a second call')
    : fail('roots.php — includeHeader()/includeFooter() can print twice (' . trim(substr($out, -80)) . ')');

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail > 0 ? 1 : 0);
