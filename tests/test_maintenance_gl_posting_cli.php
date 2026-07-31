<?php
/**
 * tests/test_maintenance_gl_posting_cli.php
 * ---------------------------------------------
 * post_principle.md gap fix — Maintenance. Verifies:
 *   1.  core/expense_posting.php Maintenance wrappers exist
 *   2.  php -l on every touched/new file
 *   3.  asset_maintenance table no longer exists (retired)
 *   4.  Completed status posts the accrual: Dr Expense / Cr Accrued Expenses, balanced
 *   5.  Paid status posts the settlement: Dr Accrued Expenses / Cr Bank, balanced
 *   6.  bank_transactions withdrawal row written on payment
 *   7.  maintenance_logs row stamped with payment_date/paid_amount/transaction_id
 *   8.  Re-saving an already-paid log with unchanged cost/accounts is a no-op (no duplicate JE)
 *   9.  Re-saving an already-paid log with a CHANGED cost is rejected
 *  10.  Cancelling a paid log reverses BOTH legs (settlement + accrual)
 *  11.  bank_transactions withdrawal row removed on cancel-after-paid
 *  12.  Deleting a posted (completed, unpaid) log reverses the accrual before soft-deleting
 *  13.  Deleting a never-posted (pending) log soft-deletes cleanly, no GL side effects
 *  14.  Deleted logs are excluded from get_maintenance_logs.php
 *  15.  Deleted logs are excluded from get_maintenance_log.php (single)
 *  16.  A deleted log cannot be re-edited (save_maintenance_log.php rejects it)
 *  17.  asset_dashboard.php's overdue-maintenance query runs cleanly against maintenance_logs
 *
 * Journal entries are InnoDB (real rollback), but maintenance_logs and
 * bank_transactions are not guaranteed to be — every fixture row is deleted
 * explicitly at the end, matching this project's established convention for
 * mixed-engine test cleanup (see test_bank_recon_phase5_import_matching_cli.php).
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/account_balance.php';
require_once __DIR__ . '/../core/expense_posting.php';
require_once __DIR__ . '/../core/payment_source.php';
require_once __DIR__ . '/../core/bank_register.php';
global $pdo;

$pass = 0; $fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label" . ($detail ? " — $detail" : '') . "\n"; $fail++; }
}

echo "\n=== Maintenance GL Posting — post_principle.md gap fix ===\n\n";

$_SESSION['user_id']  = 1;
$_SESSION['is_admin'] = true;

// ── Test 1: shared helpers exist ──────────────────────────────────────────────
ok('maintenanceLogIsAccrued() exists', function_exists('maintenanceLogIsAccrued'));
ok('reverseMaintenanceLedger() exists', function_exists('reverseMaintenanceLedger'));

// ── Test 2: php -l on every touched/new file ──────────────────────────────────
$root = dirname(__DIR__);
$touched = [
    'core/expense_posting.php',
    'api/operations/save_maintenance_log.php',
    'api/operations/delete_maintenance_log.php',
    'api/operations/get_maintenance_log.php',
    'api/operations/get_maintenance_logs.php',
    'api/operations/export_maintenance.php',
    'api/operations/print_maintenance.php',
    'app/bms/operations/maintenance.php',
    'app/bms/operations/asset_view.php',
    'app/bms/operations/asset_dashboard.php',
    'migrations/2026_07_30_maintenance_logs_payment.php',
    'migrations/2026_07_30_remove_asset_maintenance.php',
];
foreach ($touched as $rel) {
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $code);
    ok("php -l: $rel", $code === 0, implode(' ', $out));
}

// ── Test 3: asset_maintenance retired ─────────────────────────────────────────
$hasOldTable = $pdo->query("SHOW TABLES LIKE 'asset_maintenance'")->fetch();
ok('asset_maintenance table no longer exists', !$hasOldTable);

// ── Fixture accounts ───────────────────────────────────────────────────────────
$bankId = (int)$pdo->query(
    "SELECT a.account_id FROM accounts a
      JOIN account_types at ON a.account_type_id = at.type_id
     WHERE at.category IN ('asset','cash') AND a.status='active'
     ORDER BY a.account_id LIMIT 1"
)->fetchColumn();
$expId = (int)$pdo->query(
    "SELECT account_id FROM accounts WHERE account_name LIKE '%expense%' AND status='active' LIMIT 1"
)->fetchColumn();
if (!$expId) {
    $expId = (int)$pdo->query(
        "SELECT a.account_id FROM accounts a JOIN account_types t ON a.account_type_id = t.type_id
          WHERE t.category = 'expense' AND a.status = 'active' ORDER BY a.account_id LIMIT 1"
    )->fetchColumn();
}

if (!$bankId || !$expId) { echo "  SKIP: Fixture accounts not found.\n"; exit(0); }

// ── Fixture asset ──────────────────────────────────────────────────────────────
$pdo->exec("DELETE FROM assets WHERE asset_name = '__T_MAINT_ASSET__'");
$assetCode = 'T-MAINT-' . substr(md5(uniqid()), 0, 6);
$pdo->prepare("INSERT INTO assets (asset_code, asset_name, status, created_at) VALUES (?, '__T_MAINT_ASSET__', 'active', NOW())")
    ->execute([$assetCode]);
$assetId = (int)$pdo->lastInsertId();
ok('fixture asset created', $assetId > 0);

/** Run an api/operations/*.php file with the current $_POST and return decoded JSON. */
function callApi(string $rel): array {
    global $root;
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
    ob_start();
    include "$root/$rel";
    $raw = ob_get_clean();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON response: ' . $raw];
}

