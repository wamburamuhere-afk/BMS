<?php
/**
 * TP-C — CASH TRANSFER flow: dropdowns + account effect (end-to-end)
 * ------------------------------------------------------------------
 *   php tests/test_cash_transfer_flows_cli.php
 *
 * (A) the From / To pickers offer cash/bank leaves, the Charge picker offers
 *     expense leaves, and
 * (B) a transfer moves money From → To: the From account DECREASES and the To
 *     account INCREASES by the same amount (sum of the two unchanged).
 *
 * Uses the same balance engine (applyAccountBalanceDelta) the transfer pages use.
 * All writes inside a transaction that is always rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b){ return abs((float)$a - (float)$b) < 0.01; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
function bal(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT current_balance FROM accounts WHERE account_id=?"); $s->execute([$id]); return (float)$s->fetchColumn(); }

register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. From / To / Charge pickers');
    // ─────────────────────────────────────────────────────────────────────
    $cb = cashBankAccounts($pdo);
    ok(count($cb) >= 2, 'at least two cash/bank accounts to transfer between (' . count($cb) . ')');
    $exp = expenseAccounts($pdo);
    ok(count($exp) > 0, 'charge (fee) picker = expense leaves (' . count($exp) . ')');
    $bt = src($root, 'app/constant/accounts/bank_transfers.php');
    ok(strpos($bt, 'cashBankAccounts($pdo)') !== false, 'bank_transfers.php From/To = cashBankAccounts()');
    ok(strpos($bt, 'expenseAccounts($pdo)') !== false, 'bank_transfers.php Charge = expenseAccounts()');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Transfer moves money From → To (sum preserved)');
    // ─────────────────────────────────────────────────────────────────────
    $from = (int)$cb[0]['account_id'];
    $to   = (int)$cb[1]['account_id'];
    ok($from !== $to, 'From and To are different accounts');

    $pdo->beginTransaction();
    try {
        $fBefore = bal($pdo, $from);
        $tBefore = bal($pdo, $to);
        $sumBefore = $fBefore + $tBefore;

        // The transfer: money leaves From (credit a debit-normal asset) and enters To (debit).
        applyAccountBalanceDelta($pdo, $from, 'credit', 500.00);
        applyAccountBalanceDelta($pdo, $to,   'debit',  500.00);

        $fAfter = bal($pdo, $from);
        $tAfter = bal($pdo, $to);
        ok(approx($fBefore - $fAfter, 500.00), "From DECREASED by 500 (was $fBefore, now $fAfter)");
        ok(approx($tAfter - $tBefore, 500.00), "To INCREASED by 500 (was $tBefore, now $tAfter)");
        ok(approx($fAfter + $tAfter, $sumBefore), 'combined cash unchanged (a transfer creates/destroys no money)');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'transfer probe threw: ' . $e->getMessage());
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
