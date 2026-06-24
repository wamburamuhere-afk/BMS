<?php
/**
 * Petty-cash delete reverses the ledger — CLI test
 *   php tests/test_petty_cash_delete_reversal_cli.php
 *
 * Gap (account_financial.md #11): delete_transaction.php was a bare DELETE that left
 * the journal mirror + legacy transactions/books_transactions + current_balance
 * behind, so the expense stayed in the P&L and Petty Cash stayed reduced after the
 * source was gone. This verifies the fix reverses the posting on delete (expense AND
 * top-up), removes the mirror, and keeps the ledger balanced. Runtime drives the real
 * helpers in a ROLLED-BACK transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";     // postPettyCashLedger / reversePettyCashLedger
require_once "$root/core/financial_reports.php";  // assertLedgerBalanced
require_once "$root/core/gl_accounts.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'cli'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

// ── 1. Source contracts ──────────────────────────────────────────────────────
section('1. delete_transaction.php — reversal wired');
$src = file_get_contents("$root/api/petty_cash/delete_transaction.php");
ok(strpos($src, 'core/payment_source.php') !== false, 'includes core/payment_source.php');
ok(strpos($src, 'reversePettyCashLedger($pdo') !== false, 'calls reversePettyCashLedger before delete');
ok(strpos($src, 'SELECT type, transaction_id') !== false, 'reads type + transaction_id before delete');
ok(strpos($src, 'beginTransaction') !== false && preg_match('/catch[^{]*\{[^}]*rollBack/s', $src) === 1, 'wrapped in a transaction with rollback');
ok(strpos($src, 'DELETE FROM petty_cash_transactions') !== false, 'still deletes the record when allowed');

// ── helpers ──────────────────────────────────────────────────────────────────
$expAcc = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                             WHERE a.status='active' AND at.category IN ('expense','finance_cost')
                               AND NOT EXISTS(SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                             ORDER BY a.account_id LIMIT 1")->fetchColumn();
$cashLeaves = $pdo->query("SELECT a.account_id FROM accounts a LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                            WHERE a.status='active' AND a.account_type='asset' AND (st.is_bank=1 OR a.cash_flow_category='cash')
                              AND NOT EXISTS(SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                            ORDER BY a.account_id")->fetchAll(PDO::FETCH_COLUMN);
$fund   = (int)(pettyCashAccountId($pdo) ?: ($cashLeaves[0] ?? 0));
$source = 0;
foreach ($cashLeaves as $c) { if ((int)$c !== $fund) { $source = (int)$c; break; } }

$mirrorCount = function (int $txn) use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=$txn")->fetchColumn();
};
$legacyCount = function (int $txn) use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE transaction_id=$txn")->fetchColumn();
};

// ── 2. Expense: post then reverse-on-delete (rolled back) ────────────────────
section('2. Runtime — expense posts; delete reverses it');
ok($expAcc > 0 && $fund > 0, "have expense (#$expAcc) + petty fund (#$fund)");
$pdo->beginTransaction();
try {
    $txn = (int)postPettyCashLedger($pdo, 'expense', 4321.00, date('Y-m-d'), 'PC-TST', 'petty delete test', null, $expAcc, $fund);
    ok($txn > 0, 'expense posted to ledger (Dr Expense / Cr Petty Cash)');
    ok($mirrorCount($txn) === 1, 'journal mirror entry exists after post');

    reversePettyCashLedger($pdo, 'expense', $txn);   // the exact call delete now makes
    ok($mirrorCount($txn) === 0, 'journal mirror removed after delete (reports react)');
    ok($legacyCount($txn) === 0, 'legacy transactions row removed after delete');
    ok(!empty(assertLedgerBalanced($pdo, date('Y-m-d'))['ledger_balanced']), 'ledger balanced after post + reversal');

    reversePettyCashLedger($pdo, 'expense', $txn);   // idempotent
    ok($mirrorCount($txn) === 0, 'second reversal is a safe no-op (idempotent)');
} finally {
    $pdo->rollBack();
}

// ── 3. Top-up (deposit): post then reverse both legs (rolled back) ───────────
section('3. Runtime — top-up posts; delete reverses both legs');
if ($source > 0 && $fund > 0) {
    $pdo->beginTransaction();
    try {
        $txn = (int)postPettyCashLedger($pdo, 'deposit', 9000.00, date('Y-m-d'), 'PC-TOP', 'petty topup test', $source, null, $fund);
        ok($txn > 0, 'top-up posted (Dr Petty Cash / Cr Bank)');
        ok($mirrorCount($txn) === 1, 'journal mirror entry exists after top-up');

        reversePettyCashLedger($pdo, 'deposit', $txn);   // reverseJournalBalances (both legs)
        ok($mirrorCount($txn) === 0, 'journal mirror removed after delete');
        ok($legacyCount($txn) === 0, 'legacy transactions row removed after delete');
        ok(!empty(assertLedgerBalanced($pdo, date('Y-m-d'))['ledger_balanced']), 'ledger balanced after top-up + reversal');
    } finally {
        $pdo->rollBack();
    }
} else {
    ok(true, 'skipped top-up case (need two distinct cash accounts) — not a failure');
}
