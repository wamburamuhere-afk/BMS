<?php
/**
 * Credit Notes (Phase 1) — CLI test
 * ---------------------------------
 *   php tests/test_credit_notes_cli.php
 *
 * Verifies:
 *   1. All new files exist + lint clean.
 *   2. Foundation migration creates the tables, permission, account + setting.
 *   3. API source contains the agreed workflow + payment rules.
 *   4. Income Statement + Cash Flow wiring for paid credit notes.
 *   5. Sales-return origin button + double-count guard.
 *   6. Runtime DB state: tables, permission row, account, setting present.
 *
 * Exit 0 = all pass. Exit 1 = failures.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$failures = 0; $passes = 0;
function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $passes, $failures; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'migrations/2026_06_04_credit_notes_foundation.php',
    'app/bms/sales/credit_notes/credit_notes.php',
    'app/bms/sales/credit_notes/credit_note_create.php',
    'app/bms/sales/credit_notes/credit_note_edit.php',
    'app/bms/sales/credit_notes/credit_note_view.php',
    'app/bms/sales/credit_notes/print_credit_note.php',
    'api/sales/create_credit_note.php',
    'api/sales/update_credit_note.php',
    'api/sales/review_credit_note.php',
    'api/sales/approve_credit_note.php',
    'api/sales/pay_credit_note.php',
    'api/sales/delete_credit_note.php',
    'api/sales/search_credit_customers.php',
    'api/sales/search_approved_sales_returns.php',
    'api/sales/get_credit_note_source.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' | ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Foundation migration');
$mig = src($root, 'migrations/2026_06_04_credit_notes_foundation.php');
has($mig, "CREATE TABLE IF NOT EXISTS credit_notes",      'creates credit_notes table');
has($mig, "CREATE TABLE IF NOT EXISTS credit_note_items", 'creates credit_note_items table');
has($mig, "page_key = 'credit_notes'",                    'seeds credit_notes permission');
has($mig, "Sales Returns & Allowances",                   'seeds contra-revenue account');
has($mig, "default_sales_returns_account_id",             'seeds account setting');
has($mig, "ENUM('pending','reviewed','approved','paid'",  'status enum includes the lifecycle');

// ─────────────────────────────────────────────────────────────────────────
section('3. Workflow + payment API rules');
$create = src($root, 'api/sales/create_credit_note.php');
has($create, "'pending'",                    'create starts at pending');
has($create, "workflowCaptureSignature(\$pdo, 'credit_note'", "create captures 'created' e-signature");
has($create, "CN-",                          'create generates CN- number');
has($create, "get_next_ref",                 'create exposes get_next_ref action');

$review = src($root, 'api/sales/review_credit_note.php');
has($review, "canReview('credit_notes')",    'review gated by canReview');
has($review, "status = 'reviewed'",          'review sets status reviewed');
has($review, "!== 'pending'",                'review only from pending');

$approve = src($root, 'api/sales/approve_credit_note.php');
has($approve, "canApprove('credit_notes')",  'approve gated by canApprove');
has($approve, "status = 'approved'",         'approve sets status approved');
has($approve, "!== 'reviewed'",              'approve only from reviewed');

$pay = src($root, 'api/sales/pay_credit_note.php');
has($pay, "postOutflow(",                            'payment posts an outflow');
has($pay, "default_sales_returns_account_id",        'payment debits the contra-revenue account');
has($pay, "status = 'paid'",                         'payment marks note paid');
has($pay, "!== 'approved'",                          'payment only from approved');
has($pay, "canApprove('credit_notes')",              'payment gated (senior, post-approval)');

$del = src($root, 'api/sales/delete_credit_note.php');
has($del, "status = 'deleted'",              'delete is a soft delete');
has($del, "=== 'paid'",                      'delete blocks paid notes');

// ─────────────────────────────────────────────────────────────────────────
section('4. Income Statement + Cash Flow wiring');
$is = src($root, 'api/account/get_income_statement.php');
has($is, "\$sumCreditNotes",                          'income statement sums credit notes');
has($is, "cn.status = 'paid'",                        'income statement counts only paid credit notes');
has($is, "Less: Sales Returns & Credit Notes",        'contra-revenue line renamed');
has($is, "FROM credit_notes cn",                      'income statement queries credit_notes');

$cf = src($root, 'api/account/get_cash_flow.php');
has($cf, "credit_note_refunds",                       'cash flow computes credit-note refunds');
has($cf, "Customer refunds (credit notes)",           'cash flow shows the operating outflow line');
has($cf, "cn.status = 'paid'",                         'cash flow counts only paid credit notes');

// ─────────────────────────────────────────────────────────────────────────
section('5. Sales-return origin button + double-count guard');
$srv = src($root, 'app/bms/sales/sales_returns/sales_return_view.php');
has($srv, "credit_note_create",              'return view links to Create Credit Note');
has($srv, "FROM credit_notes",               'return view detects an existing credit note');

$urs = src($root, 'api/sales/update_return_status.php');
has($urs, "credited by note",                'refunded transition blocked when a credit note exists');

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime DB state (migration applied)');
try {
    $hasCn  = (bool)$pdo->query("SHOW TABLES LIKE 'credit_notes'")->fetch();
    $hasCni = (bool)$pdo->query("SHOW TABLES LIKE 'credit_note_items'")->fetch();
    $hasCn  ? pass('credit_notes table exists') : fail('credit_notes table missing — run the migration');
    $hasCni ? pass('credit_note_items table exists') : fail('credit_note_items table missing — run the migration');

    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'credit_notes' LIMIT 1")->fetchColumn();
    $perm ? pass("permission 'credit_notes' seeded (id $perm)") : fail("permission 'credit_notes' not seeded");

    $acc = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'Sales Returns & Allowances' LIMIT 1")->fetchColumn();
    $acc ? pass("contra-revenue account seeded (id $acc)") : fail('Sales Returns & Allowances account not seeded');

    $set = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_sales_returns_account_id' LIMIT 1")->fetchColumn();
    $set ? pass("setting default_sales_returns_account_id = $set") : fail('default_sales_returns_account_id setting missing');
} catch (Throwable $e) {
    fail('Runtime DB check error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Intelligent create — curated pickers, real products, SKU-on-print, attachments');

$ss = src($root, 'api/sales/search_credit_customers.php');
has($ss, "JOIN sales_returns sr",                     'customer picker joins approved returns');
has($ss, "sr.status = 'approved'",                    'customer picker requires approved return');
has($ss, "NOT EXISTS",                                'customer picker excludes returns that already have a credit note');

$sr2 = src($root, 'api/sales/search_approved_sales_returns.php');
has($sr2, "customer_id",                              'return picker filters by customer');

$sp = src($root, 'api/search_products.php');
has($sp, "FROM products",                             'product search exists');

$cform = src($root, 'app/bms/sales/credit_notes/credit_note_create.php');
has($cform, "minimumInputLength:0",                   'pickers show on open');
has($cform, "li-product",                             'line items use a real-product search');
has($cform, "bi-trash3",                              'delete uses a red trash icon (not X)');
has($cform, "attachment_names[]",                     'create form has named attachment rows');
has($cform, "enctype=\"multipart/form-data\"",        'create form posts multipart (files)');

$cc = src($root, 'api/sales/create_credit_note.php');
has($cc, "An approved sales return is required",      'create API requires a sales return');
has($cc, "saveNoteAttachments",                       'create API saves attachments');

$pp = src($root, 'app/bms/sales/credit_notes/print_credit_note.php');
has($pp, "Product Code",                              'print has a Product Code (SKU) column');
has($pp, "p.sku",                                     'print joins products for SKU');
(strpos($cform, 'Product Code') === false)
    ? pass('create form does NOT show SKU column')
    : fail('create form leaked a SKU column (should be print-only)');

$am = src($root, 'migrations/2026_06_05_note_attachments.php');
has($am, "credit_note_attachments",                   'migration creates credit_note_attachments');
try {
    (bool)$pdo->query("SHOW TABLES LIKE 'credit_note_attachments'")->fetch()
        ? pass('credit_note_attachments table exists') : fail('credit_note_attachments table missing — run the migration');
} catch (Throwable $e) { fail('attachment table check error: ' . $e->getMessage()); }
