<?php
/**
 * Customer payment void reverses the receipt — CLI test
 *   php tests/test_payment_void_cli.php
 *
 * Gap (account_financial.md #12a): there was NO void/delete path for a customer
 * receipt — a mis-keyed payment couldn't be undone. This verifies the new reversal:
 * the receipt entry (Dr Bank / Cr AR) is contra-posted so Bank and AR net to zero,
 * it's idempotent and ledger-balanced, and the void endpoint wires the full unwind
 * (ledger + payment status + invoice recompute + bank register).
 * Runtime drives the real helpers in a ROLLED-BACK transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/money_in_posting.php";   // postPaymentReceived / reversePaymentReceived
require_once "$root/core/financial_reports.php";  // assertLedgerBalanced
require_once "$root/core/gl_accounts.php";        // arAccountId
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

// ── 1. Source contract ───────────────────────────────────────────────────────
section('1. void_payment.php — full unwind wired');
$src = file_get_contents("$root/api/account/void_payment.php");
ok(strpos($src, 'core/money_in_posting.php') !== false, 'includes core/money_in_posting.php');
ok(strpos($src, 'reversePaymentReceived($pdo') !== false, 'reverses the receipt ledger entry');
ok(strpos($src, "status='cancelled'") !== false, "marks the payment cancelled");
ok(strpos($src, 'paid_amount') !== false && strpos($src, 'balance_due') !== false, 'recomputes the invoice balance/status');
ok(strpos($src, 'reverseBankTransaction(') !== false, 'reverses the bank-register deposit');
ok(strpos($src, "canEdit('invoices')") !== false && strpos($src, 'csrf_check()') !== false, 'gated by canEdit + CSRF');
ok(strpos($src, 'beginTransaction') !== false && preg_match('/catch[^{]*\{[^}]*rollBack/s', $src) === 1, 'wrapped in a transaction with rollback');

// ── 2. Runtime — receipt posts, void reverses (rolled back) ──────────────────
section('2. Runtime — receipt posts; void reverses it');
$ar   = (int)arAccountId($pdo);
$bank = (int)$pdo->query("SELECT a.account_id FROM accounts a LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                           WHERE a.status='active' AND a.account_type='asset' AND (st.is_bank=1 OR a.cash_flow_category='cash')
                             AND NOT EXISTS(SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                           ORDER BY a.account_id LIMIT 1")->fetchColumn();
ok($ar > 0 && $bank > 0, "have Accounts Receivable (#$ar) + bank (#$bank)");

$FAKE = 999000444; $amount = 55000.00;
$pdo->beginTransaction();
try {
    $res = postPaymentReceived($pdo, $FAKE, $bank, $amount, date('Y-m-d'), 'PAY-VOID-TST', 'void test', null, 4);
    ok(!empty($res['posted']), 'receipt posted (Dr Bank / Cr AR)');

    $bal = function (int $acc) use ($pdo, $FAKE): float {
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
            FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
            WHERE jei.account_id=? AND je.entity_id=? AND je.entity_type IN ('payment','payment_void')");
        $s->execute([$acc, $FAKE]);
        return round((float)$s->fetchColumn(), 2);
    };
    ok(abs($bal($bank) - $amount) < 0.01, "Bank carries +$amount after receipt");
    ok(abs($bal($ar) + $amount) < 0.01, "AR carries -$amount after receipt");

    $rev = reversePaymentReceived($pdo, $FAKE, 4);   // the core call void_payment.php makes
    ok(!empty($rev['reversed']), 'reversePaymentReceived posted the contra');
    $voidExists = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='payment_void' AND entity_id=$FAKE AND status='posted'")->fetchColumn();
    ok($voidExists === 1, 'a payment_void entry now exists');
    ok(abs($bal($bank)) < 0.01, 'Bank nets to ZERO after void');
    ok(abs($bal($ar)) < 0.01, 'AR nets to ZERO after void');

    $rev2 = reversePaymentReceived($pdo, $FAKE, 4);
    ok(($rev2['reason'] ?? '') === 'already_reversed', 'void is idempotent');

    ok(!empty(assertLedgerBalanced($pdo, date('Y-m-d'))['ledger_balanced']), 'ledger balanced after receipt + void');
} finally {
    $pdo->rollBack();
}
$leak = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$FAKE AND entity_type IN ('payment','payment_void')")->fetchColumn();
ok($leak === 0, 'rolled back cleanly — no test rows persisted');
