<?php
/**
 * Voucher delete reverses the accrual + locks paid — CLI test
 *   php tests/test_voucher_delete_reversal_cli.php
 *
 * Gap (account_financial.md #6): delete_voucher.php was a bare DELETE — it never
 * reversed the approval accrual (Dr Expense / Cr Accrued Expenses) and had no
 * status guard, so deleting an approved voucher orphaned the accrual, and a PAID
 * voucher could be hard-deleted (orphaning the bank/GL payment entries). This
 * verifies the fix: delete reverses the accrual for an approved-unpaid voucher,
 * and blocks delete when payments exist.
 *
 * Runtime drives the real accrual helpers inside a ROLLED-BACK transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/expense_posting.php";
require_once "$root/core/financial_reports.php";
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

// ── 1. Source contracts in delete_voucher.php ────────────────────────────────
section('1. delete_voucher.php — reversal + paid-lock wired');
$src = file_get_contents("$root/api/account/delete_voucher.php");
ok(strpos($src, "core/expense_posting.php") !== false, 'includes core/expense_posting.php');
ok(strpos($src, 'voucherIsAccrued($pdo, $id)') !== false, 'guards with voucherIsAccrued()');
ok(strpos($src, 'reverseVoucherAccrual($pdo, $id') !== false, 'calls reverseVoucherAccrual() before delete');
ok(strpos($src, 'voucher_payments WHERE voucher_id') !== false, 'checks voucher_payments for the paid-lock');
ok(strpos($src, "['paid', 'partially_paid']") !== false || strpos($src, "'partially_paid'") !== false, 'locks paid / partially_paid vouchers');
ok(strpos($src, 'DELETE FROM payment_vouchers') !== false, 'still deletes the voucher when allowed');
ok(strpos($src, 'beginTransaction') !== false && preg_match('/catch[^{]*\{[^}]*rollBack/s', $src) === 1, 'wraps in a transaction with rollback');

// ── 2. Runtime — accrual posts, reversal nets to zero (rolled back) ──────────
section('2. Runtime — approve posts accrual; delete reverses it (rolled back)');
$expAcc = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                             WHERE a.status='active' AND at.category IN ('expense','finance_cost') ORDER BY a.account_id LIMIT 1")->fetchColumn();
$accrued = (int)accruedExpensesAccountId($pdo);
ok($expAcc > 0 && $accrued > 0, "have expense (#$expAcc) + Accrued Expenses (#$accrued) accounts");

$FAKE_VOUCHER_ID = 999000777;   // synthetic, unique to this test
$amount = 7654.00;

$pdo->beginTransaction();
try {
    $r = postVoucherAccrual($pdo, $FAKE_VOUCHER_ID, $expAcc, $amount, date('Y-m-d'), null, 4, 'VCH-TST', 'voucher delete test');
    ok(!empty($r['posted']), 'accrual posted (Dr Expense / Cr Accrued)');
    ok(voucherIsAccrued($pdo, $FAKE_VOUCHER_ID), 'voucherIsAccrued() = true');

    $bal = function(int $acc) use ($pdo, $FAKE_VOUCHER_ID): float {
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
            FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
            WHERE jei.account_id=? AND je.entity_id=? AND je.entity_type IN ('voucher_accrual','voucher_accrual_void')");
        $s->execute([$acc, $FAKE_VOUCHER_ID]);
        return round((float)$s->fetchColumn(), 2);
    };
    ok(abs($bal($expAcc) - $amount) < 0.01, 'Expense carries +'.$amount.' after accrual');
    ok(abs($bal($accrued) + $amount) < 0.01, 'Accrued carries -'.$amount.' after accrual');

    // The exact call delete_voucher.php now makes for an approved-unpaid voucher.
    $rev = reverseVoucherAccrual($pdo, $FAKE_VOUCHER_ID, 4);
    ok(!empty($rev['reversed']), 'reverseVoucherAccrual() posted the contra');
    $voidExists = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='voucher_accrual_void' AND entity_id=$FAKE_VOUCHER_ID AND status='posted'")->fetchColumn();
    ok($voidExists === 1, 'a voucher_accrual_void entry now exists');
    ok(abs($bal($expAcc)) < 0.01, 'Expense nets to ZERO after delete (not overstated)');
    ok(abs($bal($accrued)) < 0.01, 'Accrued nets to ZERO after delete (not overstated)');

    $rev2 = reverseVoucherAccrual($pdo, $FAKE_VOUCHER_ID, 4);
    ok(($rev2['reason'] ?? '') === 'already_reversed', 'reverse is idempotent');

    ok(assertLedgerBalanced($pdo, date('Y-m-d'))['ledger_balanced'], 'ledger balanced after accrual + reversal');
} finally {
    $pdo->rollBack();
}
$leak = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$FAKE_VOUCHER_ID AND entity_type IN ('voucher_accrual','voucher_accrual_void')")->fetchColumn();
ok($leak === 0, 'rolled back cleanly — no test rows persisted');
