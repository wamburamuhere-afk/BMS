<?php
/**
 * Debit Notes (Phase 2) — CLI test
 * --------------------------------
 *   php tests/test_debit_notes_cli.php
 *
 * Verifies files+lint, foundation migration, workflow/payment rules, the
 * postInflow helper, income-statement + cash-flow wiring, the purchase-return
 * origin button, and live DB state. Exit 0 = all pass.
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
    'migrations/2026_06_04_debit_notes_foundation.php',
    'app/bms/purchase/debit_notes/debit_notes.php',
    'app/bms/purchase/debit_notes/debit_note_create.php',
    'app/bms/purchase/debit_notes/debit_note_edit.php',
    'app/bms/purchase/debit_notes/debit_note_view.php',
    'app/bms/purchase/debit_notes/print_debit_note.php',
    'api/purchase/create_debit_note.php',
    'api/purchase/update_debit_note.php',
    'api/purchase/review_debit_note.php',
    'api/purchase/approve_debit_note.php',
    'api/purchase/pay_debit_note.php',
    'api/purchase/delete_debit_note.php',
    'api/purchase/search_debit_suppliers.php',
    'api/purchase/search_approved_purchase_returns.php',
    'api/purchase/get_debit_note_source.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' | ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Foundation migration + postInflow helper');
$mig = src($root, 'migrations/2026_06_04_debit_notes_foundation.php');
has($mig, "CREATE TABLE IF NOT EXISTS debit_notes",      'creates debit_notes table');
has($mig, "CREATE TABLE IF NOT EXISTS debit_note_items", 'creates debit_note_items table');
has($mig, "page_key = 'debit_notes'",                    'seeds debit_notes permission');
has($mig, "Supplier Credit Notes",                       'seeds Other Income account');
has($mig, "default_supplier_credits_account_id",         'seeds account setting');

$ps = src($root, 'core/payment_source.php');
has($ps, "function postInflow(",    'payment_source defines postInflow');
has($ps, "function reverseInflow(", 'payment_source defines reverseInflow');
has($ps, "'debit', \$amount",       'postInflow debits (increases) the cash account');

// ─────────────────────────────────────────────────────────────────────────
section('3. Workflow + payment API rules');
$create = src($root, 'api/purchase/create_debit_note.php');
has($create, "'pending'",                                  'create starts at pending');
has($create, "workflowCaptureSignature(\$pdo, 'debit_note'", "create captures 'created' e-signature");
has($create, "DBN-",                                       'create generates DBN- number');
has($create, "get_next_ref",                               'create exposes get_next_ref action');

$review = src($root, 'api/purchase/review_debit_note.php');
has($review, "canReview('debit_notes')", 'review gated by canReview');
has($review, "status = 'reviewed'",      'review sets status reviewed');

$approve = src($root, 'api/purchase/approve_debit_note.php');
has($approve, "canApprove('debit_notes')", 'approve gated by canApprove');
has($approve, "status = 'approved'",       'approve sets status approved');

$pay = src($root, 'api/purchase/pay_debit_note.php');
has($pay, "postInflow(",                          'payment posts an inflow');
has($pay, "default_supplier_credits_account_id",  'payment credits the Other Income account');
has($pay, "status = 'paid'",                      'payment marks note paid');
has($pay, "!== 'approved'",                       'payment only from approved');
has($pay, "canApprove('debit_notes')",            'payment gated (senior, post-approval)');

$del = src($root, 'api/purchase/delete_debit_note.php');
has($del, "status = 'deleted'", 'delete is a soft delete');
has($del, "=== 'paid'",         'delete blocks paid notes');

// ─────────────────────────────────────────────────────────────────────────
section('4. Income Statement + Cash Flow wiring');
$is = src($root, 'api/account/get_income_statement.php');
has($is, "SHOW TABLES LIKE 'debit_notes'", 'Other Income reads debit_notes');
has($is, "AND debit_date BETWEEN ? AND ?", 'Other Income filters debit notes by debit_date window');

$cf = src($root, 'api/account/get_cash_flow.php');
has($cf, "debit_note_refunds",                'cash flow computes debit-note refunds');
has($cf, "Supplier refunds (debit notes)",    'cash flow shows the operating inflow line');

// ─────────────────────────────────────────────────────────────────────────
section('5. Purchase-return origin button');
$prv = src($root, 'app/bms/purchase/purchase_return_view.php');
has($prv, "debit_note_create",   'return view links to Create Debit Note');
has($prv, "FROM debit_notes",    'return view detects an existing debit note');
has($prv, "btnCreateDebitNote",  'JS toggles the create-debit-note button on approval');

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime DB state (migration applied)');
try {
    (bool)$pdo->query("SHOW TABLES LIKE 'debit_notes'")->fetch()      ? pass('debit_notes table exists')      : fail('debit_notes table missing');
    (bool)$pdo->query("SHOW TABLES LIKE 'debit_note_items'")->fetch() ? pass('debit_note_items table exists') : fail('debit_note_items table missing');
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'debit_notes' LIMIT 1")->fetchColumn();
    $perm ? pass("permission 'debit_notes' seeded (id $perm)") : fail("permission 'debit_notes' not seeded");
    $acc = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'Supplier Credit Notes' LIMIT 1")->fetchColumn();
    $acc ? pass("Other Income account seeded (id $acc)") : fail('Supplier Credit Notes account not seeded');
    $set = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_supplier_credits_account_id' LIMIT 1")->fetchColumn();
    $set ? pass("setting default_supplier_credits_account_id = $set") : fail('default_supplier_credits_account_id setting missing');
} catch (Throwable $e) {
    fail('Runtime DB check error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Project integration (in-project workspace, same files)');

// Migration + column
$projMig = src($root, 'migrations/2026_06_05_debit_notes_project_id.php');
has($projMig, "ADD COLUMN project_id",                'migration adds debit_notes.project_id');
has($projMig, "SET dn.project_id = pr.project_id",    'migration backfills project_id from purchase return');
try {
    (bool)$pdo->query("SHOW COLUMNS FROM debit_notes LIKE 'project_id'")->fetch()
        ? pass('debit_notes.project_id column exists') : fail('debit_notes.project_id column missing — run the migration');
} catch (Throwable $e) { fail('project_id column check error: ' . $e->getMessage()); }

// Project data API surfaces debit notes
$gp = src($root, 'api/operations/get_project.php');
has($gp, '"debit_notes" => $debit_notes',             'get_project returns a debit_notes key');
has($gp, "FROM debit_notes dn",                       'get_project queries project debit notes');

// Project workspace embed (same file links carry project context)
$pv = src($root, 'app/bms/operations/project_view.php');
has($pv, 'id="proc-debit-notes"',                     'project workspace has a Debit Notes tab-pane');
has($pv, 'function renderProjectDebitNotes',          'project workspace renders project debit notes');
has($pv, 'function createDebitNote',                  'project workspace has Create Debit Note');
has($pv, 'debit_note_view?id=${pid}&project_id=${projectId}', 'embedded links carry project context');

// Create stores the project tag
$dc = src($root, 'api/purchase/create_debit_note.php');
has($dc, "project_id",                                'create API handles project_id');
has($dc, "INSERT INTO debit_notes",                   'create API inserts (with project_id column)');

// View shows a one-click Back to Project
$dv = src($root, 'app/bms/purchase/debit_notes/debit_note_view.php');
has($dv, "Back to Project",                           'view shows Back to Project');
has($dv, "getUrl('project_view')",                    'view links back to the project workspace');
has($dv, "origin_project_id",                         'view falls back to the origin return project');

// Edit threads project context through Back + Save
$de = src($root, 'app/bms/purchase/debit_notes/debit_note_edit.php');
has($de, "\$proj_qs",                                 'edit threads project context');

// ─────────────────────────────────────────────────────────────────────────
section('8. Intelligent create — curated pickers, real products, SKU-on-print, attachments');

// Curated supplier picker (only approved returns awaiting a debit note)
$ss = src($root, 'api/purchase/search_debit_suppliers.php');
has($ss, "JOIN purchase_returns pr",                  'supplier picker joins approved returns');
has($ss, "pr.status = 'approved'",                    'supplier picker requires approved return');
has($ss, "NOT EXISTS",                                'supplier picker excludes returns that already have a debit note');

// Return picker accepts a supplier filter
$sr = src($root, 'api/purchase/search_approved_purchase_returns.php');
has($sr, "supplier_id",                               'return picker filters by supplier');

// Real-product picker
$sp = src($root, 'api/search_products.php');
has($sp, "FROM products",                             'product search exists');
has($sp, "sku",                                       'product search returns SKU');

// Source returns the warehouse
$gs = src($root, 'api/purchase/get_debit_note_source.php');
has($gs, "warehouse_name",                            'source returns the return warehouse');

// Create form: curated show-on-open + real product rows + bottom Add Line + trash icon + attachments
$cf = src($root, 'app/bms/purchase/debit_notes/debit_note_create.php');
has($cf, "minimumInputLength:0",                      'pickers show on open (no typing needed)');
has($cf, "li-product",                                'line items use a real-product search');
has($cf, "bi-trash3",                                 'delete uses a red trash icon (not X)');
has($cf, "search_products.php",                       'Add Line pulls real products');
has($cf, "attachment_names[]",                        'create form has named attachment rows');
has($cf, "enctype=\"multipart/form-data\"",           'create form posts multipart (files)');
has($cf, "Returned From",                             'create shows the return warehouse');

// Create API requires a return + saves attachments
$dc2 = src($root, 'api/purchase/create_debit_note.php');
has($dc2, "An approved purchase return is required",  'create API requires a purchase return');
has($dc2, "saveNoteAttachments",                      'create API saves attachments');

// SKU appears ONLY on print (present in print, absent from create/view item rows)
$pp = src($root, 'app/bms/purchase/debit_notes/print_debit_note.php');
has($pp, "Product Code",                              'print has a Product Code (SKU) column');
has($pp, "p.sku",                                     'print joins products for SKU');
has($pp, "Returned From",                             'print shows the warehouse');
(strpos($cf, 'Product Code') === false)
    ? pass('create form does NOT show SKU column')
    : fail('create form leaked a SKU column (should be print-only)');

// Migration + tables
$am = src($root, 'migrations/2026_06_05_note_attachments.php');
has($am, "debit_note_attachments",                    'migration creates debit_note_attachments');
try {
    (bool)$pdo->query("SHOW TABLES LIKE 'debit_note_attachments'")->fetch()
        ? pass('debit_note_attachments table exists') : fail('debit_note_attachments table missing — run the migration');
} catch (Throwable $e) { fail('attachment table check error: ' . $e->getMessage()); }

// Shared upload helper
$nh = src($root, 'core/note_attachments.php');
has($nh, "function saveNoteAttachments",              'shared attachment helper exists');
has($nh, "move_uploaded_file",                        'helper moves uploaded files');
has($nh, "finfo",                                     'helper checks real MIME (§19)');
