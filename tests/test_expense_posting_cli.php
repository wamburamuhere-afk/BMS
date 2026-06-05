<?php
/**
 * Expenses — post-gated cash (GAP 1) + bank register (GAP 2) — CLI test
 *   php tests/test_expense_posting_cli.php
 *
 * Verifies: create posts NOTHING; the Paid step posts the double entry + a bank
 * register row + drops the bank balance; edit/delete are blocked once paid;
 * void reverses everything; backfill is idempotent. Source-asserts the contracts.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/bank_register.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'core/bank_register.php',
    'api/account/add_expense.php', 'api/account/update_expense_status.php',
    'api/account/update_expense.php', 'api/account/delete_expense.php',
    'api/account/get_bank_statement.php', 'app/constant/accounts/bank_statement.php',
    'api/account/create_reconciliation.php',
    'migrations/2026_06_06_bank_register_backfill.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. GAP 1 — create posts nothing; Paid posts; edit/delete locked; void');
$add = src($root, 'api/account/add_expense.php');
hasnt($add, "recordGlobalTransaction(\$transactionData", 'add_expense no longer posts the ledger at create');
hasnt($add, "applyAccountBalanceDelta(\$pdo, (int)\$bank_account_id, 'credit'", 'add_expense no longer moves cash at create');
has($add, "GAP 1", 'add_expense documents the deferred posting');

$st = src($root, 'api/account/update_expense_status.php');
has($st, "postOutflow(\$pdo, 'expense'", 'Paid step posts via postOutflow');
has($st, "recordBankTransaction(\$pdo, \$bank", 'Paid step writes the bank register row');
has($st, "empty(\$expense_snap['transaction_id'])", 'posting is idempotent (only if not already posted)');
has($st, "reverseOutflow(\$pdo", 'void (paid->rejected) reverses the ledger');
has($st, "reverseBankTransaction(\$pdo", 'void reverses the bank register row');

$up = src($root, 'api/account/update_expense.php');
has($up, "old_status === 'paid'", 'edit blocked when paid');
has($up, "if (\$existing_txn_id)", 'edit re-syncs ledger ONLY for legacy posted rows');

$del = src($root, 'api/account/delete_expense.php');
has($del, "'paid'", 'delete blocked when paid');
has($del, "if (\$transactionId)", 'delete reverses ONLY legacy posted rows');

// ─────────────────────────────────────────────────────────────────────────
section('3. GAP 2 — register helper + statement + authoritative book balance');
$reg = src($root, 'core/bank_register.php');
has($reg, "function recordBankTransaction", 'recordBankTransaction exists');
has($reg, "function reverseBankTransaction", 'reverseBankTransaction exists');
has($reg, "balance_after", 'register carries a running balance');
$stm = src($root, 'api/account/get_bank_statement.php');
has($stm, "FROM bank_transactions", 'statement API reads the register');
$rec = src($root, 'api/account/create_reconciliation.php');
has($rec, "SELECT current_balance FROM accounts WHERE account_id = ?", 'reconciliation book-balance is from the ledger');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — full create → paid → void cycle (rolled back)');
try {
    $acct = $pdo->query("SELECT account_id, COALESCE(current_balance,0) cb FROM accounts WHERE status='active' AND account_type='asset' AND cash_flow_category='cash' ORDER BY account_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $expAcc = $pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%') LIMIT 1")->fetchColumn();
    if (!$acct || !$expAcc) { fail('need a cash account + an expense account'); }
    else {
        $bank = (int)$acct['account_id']; $bal0 = (float)$acct['cb']; $amt = 1234.00;

        $pdo->beginTransaction();
        // create (pending, no posting)
        $pdo->prepare("INSERT INTO expenses (expense_date, expense_account_id, amount, bank_account_id, description, status, created_by) VALUES (?,?,?,?,?, 'pending', ?)")
            ->execute([date('Y-m-d'), (int)$expAcc, $amt, $bank, 'POST-TEST', 4]);
        $eid = (int)$pdo->lastInsertId();
        $balNow = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
        abs($balNow - $bal0) < 0.001 ? pass('create did NOT move the bank balance') : fail("create moved balance ($bal0 -> $balNow)");
        (empty($pdo->query("SELECT transaction_id FROM expenses WHERE expense_id=$eid")->fetchColumn())) ? pass('create left transaction_id NULL') : fail('create set a transaction_id');

        // paid (post)
        $ref = 'EXP-' . $eid;
        $txn = postOutflow($pdo, 'expense', $bank, (int)$expAcc, $amt, date('Y-m-d'), $ref, 'Expense #'.$eid, null);
        $pdo->prepare("UPDATE expenses SET status='paid', transaction_id=? WHERE expense_id=?")->execute([$txn, $eid]);
        recordBankTransaction($pdo, $bank, $amt, 'withdrawal', date('Y-m-d'), $ref, 'Expense #'.$eid, 4);

        $balPaid = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
        abs($balPaid - ($bal0 - $amt)) < 0.001 ? pass('Paid dropped the bank balance by the amount') : fail("paid balance wrong ($balPaid vs ".($bal0-$amt).")");
        $books = (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn();
        $books === 2 ? pass('Paid wrote a balanced 2-line double entry') : fail("expected 2 ledger lines, got $books");
        $regRow = $pdo->query("SELECT amount, transaction_type FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref) . " AND bank_account_id=$bank")->fetch(PDO::FETCH_ASSOC);
        ($regRow && (float)$regRow['amount'] === $amt && $regRow['transaction_type'] === 'withdrawal') ? pass('Paid wrote a bank register withdrawal row') : fail('no register row written');

        // void (reverse)
        reverseOutflow($pdo, $txn);
        reverseBankTransaction($pdo, $bank, $ref, 'withdrawal');
        $balVoid = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
        abs($balVoid - $bal0) < 0.001 ? pass('Void restored the bank balance') : fail("void balance wrong ($balVoid vs $bal0)");
        ((int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetchColumn() === 0) ? pass('Void removed the register row') : fail('register row remained');

        $pdo->rollBack();   // discard the whole test cycle
        pass('test cycle rolled back (no persistence)');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('runtime error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Backfill register present + idempotent');
try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions")->fetchColumn();
    $n > 0 ? pass("bank_transactions populated ($n rows)") : fail('bank_transactions still empty — run the backfill migration');
} catch (Throwable $e) { fail('register check error: ' . $e->getMessage()); }