function getLegs(PDO $pdo, string $entityType, int $entityId): array {
    $s = $pdo->prepare("
        SELECT ji.account_id, ji.type, ji.amount
          FROM journal_entry_items ji
          JOIN journal_entries je ON je.entry_id = ji.entry_id
         WHERE je.entity_type = ? AND je.entity_id = ? AND je.status = 'posted'
    ");
    $s->execute([$entityType, $entityId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

$accruedAcc = accruedExpensesAccountId($pdo);
ok('Accrued Expenses account resolves', $accruedAcc !== null);

// ── Test 4: Completed posts the accrual ────────────────────────────────────────
$_POST = [
    'asset_id' => $assetId, 'maintenance_date' => date('Y-m-d'), 'maintenance_type' => 'repair',
    'description' => 'Test repair', 'cost' => '150000', 'performed_by' => 'Test Vendor',
    'status' => 'completed', 'expense_account_id' => $expId,
];
$res4 = callApi('api/operations/save_maintenance_log.php');
ok('save (completed) succeeds', $res4['success'] === true, json_encode($res4));

$logId = (int)$pdo->query("SELECT log_id FROM maintenance_logs WHERE asset_id = $assetId ORDER BY log_id DESC LIMIT 1")->fetchColumn();
ok('maintenance log row exists', $logId > 0);

$legs4 = getLegs($pdo, 'maintenance_log', $logId);
ok('accrual posted with 2 legs', count($legs4) === 2, json_encode($legs4));
$dr4 = array_values(array_filter($legs4, fn($l) => $l['type'] === 'debit'));
$cr4 = array_values(array_filter($legs4, fn($l) => $l['type'] === 'credit'));
ok('accrual Dr = Expense account, 150000', !empty($dr4) && (int)$dr4[0]['account_id'] === $expId && abs((float)$dr4[0]['amount'] - 150000) < 0.01, json_encode($dr4));
ok('accrual Cr = Accrued Expenses, 150000', !empty($cr4) && (int)$cr4[0]['account_id'] === $accruedAcc && abs((float)$cr4[0]['amount'] - 150000) < 0.01, json_encode($cr4));

// ── Test 5-7: Paid posts the settlement ────────────────────────────────────────
$bankBefore = accountLedgerBalanceAsOf($pdo, $bankId, date('Y-m-d'));
$_POST = [
    'log_id' => $logId, 'asset_id' => $assetId, 'maintenance_date' => date('Y-m-d'), 'maintenance_type' => 'repair',
    'description' => 'Test repair', 'cost' => '150000', 'performed_by' => 'Test Vendor',
    'status' => 'paid', 'expense_account_id' => $expId, 'paid_from_account_id' => $bankId,
    'completion_date' => date('Y-m-d'),
];
$res5 = callApi('api/operations/save_maintenance_log.php');
ok('save (paid) succeeds', $res5['success'] === true, json_encode($res5));

$row5 = $pdo->query("SELECT * FROM maintenance_logs WHERE log_id = $logId")->fetch(PDO::FETCH_ASSOC);
ok('status is paid', ($row5['status'] ?? '') === 'paid');
ok('transaction_id stamped', !empty($row5['transaction_id']));
ok('payment_date stamped', !empty($row5['payment_date']));
ok('paid_amount stamped correctly', abs((float)($row5['paid_amount'] ?? 0) - 150000) < 0.01);

$txnId5 = (int)($row5['transaction_id'] ?? 0);
// The settlement is posted via postOutflow -> recordGlobalTransaction, which
// writes the legs to books_transactions AND mirrors them into the canonical
// journal_entries ledger keyed as entity_type='books_transaction',
// entity_id=books_transactions.transaction_id (never 'maintenance' — that
// mirror keying is generic across every postOutflow caller, not per-module).
$settleLegs = $pdo->prepare("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id = ?");
$settleLegs->execute([$txnId5]);
$settleLegs = $settleLegs->fetchAll(PDO::FETCH_ASSOC);
ok('settlement posted with 2 legs', count($settleLegs) === 2, json_encode($settleLegs));
$drS = array_values(array_filter($settleLegs, fn($l) => $l['type'] === 'debit'));
$crS = array_values(array_filter($settleLegs, fn($l) => $l['type'] === 'credit'));
ok('settlement Dr = Accrued Expenses', !empty($drS) && (int)$drS[0]['account_id'] === $accruedAcc, json_encode($drS));
ok('settlement Cr = Bank/Paid-From', !empty($crS) && (int)$crS[0]['account_id'] === $bankId, json_encode($crS));

// post_principle.md Q4: must ALSO reach the canonical journal_entries ledger,
// not just the legacy books_transactions mirror.
$canonLegs = getLegs($pdo, 'books_transaction', $txnId5);
ok('settlement also mirrored into canonical journal_entries (Q4)', count($canonLegs) === 2, json_encode($canonLegs));
$sumDr = array_sum(array_map(fn($l) => $l['type'] === 'debit' ? (float)$l['amount'] : 0, $canonLegs));
$sumCr = array_sum(array_map(fn($l) => $l['type'] === 'credit' ? (float)$l['amount'] : 0, $canonLegs));
ok('canonical settlement entry balances (Sum Dr = Sum Cr)', abs($sumDr - $sumCr) < 0.01, "dr=$sumDr cr=$sumCr");

$bankTxnRow = $pdo->query("SELECT * FROM bank_transactions WHERE reference_number = 'ML-$logId' AND transaction_type = 'withdrawal'")->fetch(PDO::FETCH_ASSOC);
ok('bank_transactions withdrawal row written', !empty($bankTxnRow), json_encode($bankTxnRow));

// ── Test 8: re-save unchanged is a no-op (no duplicate settlement) ───────────
$countBefore8 = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number = 'ML-$logId'")->fetchColumn();
$res8 = callApi('api/operations/save_maintenance_log.php'); // same $_POST as test 5-7
$countAfter8 = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number = 'ML-$logId'")->fetchColumn();
ok('re-save (unchanged, already paid) succeeds', $res8['success'] === true, json_encode($res8));
ok('re-save (unchanged) does not duplicate the bank register row', $countBefore8 === $countAfter8, "before=$countBefore8 after=$countAfter8");

// ── Test 9: re-save with changed cost after paid is rejected ─────────────────
$_POST['cost'] = '999999';
$res9 = callApi('api/operations/save_maintenance_log.php');
ok('re-save (changed cost, already paid) is rejected', $res9['success'] === false, json_encode($res9));
$_POST['cost'] = '150000'; // restore

// ── Test 10-11: cancel-after-paid reverses both legs ──────────────────────────
$_POST = [
    'log_id' => $logId, 'asset_id' => $assetId, 'maintenance_date' => date('Y-m-d'), 'maintenance_type' => 'repair',
    'description' => 'Test repair', 'cost' => '150000', 'performed_by' => 'Test Vendor',
    'status' => 'cancelled', 'expense_account_id' => $expId,
];
$res10 = callApi('api/operations/save_maintenance_log.php');
ok('cancel (from paid) succeeds', $res10['success'] === true, json_encode($res10));

$row10 = $pdo->query("SELECT * FROM maintenance_logs WHERE log_id = $logId")->fetch(PDO::FETCH_ASSOC);
ok('status is cancelled', ($row10['status'] ?? '') === 'cancelled');
ok('transaction_id cleared', empty($row10['transaction_id']));

$accrualVoidedAfterCancel = accrualVoided($pdo, 'maintenance_log', $logId);
ok('accrual reversed (accrualVoided)', $accrualVoidedAfterCancel === true);

$bankTxnAfterCancel = $pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number = 'ML-$logId' AND transaction_type = 'withdrawal'")->fetchColumn();
ok('bank_transactions withdrawal row removed on cancel', (int)$bankTxnAfterCancel === 0, "count=$bankTxnAfterCancel");

// ── Test 12: delete a posted (completed, unpaid) log reverses the accrual ────
$pdo->exec("DELETE FROM maintenance_logs WHERE asset_id = $assetId"); // clean slate for this asset
$_POST = [
    'asset_id' => $assetId, 'maintenance_date' => date('Y-m-d'), 'maintenance_type' => 'inspection',
    'description' => 'Test inspection', 'cost' => '20000', 'performed_by' => 'Test Vendor',
    'status' => 'completed', 'expense_account_id' => $expId,
];
callApi('api/operations/save_maintenance_log.php');
$logId12 = (int)$pdo->query("SELECT log_id FROM maintenance_logs WHERE asset_id = $assetId ORDER BY log_id DESC LIMIT 1")->fetchColumn();
ok('fixture log for delete test created + accrued', $logId12 > 0 && accrualEntryId($pdo, 'maintenance_log', $logId12) !== null);

$_POST = ['log_id' => $logId12];
$res12 = callApi('api/operations/delete_maintenance_log.php');
ok('delete (posted, unpaid) succeeds', $res12['success'] === true, json_encode($res12));

$row12 = $pdo->query("SELECT * FROM maintenance_logs WHERE log_id = $logId12")->fetch(PDO::FETCH_ASSOC);
ok('row still exists (soft delete, not hard delete)', !empty($row12));
ok('status is deleted', ($row12['status'] ?? '') === 'deleted');
ok('accrual reversed on delete', accrualVoided($pdo, 'maintenance_log', $logId12) === true);

// ── Test 13: delete a never-posted (pending) log — clean soft-delete ─────────
$_POST = [
    'asset_id' => $assetId, 'maintenance_date' => date('Y-m-d'), 'maintenance_type' => 'routine',
    'description' => 'Test pending log', 'cost' => '0', 'performed_by' => '',
    'status' => 'pending',
];
callApi('api/operations/save_maintenance_log.php');
$logId13 = (int)$pdo->query("SELECT log_id FROM maintenance_logs WHERE asset_id = $assetId AND description = 'Test pending log' ORDER BY log_id DESC LIMIT 1")->fetchColumn();
ok('fixture pending log created', $logId13 > 0);
ok('pending log has no accrual', accrualEntryId($pdo, 'maintenance_log', $logId13) === null);

$_POST = ['log_id' => $logId13];
$res13 = callApi('api/operations/delete_maintenance_log.php');
ok('delete (never posted) succeeds', $res13['success'] === true, json_encode($res13));
$row13 = $pdo->query("SELECT status FROM maintenance_logs WHERE log_id = $logId13")->fetch(PDO::FETCH_ASSOC);
ok('pending log soft-deleted cleanly', ($row13['status'] ?? '') === 'deleted');

// ── Test 14-15: deleted logs excluded from list + single-record endpoints ────
$_GET = ['status' => '', 'search_term' => '', 'start' => 0, 'length' => 100];
$listRes = callApi('api/operations/get_maintenance_logs.php');
$listedIds = array_column($listRes['data'] ?? [], 'log_id');
ok('deleted log (12) excluded from list', !in_array($logId12, array_map('intval', $listedIds), true), json_encode($listedIds));
ok('deleted log (13) excluded from list', !in_array($logId13, array_map('intval', $listedIds), true), json_encode($listedIds));

$_GET = ['id' => $logId12];
$singleRes = callApi('api/operations/get_maintenance_log.php');
ok('deleted log excluded from single-record endpoint', $singleRes['success'] === false, json_encode($singleRes));

// ── Test 16: a deleted log cannot be re-edited ────────────────────────────────
$_POST = [
    'log_id' => $logId12, 'asset_id' => $assetId, 'maintenance_date' => date('Y-m-d'), 'maintenance_type' => 'inspection',
    'description' => 'Attempted resurrection', 'cost' => '20000', 'status' => 'pending',
];
$res16 = callApi('api/operations/save_maintenance_log.php');
ok('editing a deleted log is rejected', $res16['success'] === false, json_encode($res16));

// ── Test 17: asset_dashboard.php's overdue query runs cleanly ────────────────
try {
    $overdueTest = $pdo->query("
        SELECT a.asset_id, a.asset_code, a.asset_name, mx.next_due
          FROM assets a
          JOIN (SELECT asset_id, MAX(next_due_date) next_due FROM maintenance_logs WHERE next_due_date IS NOT NULL AND status != 'deleted' GROUP BY asset_id) mx
            ON mx.asset_id = a.asset_id
         WHERE a.status NOT IN ('deleted','disposed','written_off')
           AND mx.next_due < CURDATE()
      ORDER BY mx.next_due ASC
    ")->fetchAll();
    ok('asset_dashboard overdue query runs cleanly against maintenance_logs', true);
} catch (Throwable $e) {
    ok('asset_dashboard overdue query runs cleanly against maintenance_logs', false, $e->getMessage());
}

// ── Cleanup ────────────────────────────────────────────────────────────────────
$logIds = implode(',', array_filter([$logId, $logId12, $logId13]));

// Settlement + its canonical mirror (books_transactions has no
// reference_number of its own — that lives on the legacy `transactions`
// header table; $txnId5 was already captured directly from the API
// response, so look it up by id rather than re-deriving it by reference).
// Also sweep the legacy `transactions` header row itself.
if (!empty($txnId5)) {
    $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN (SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = $txnId5)");
    $pdo->exec("DELETE FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = $txnId5");
    $pdo->exec("DELETE FROM books_transactions WHERE transaction_id = $txnId5");
    $pdo->exec("DELETE FROM transactions WHERE transaction_id = $txnId5");
}
$pdo->exec("DELETE FROM bank_transactions WHERE reference_number LIKE 'ML-%' AND bank_account_id = $bankId");

// Accrual + its reversal (entity_type='maintenance_log' / '..._void').
if ($logIds !== '') {
    $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN (SELECT entry_id FROM journal_entries WHERE entity_type IN ('maintenance_log','maintenance_log_void') AND entity_id IN ($logIds))");
    $pdo->exec("DELETE FROM journal_entries WHERE entity_type IN ('maintenance_log','maintenance_log_void') AND entity_id IN ($logIds)");
}
$pdo->exec("DELETE FROM maintenance_logs WHERE asset_id = $assetId");
$pdo->exec("DELETE FROM assets WHERE asset_id = $assetId");

echo "\n--- Maintenance GL Posting results: $pass passed, $fail failed ---\n\n";
exit($fail > 0 ? 1 : 0);
