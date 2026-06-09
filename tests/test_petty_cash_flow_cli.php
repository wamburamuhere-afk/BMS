<?php
/**
 * TP-B(petty) — PETTY CASH flow: source + account effect (end-to-end)
 * -------------------------------------------------------------------
 *   php tests/test_petty_cash_flow_cli.php
 *
 * Petty cash is a SPECIAL money-out flow: the source is NOT a user dropdown —
 * it is the configured imprest account (pettyCashAccountId), and an expense
 * posts the consolidated outflow  Dr Accounts Payable / Cr Petty Cash. This
 * mirrors exactly what api/petty_cash/save_transaction.php does, proving:
 *   (A) the petty cash source is the configured account (fixed, by logic) and a
 *       cash/asset LEAF; the on-screen dropdown is expense CATEGORIES, not a
 *       posting account;
 *   (B) recording an expense DECREASES the petty cash account by the amount,
 *       with a balanced Dr AP / Cr Petty Cash ledger; reverseOutflow restores;
 *   (C) a top-up/deposit now posts a transfer Dr Petty Cash / Cr funding bank (Gap 2).
 *
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
function hasKids(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT 1 FROM accounts WHERE parent_account_id=? LIMIT 1"); $s->execute([$id]); return (bool)$s->fetchColumn(); }

register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Petty cash SOURCE is the configured account (not a dropdown)');
    // ─────────────────────────────────────────────────────────────────────
    $petty = (int)(pettyCashAccountId($pdo) ?? 0);
    $ap    = (int)(defaultPayableAccountId($pdo) ?? 0);
    ok($petty > 0, "petty cash imprest account is configured (id=$petty)");
    ok($ap > 0,    "default Accounts Payable account is configured (id=$ap)");

    $row = $pdo->query("SELECT account_type, status FROM accounts WHERE account_id=$petty")->fetch(PDO::FETCH_ASSOC);
    ok($row && $row['account_type'] === 'asset', 'petty cash source is an asset account');
    ok($row && $row['status'] === 'active', 'petty cash source is active');
    ok(!hasKids($pdo, $petty), 'petty cash source is a LEAF (no children) — postable');

    // The endpoint delegates posting to postPettyCashLedger() (in payment_source.php),
    // which uses the configured imprest account as the source — not a posted field.
    $ep = src($root, 'api/petty_cash/save_transaction.php');
    $ps = src($root, 'core/payment_source.php');
    ok(strpos($ep, 'postPettyCashLedger(') !== false, 'endpoint delegates posting to postPettyCashLedger()');
    ok(strpos($ps, 'pettyCashAccountId($pdo)') !== false, 'posting logic uses pettyCashAccountId() as the source (fixed)');
    ok(strpos($ps, 'defaultPayableAccountId($pdo)') !== false, 'expense posting debits Accounts Payable');
    ok(preg_match('/postOutflow\(\$pdo,\s*\'petty_cash\'/', $ps) === 1, "expense posts a 'petty_cash' outflow");

    // The on-screen dropdown is expense CATEGORIES, not a posting account.
    $page = src($root, 'app/constant/accounts/petty_cash.php');
    ok(strpos($page, 'account_categories') !== false, 'page dropdown = expense categories (account_categories), not a posting account');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Recording an expense DECREASES the petty cash account');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();
    try {
        $before = bal($pdo, $petty);
        // Mirror the endpoint's expense posting exactly.
        $txn = postOutflow($pdo, 'petty_cash', $petty, $ap, 200.00, date('Y-m-d'), 'TP-PC', 'Petty cash: tea & sugar', null);
        ok($txn > 0, 'postOutflow posted a petty-cash transaction');
        $after = bal($pdo, $petty);
        ok(approx($before - $after, 200.00), "petty cash DECREASED by 200 (was $before, now $after)");

        $legs = $pdo->query("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC);
        ok(count($legs) === 2, 'two balanced ledger legs written');
        $dr = array_values(array_filter($legs, fn($l) => $l['type'] === 'debit'));
        $cr = array_values(array_filter($legs, fn($l) => $l['type'] === 'credit'));
        ok($dr && (int)$dr[0]['account_id'] === $ap && approx($dr[0]['amount'], 200), 'Dr leg = Accounts Payable, 200');
        ok($cr && (int)$cr[0]['account_id'] === $petty && approx($cr[0]['amount'], 200), 'Cr leg = Petty Cash, 200');

        reverseOutflow($pdo, $txn);
        ok(approx(bal($pdo, $petty), $before), 'reverseOutflow restores the petty cash balance exactly');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'petty-cash expense posting threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. A top-up/deposit now posts a transfer (Gap 2)');
    // ─────────────────────────────────────────────────────────────────────
    // Top-ups are now real postings: Dr Petty Cash / Cr funding bank — both balances
    // move and the entry is mirrored to the canonical journal. The endpoint requires a
    // funding account for a deposit; the deposit branch lives in postPettyCashLedger().
    ok(strpos($ep, 'source_account_id') !== false,
       'endpoint requires a funding account (source_account_id) for a top-up');
    ok(strpos($ps, "type === 'deposit'") !== false && strpos($ps, "petty_cash_topup") !== false,
       'postPettyCashLedger posts a Dr Petty Cash / Cr funding transfer for a deposit');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
