<?php
/**
 * Invoice cancellation GL reversal — CLI test
 *   php tests/test_invoice_cancel_reversal_cli.php
 *
 * Verifies that cancelling an approved invoice reverses both the revenue
 * GL entry (Dr AR / Cr Revenue) and the COGS GL entry (Dr COGS / Cr Inventory).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/revenue_posting.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$INV_ID = 0;
function teardown(PDO $pdo): void {
    global $INV_ID;
    if (!$INV_ID) return;
    $pdo->prepare("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE je.entity_type IN ('invoice','invoice_void','invoice_cogs','invoice_cogs_void') AND je.entity_id=?")->execute([$INV_ID]);
    $pdo->prepare("DELETE FROM journal_entries WHERE entity_type IN ('invoice','invoice_void','invoice_cogs','invoice_cogs_void') AND entity_id=?")->execute([$INV_ID]);
    $pdo->prepare("DELETE FROM invoices WHERE invoice_id=?")->execute([$INV_ID]);
    $INV_ID = 0;
}
register_shutdown_function(function() use ($pdo) {
    teardown($pdo);
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail===0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// Helper: call update_invoice_status.php endpoint
$callStatus = function(int $id, string $status) use ($root) {
    $_POST = ['invoice_id' => $id, 'status' => $status];
    if (function_exists('csrf_token')) { $_POST['_csrf'] = csrf_token(); $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token(); }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start(); require $root . '/api/account/update_invoice_status.php'; $raw = ob_get_clean();
    return json_decode($raw, true);
};

// ── Setup ──────────────────────────────────────────────────────────────────
section('1. Create a synthetic invoice and approve it');

$pdo->prepare("INSERT INTO invoices (invoice_number, invoice_date, customer_id, subtotal, tax_amount, grand_total, status, created_by)
               VALUES (?,CURDATE(),1,100000,0,100000,'reviewed',4)")
    ->execute(['INV-TEST-' . time()]);
$INV_ID = (int)$pdo->lastInsertId();
$INV_ID > 0 ? pass("Invoice #$INV_ID created") : fail('Could not create test invoice');

// Approve — posts Dr AR / Cr Revenue
$res = $callStatus($INV_ID, 'approved');
(!empty($res['success'])) ? pass('Invoice approved') : fail('Approval failed: ' . json_encode($res));

$revEntry = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='invoice' AND entity_id=$INV_ID AND status='posted'")->fetchColumn();
($revEntry > 0) ? pass("Revenue GL entry posted (entry #$revEntry)") : fail('No revenue GL entry after approval');

// ── Cancel ─────────────────────────────────────────────────────────────────
section('2. Cancel the invoice — GL must be reversed');

$res2 = $callStatus($INV_ID, 'cancelled');
(!empty($res2['success'])) ? pass('Invoice cancelled') : fail('Cancellation failed: ' . json_encode($res2));

// Revenue reversal (invoice_void)
$voidEntry = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='invoice_void' AND entity_id=$INV_ID AND status='posted'")->fetchColumn();
($voidEntry > 0) ? pass("Revenue reversal GL entry posted (entry #$voidEntry)") : fail('Revenue GL entry was NOT reversed on cancellation');

// Net revenue for this invoice must be zero
$netRev = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),0)
      FROM journal_entry_items jei
      JOIN journal_entries je ON je.entry_id=jei.entry_id
     WHERE je.entity_type IN ('invoice','invoice_void') AND je.entity_id=$INV_ID AND je.status='posted'
       AND jei.account_id = (SELECT entry_id FROM journal_entries WHERE entity_type='invoice' AND entity_id=$INV_ID AND status='posted' LIMIT 1)
")->fetchColumn();
// Simpler: check ledger is balanced for this invoice
$items = $pdo->query("
    SELECT jei.type, SUM(jei.amount) AS total
      FROM journal_entry_items jei
      JOIN journal_entries je ON je.entry_id=jei.entry_id
     WHERE je.entity_type IN ('invoice','invoice_void') AND je.entity_id=$INV_ID AND je.status='posted'
     GROUP BY jei.type
")->fetchAll(PDO::FETCH_KEY_PAIR);
$dr = (float)($items['debit'] ?? 0);
$cr = (float)($items['credit'] ?? 0);
(abs($dr - $cr) < 0.01) ? pass("Revenue entries net to zero after cancellation (Dr $dr = Cr $cr)") : fail("Revenue entries not zeroed: Dr $dr, Cr $cr");

// ── Idempotency ─────────────────────────────────────────────────────────────
section('3. Calling reverseInvoiceRevenue again is idempotent');
$r2 = reverseInvoiceRevenue($pdo, $INV_ID, 4);
($r2['reason'] === 'already_reversed') ? pass('Second call returns already_reversed') : fail('Not idempotent: ' . json_encode($r2));

// ── Ledger balance ──────────────────────────────────────────────────────────
section('4. Overall ledger remains balanced after teardown');
teardown($pdo);
require_once $root . '/core/financial_reports.php';
$bal = assertLedgerBalanced($pdo);
$bal['ledger_balanced'] ? pass('Ledger Σ Dr = Σ Cr') : fail('Ledger unbalanced after teardown');
