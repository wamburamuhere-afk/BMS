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
