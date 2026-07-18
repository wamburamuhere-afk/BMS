<?php
/**
 * Quotations Module CLI Test Suite
 * Run: php tests/test_quotations_cli.php
 * Exit 0 = all pass (safe to push)
 * Exit 1 = failures found (push blocked)
 *
 * Static-analysis suite — no database required.
 *
 * Covers the dedicated quotations module: separate `quotations` /
 * `quotation_items` tables, dedicated pages (view / form / create / edit /
 * print), dedicated APIs, routing, and a regression guard that the shared
 * sales-order files were not disturbed.
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $path = "$root/$rel";
    return file_exists($path) ? file_get_contents($path) : '';
}
function want(string $hay, string $needle, string $ok, string $ko): void {
    str_contains($hay, $needle) ? pass($ok) : fail($ko);
}
function wantAbsent(string $hay, string $needle, string $ok, string $ko): void {
    !str_contains($hay, $needle) ? pass($ok) : fail($ko);
}

$migration = 'migrations/2026_05_22_create_quotations_tables.php';
$files = [
    'migration'        => $migration,
    'save API'         => 'api/account/save_quotation.php',
    'delete API'       => 'api/account/delete_quotation.php',
    'status API'       => 'api/account/update_quotation_status.php',
    'convert API'      => 'api/account/convert_quote_to_order.php',
    'review API'       => 'api/account/review_quotation.php',
    'approve API'      => 'api/account/approve_quotation.php',
    'workflow migration' => 'migrations/2026_05_22_quotation_workflow.php',
    'view page'        => 'app/bms/sales/quotations/quotation_view.php',
    'print page'       => 'app/bms/sales/quotations/print_quotation.php',
    'form body'        => 'app/bms/sales/quotations/quotation_form.php',
    'create entry'     => 'app/bms/sales/quotations/quotation_create.php',
    'edit entry'       => 'app/bms/sales/quotations/quotation_edit.php',
    'list page'        => 'app/bms/sales/quotations/quotations.php',
    'router'           => 'roots.php',
];

// ─────────────────────────────────────────────────────────────────────────────
section('1. Required files exist');
// ─────────────────────────────────────────────────────────────────────────────
foreach ($files as $label => $rel) {
    file_exists("$root/$rel")
        ? pass("$label — $rel")
        : fail("MISSING ($label): $rel");
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. PHP syntax');
// ─────────────────────────────────────────────────────────────────────────────
foreach ($files as $label => $rel) {
    $path = "$root/$rel";
    if (!file_exists($path)) { fail("Cannot lint — file missing: $rel"); continue; }
    $out = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (str_contains((string)$out, 'Parse error') || str_contains((string)$out, 'Fatal error')) {
        fail("Syntax error in $rel:\n     $out");
    } else {
        pass("Syntax OK: $rel");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Migration — creates dedicated tables, idempotent, non-destructive');
// ─────────────────────────────────────────────────────────────────────────────
$mig = readSrc($root, $migration);
want($mig, 'CREATE TABLE IF NOT EXISTS quotations LIKE sales_orders',
     'Creates quotations table (LIKE sales_orders — guaranteed structural match)',
     'Migration does not create the quotations table');
want($mig, 'CREATE TABLE IF NOT EXISTS quotation_items LIKE sales_order_items',
     'Creates quotation_items table',
     'Migration does not create the quotation_items table');
want($mig, 'INSERT IGNORE INTO quotations',
     'Copies existing quotations with INSERT IGNORE (idempotent)',
     'Migration does not copy existing quotation headers');
want($mig, 'INSERT IGNORE INTO quotation_items',
     'Copies existing quotation items with INSERT IGNORE (idempotent)',
     'Migration does not copy existing quotation items');
wantAbsent($mig, 'DROP TABLE',
     'Migration drops nothing (safe)',
     'Migration contains DROP TABLE — unsafe');
wantAbsent($mig, 'DELETE FROM sales_orders',
     'Migration deletes nothing from sales_orders (non-destructive copy)',
     'Migration deletes from sales_orders — risk of data loss');
want($mig, 'exit(1)',
     'Migration exits 1 on failure (halts deploy)',
     'Migration does not exit(1) on failure');

// ─────────────────────────────────────────────────────────────────────────────
section('4. save_quotation.php — writes the quotations tables only');
// ─────────────────────────────────────────────────────────────────────────────
$save = readSrc($root, 'api/account/save_quotation.php');
want($save, 'INSERT INTO quotations',  'Inserts into quotations table',  'Does not INSERT INTO quotations');
want($save, 'UPDATE quotations',       'Updates the quotations table',   'Does not UPDATE quotations');
want($save, 'INSERT INTO quotation_items', 'Inserts line items into quotation_items', 'Does not INSERT INTO quotation_items');
want($save, 'DELETE FROM quotation_items', 'Replaces items on update (DELETE FROM quotation_items)', 'Does not clear old items on update');
wantAbsent($save, 'INTO sales_orders',  'Never writes to sales_orders',  'save_quotation writes to sales_orders — wrong table');
wantAbsent($save, 'UPDATE sales_orders','Never updates sales_orders',    'save_quotation updates sales_orders — wrong table');
wantAbsent($save, "'po_no'",            'No PO field handled (quotation precedes the PO)', 'save_quotation still handles po_no');
wantAbsent($save, 'Insufficient stock', 'No stock blocking (a quotation is only a proposal)', 'save_quotation still blocks on stock');
want($save, "\$_POST['quotation_id']",  'Reads quotation_id from POST',  'Does not read quotation_id');
want($save, 'quote_valid_until',        'Persists the Valid Until date', 'Does not persist quote_valid_until');

// ─────────────────────────────────────────────────────────────────────────────
section('5. delete_quotation.php / update_quotation_status.php');
// ─────────────────────────────────────────────────────────────────────────────
$del = readSrc($root, 'api/account/delete_quotation.php');
want($del, 'DELETE FROM quotations',      'delete: removes the quotation header', 'delete: missing DELETE FROM quotations');
want($del, 'DELETE FROM quotation_items', 'delete: removes the quotation items',  'delete: missing DELETE FROM quotation_items');
wantAbsent($del, 'sales_order_items',     'delete: never touches sales_order_items', 'delete: touches sales_order_items');

$ust = readSrc($root, 'api/account/update_quotation_status.php');
want($ust, 'UPDATE quotations SET status', 'status: updates quotations.status', 'status: missing UPDATE quotations SET status');

// ─────────────────────────────────────────────────────────────────────────────
section('6. convert_quote_to_order.php — quotation → sales order');
// ─────────────────────────────────────────────────────────────────────────────
$conv = readSrc($root, 'api/account/convert_quote_to_order.php');
want($conv, 'FROM quotations',          'convert: reads the source from quotations', 'convert: does not read from quotations');
want($conv, 'FROM quotation_items',     'convert: reads the source items from quotation_items', 'convert: does not read quotation_items');
want($conv, 'INSERT INTO sales_orders', 'convert: creates a real row in sales_orders', 'convert: does not insert into sales_orders');
want($conv, 'INSERT INTO sales_order_items', 'convert: copies items into sales_order_items', 'convert: does not copy items into sales_order_items');
want($conv, "!== 'approved'",     'convert: only an approved quotation may be converted', 'convert: does not require approved status');
want($conv, 'converted_to_so_id', 'convert: tags the quotation with the new sales order id (blocks double-convert)', 'convert: does not record converted_to_so_id');
// User-reported 2026-07-18: print_sales_order.php's "Created By" column showed
// a name but no e-signature stamp for SOs converted from a quote, unlike
// save_sales_order.php's direct-create path which always captures it.
want($conv, "workflowCaptureSignature",              'convert: captures the "Created By" e-signature (was previously missing)', 'convert: still does not call workflowCaptureSignature');
want($conv, "'sales_order', (int)\$new_so_id, 'created'", 'convert: captures it against the NEW sales order id with action \'created\'', 'convert: signature capture is missing the correct entity_id/action');

// ─────────────────────────────────────────────────────────────────────────────
section('7. quotation_view.php — dedicated details page');
// ─────────────────────────────────────────────────────────────────────────────
$view = readSrc($root, 'app/bms/sales/quotations/quotation_view.php');
want($view, 'FROM quotations',       'view: reads the quotations table',       'view: does not read quotations');
want($view, 'FROM quotation_items',  'view: reads quotation_items',            'view: does not read quotation_items');
want($view, 'Quotation Details',     'view: titled "Quotation Details"',       'view: missing "Quotation Details" heading');
want($view, "getUrl('quotations')",  'view: "Back to List" returns to the quotations list', 'view: Back to List does not target the quotations list');
want($view, "getUrl('print_quotation')", 'view: Print uses the print_quotation route', 'view: Print does not use print_quotation');
want($view, "getUrl('quotation_edit')",  'view: Edit uses the quotation_edit route',   'view: Edit does not use quotation_edit');
wantAbsent($view, 'FROM sales_orders',   'view: never reads the sales_orders table', 'view: still reads sales_orders');

// ─────────────────────────────────────────────────────────────────────────────
section('8. print_quotation.php — dedicated print-out');
// ─────────────────────────────────────────────────────────────────────────────
$print = readSrc($root, 'app/bms/sales/quotations/print_quotation.php');
want($print, 'FROM quotations',        'print: reads the quotations table',  'print: does not read quotations');
want($print, 'FROM quotation_items',   'print: reads quotation_items',       'print: does not read quotation_items');
want($print, "\$doc_title  = 'QUOTATION'", 'print: hard-wired to the QUOTATION layout', 'print: not hard-wired to QUOTATION');
wantAbsent($print, 'FROM sales_orders','print: never reads the sales_orders table', 'print: still reads sales_orders');

// ─────────────────────────────────────────────────────────────────────────────
section('9. quotation_form.php — dedicated create/edit form');
// ─────────────────────────────────────────────────────────────────────────────
$form = readSrc($root, 'app/bms/sales/quotations/quotation_form.php');
want($form, 'FROM quotations',          'form: reads the quotations table',      'form: does not read quotations');
want($form, 'FROM quotation_items',     'form: reads quotation_items',           'form: does not read quotation_items');
want($form, 'save_quotation.php',       'form: submits to the save_quotation API', 'form: does not submit to save_quotation.php');
want($form, 'name="quotation_id"',      'form: carries the quotation_id hidden field', 'form: missing quotation_id hidden field');
want($form, 'id="quotationForm"',       'form: uses its own form id (quotationForm)', 'form: missing quotationForm id');
wantAbsent($form, 'id="po_no"',         'form: has NO PO No input (quotation precedes the PO)', 'form: still renders a PO No input');
wantAbsent($form, 'name="po_no"',       'form: submits NO po_no field',          'form: still submits a po_no field');
wantAbsent($form, 'Switch to Sales Order', 'form: has no "Switch to Sales Order" button', 'form: still offers "Switch to Sales Order"');
wantAbsent($form, 'save_sales_order.php', 'form: never calls the sales-order save API', 'form: still calls save_sales_order.php');

// Thin entry files delegate to the shared form body.
$create = readSrc($root, 'app/bms/sales/quotations/quotation_create.php');
$edit   = readSrc($root, 'app/bms/sales/quotations/quotation_edit.php');
want($create, "require_once __DIR__ . '/quotation_form.php'", 'create entry delegates to quotation_form.php', 'create entry does not delegate to quotation_form.php');
want($edit,   "require_once __DIR__ . '/quotation_form.php'", 'edit entry delegates to quotation_form.php',   'edit entry does not delegate to quotation_form.php');

// ─────────────────────────────────────────────────────────────────────────────
section('10. quotations.php — list page points at the new module');
// ─────────────────────────────────────────────────────────────────────────────
$list = readSrc($root, 'app/bms/sales/quotations/quotations.php');
want($list, 'FROM quotations q',          'list: queries the quotations table',  'list: does not query the quotations table');
wantAbsent($list, 'FROM sales_orders',    'list: never queries the sales_orders table', 'list: still queries sales_orders');
want($list, "getUrl('quotation_create')", 'list: "New Quotation" → quotation_create', 'list: New Quotation does not target quotation_create');
want($list, "getUrl('quotation_view')",   'list: "View Details" → quotation_view',    'list: View Details does not target quotation_view');
want($list, "getUrl('quotation_edit')",   'list: "Edit Quote" → quotation_edit',      'list: Edit Quote does not target quotation_edit');
want($list, 'delete_quotation.php',        'list: delete uses the delete_quotation API', 'list: delete still uses delete_sales_order');
want($list, 'update_quotation_status.php', 'list: status uses the update_quotation_status API', 'list: status still uses update_sales_order_status');
wantAbsent($list, 'sales_order_view?id=', 'list: no bare sales_order_view link remains', 'list: still links to bare sales_order_view');
wantAbsent($list, 'sales_order_edit?id=', 'list: no bare sales_order_edit link remains', 'list: still links to bare sales_order_edit');

// ─────────────────────────────────────────────────────────────────────────────
section('11. roots.php — routes registered');
// ─────────────────────────────────────────────────────────────────────────────
$routes = readSrc($root, 'roots.php');
want($routes, "'quotation_view' => SALES_DIR . '/quotations/quotation_view.php'",
     'route: quotation_view registered', 'route: quotation_view missing');
want($routes, "'quotation_create' => SALES_DIR . '/quotations/quotation_create.php'",
     'route: quotation_create registered', 'route: quotation_create missing');
want($routes, "'quotation_edit' => SALES_DIR . '/quotations/quotation_edit.php'",
     'route: quotation_edit registered', 'route: quotation_edit missing');
want($routes, "'print_quotation' => SALES_DIR . '/quotations/print_quotation.php'",
     'route: print_quotation points at the dedicated print file', 'route: print_quotation not repointed');

// ─────────────────────────────────────────────────────────────────────────────
section('12. Sales-order side — strictly separated from quotations');
// ─────────────────────────────────────────────────────────────────────────────
$so_list = readSrc($root, 'app/bms/sales/sales_orders.php');
want($so_list, 'WHERE so.is_quote = 0',
     'Sales Orders list excludes quotations (WHERE is_quote = 0)',
     'Sales Orders list no longer filters out quotations — regression');

$so_view = readSrc($root, 'app/bms/sales/sales_order_view.php');
want($so_view, 'so.is_quote = 0', 'sales_order_view loads sales orders only (is_quote = 0)',
     'sales_order_view does not restrict its query to sales orders');
want($so_view, "getUrl('print_sales_order')", 'sales_order_view Print uses the print_sales_order route',
     'sales_order_view Print route is wrong');
wantAbsent($so_view, 'print_quotation', 'sales_order_view never routes Print to print_quotation',
     'sales_order_view can still route Print to print_quotation');

$so_print = readSrc($root, 'app/bms/sales/print_sales_order.php');
want($so_print, 'so.is_quote = 0', 'print_sales_order renders sales orders only (is_quote = 0)',
     'print_sales_order does not restrict its query to sales orders');

foreach (['app/bms/sales/sales_order_view.php',
          'app/bms/sales/sales_order_create.php',
          'app/bms/sales/print_sales_order.php'] as $sf) {
    file_exists("$root/$sf")
        ? pass("Sales-order file present: $sf")
        : fail("Sales-order file went missing: $sf");
}

// ─────────────────────────────────────────────────────────────────────────────
section('13. Approval workflow — pending -> reviewed -> approved');
// ─────────────────────────────────────────────────────────────────────────────
$wfMig = readSrc($root, 'migrations/2026_05_22_quotation_workflow.php');
want($wfMig, 'reviewed_by INT NULL',        'workflow migration adds reviewed_by',        'workflow migration missing reviewed_by');
want($wfMig, 'reviewed_at DATETIME NULL',   'workflow migration adds reviewed_at',        'workflow migration missing reviewed_at');
want($wfMig, 'approved_at DATETIME NULL',   'workflow migration adds approved_at',        'workflow migration missing approved_at');
want($wfMig, 'converted_to_so_id INT NULL', 'workflow migration adds converted_to_so_id', 'workflow migration missing converted_to_so_id');
want($wfMig, "'reviewed'",                  "workflow migration extends the status ENUM with 'reviewed'", 'workflow migration does not add reviewed to the ENUM');

$rev = readSrc($root, 'api/account/review_quotation.php');
want($rev, "canReview('sales_orders')", 'review API is gated by canReview',          'review API not gated by canReview');
want($rev, "status = 'reviewed'",       'review API sets status to reviewed',        'review API does not set status reviewed');
want($rev, 'reviewed_by',               'review API stamps reviewed_by',             'review API does not stamp reviewed_by');
want($rev, "!== 'pending'",             'review API requires a pending quotation',   'review API does not enforce the pending pre-state');

$apr = readSrc($root, 'api/account/approve_quotation.php');
want($apr, "canApprove('sales_orders')", 'approve API is gated by canApprove',       'approve API not gated by canApprove');
want($apr, "status = 'approved'",        'approve API sets status to approved',      'approve API does not set status approved');
want($apr, 'approved_by',                'approve API stamps approved_by',           'approve API does not stamp approved_by');
want($apr, "!== 'reviewed'",             'approve API requires a reviewed quotation','approve API does not enforce the reviewed pre-state');

want($save, "'pending', 1,",  'save_quotation: a new quotation starts at status pending', 'save_quotation does not create as pending');
want($save, "=== 'approved'", 'save_quotation: blocks editing an approved quotation',     'save_quotation does not lock approved quotations');

want($list, 'reviewQuotation',  'list: has the Review action',  'list: missing Review action');
want($list, 'approveQuotation', 'list: has the Approve action', 'list: missing Approve action');
want($list, "\$status === 'approved' && empty(\$q['converted_to_so_id'])",
     'list: Convert shows only for an un-converted approved quotation', 'list: Convert visibility rule missing');

$wView = readSrc($root, 'app/bms/sales/quotations/quotation_view.php');
want($wView, 'Created By',            'view: workflow strip shows Created By',  'view: missing Created By');
want($wView, 'Reviewed By',           'view: workflow strip shows Reviewed By', 'view: missing Reviewed By');
want($wView, 'Approved By',           'view: workflow strip shows Approved By', 'view: missing Approved By');
want($wView, "status !== 'approved'", 'view: Edit button hidden once approved', 'view: Edit not hidden when approved');

$wPrint = readSrc($root, 'app/bms/sales/quotations/print_quotation.php');
want($wPrint, 'Account Details', 'print: shows the Account Details block', 'print: missing Account Details block');
want($wPrint, 'bank_name',       'print: pulls the bank settings',         'print: does not read bank settings');
want($wPrint, 'workflow_signature_row.php', 'print: includes canonical signature partial (Created/Reviewed/Approved By)', 'print: missing workflow_signature_row.php include');
wantAbsent($wPrint, 'Authorized Signature',    'print: old "Authorized Signature" line removed',    'print: still shows "Authorized Signature"');
wantAbsent($wPrint, 'Customer Acknowledgment', 'print: old "Customer Acknowledgment" line removed', 'print: still shows "Customer Acknowledgment"');

$wForm = readSrc($root, 'app/bms/sales/quotations/quotation_form.php');
want($wForm, "=== 'approved'", 'form: blocks editing an approved quotation', 'form: does not block editing approved quotations');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes tests passed — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed\033[0m\n";
    echo "\033[31mFix the errors above before pushing.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(1);
}
