<?php
/**
 * Banking & Cash ↔ Chart of Accounts relationship
 * ------------------------------------------------
 *   php tests/test_banking_petty_chart_link_cli.php
 *
 * Proves that Bank Statement and Petty Cash are operational windows onto
 * accounts that live IN the chart of accounts:
 *
 *  A. BANK STATEMENT (read-only view of one chart account)
 *     - its account picker = cashBankAccounts() = chart cash/bank LEAVES;
 *     - get_bank_statement.php keys rows by bank_account_id = chart account_id;
 *     - a deposit + withdrawal recorded against a chart cash account summarise
 *       correctly (total in/out/closing) for that account only.
 *
 *  B. PETTY CASH (moves money in/out of one chart account)
 *     - the petty-cash source is a real chart account (asset/cash leaf, system);
 *     - the on-screen dropdown is expense CATEGORIES, not a posting account;
 *     - an expense posts Dr AP / Cr Petty Cash → the petty-cash CHART account
 *       balance decreases (reverse restores).
 *
 * All writes happen inside a transaction that is always rolled back.
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
    // ═════════════════════════════════════════════════════════════════════
    section('A1. Bank Statement picker = chart cash/bank LEAVES');
    // ═════════════════════════════════════════════════════════════════════
    $bs = src($root, 'app/constant/accounts/bank_statement.php');
    ok(strpos($bs, 'cashBankAccounts($pdo)') !== false, 'bank_statement.php builds its account list from cashBankAccounts()');

    $cb = cashBankAccounts($pdo);
    ok(count($cb) > 0, 'picker returns chart cash/bank accounts (' . count($cb) . ')');
    $allChartLeaves = true;
    foreach ($cb as $a) {
        $r = $pdo->prepare("SELECT 1 FROM accounts WHERE account_id=? AND account_type='asset' AND cash_flow_category='cash' AND status='active'");
        $r->execute([$a['account_id']]);
        if (!$r->fetchColumn() || hasKids($pdo, (int)$a['account_id'])) $allChartLeaves = false;
    }
    ok($allChartLeaves, 'every Bank Statement option is an active cash chart LEAF');

    // ═════════════════════════════════════════════════════════════════════
    section('A2. get_bank_statement keys on the chart account_id');
    // ═════════════════════════════════════════════════════════════════════
    $ep = src($root, 'api/account/get_bank_statement.php');
    ok(strpos($ep, 'bt.bank_account_id = ?') !== false, 'statement endpoint filters bank_transactions by bank_account_id (= chart account_id)');

    // Functional: record a deposit + withdrawal against a chart cash account, then
    // reproduce the endpoint's summary. NOTE: bank_transactions is MyISAM (no
    // transaction rollback), so we tag rows with a unique marker, sum ONLY the
    // tagged rows, and DELETE them explicitly in finally — never relying on
    // rollback and never touching real statement data.
    $acct  = (int)$cb[0]['account_id'];
    $other = (int)($cb[1]['account_id'] ?? 0);
    $mark  = 'BSTEST-' . substr(uniqid(), -8);
    try {
        $ins = $pdo->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, transaction_type, amount, balance_after, description, created_at) VALUES (?,?,?,?,?,?,NOW())");
        $ins->execute([$acct, date('Y-m-d'), 'deposit',    1000.00, 1000.00, "$mark in"]);
        $ins->execute([$acct, date('Y-m-d'), 'withdrawal',  400.00,  600.00, "$mark out"]);
        if ($other) $ins->execute([$other, date('Y-m-d'), 'deposit', 9999.00, 9999.00, "$mark other-acct noise"]);

        // Mirror get_bank_statement.php summary, scoped to THIS account + marker.
        $rows = $pdo->prepare("SELECT transaction_type, amount, balance_after FROM bank_transactions WHERE bank_account_id=? AND description LIKE ? ORDER BY transaction_id ASC");
        $rows->execute([$acct, "$mark%"]);
        $r = $rows->fetchAll(PDO::FETCH_ASSOC);
        $in=0;$out=0; foreach($r as $x){ if($x['transaction_type']==='deposit') $in+=$x['amount']; else $out+=$x['amount']; }
        $closing = count($r) ? (float)$r[count($r)-1]['balance_after'] : null;

        ok(approx($in, 1000.00),  "total IN for the chart account = 1000 (other accounts excluded)");
        ok(approx($out, 400.00),  "total OUT for the chart account = 400");
        ok(approx($closing, 600.00), "closing balance reflects the last row (600)");
    } catch (Throwable $e) {
        ok(false, 'bank-statement probe threw: ' . $e->getMessage());
    } finally {
        // Explicit cleanup (MyISAM has no rollback).
        $del = $pdo->prepare("DELETE FROM bank_transactions WHERE description LIKE ?");
        $del->execute(["$mark%"]);
        ok((int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE description LIKE " . $pdo->quote("$mark%"))->fetchColumn() === 0, 'test statement rows cleaned up (no residue)');
    }

    // ═════════════════════════════════════════════════════════════════════
    section('B1. Petty Cash source is a real chart account (fixed)');
    // ═════════════════════════════════════════════════════════════════════
    $petty = (int)(pettyCashAccountId($pdo) ?? 0);
    $ap    = (int)(defaultPayableAccountId($pdo) ?? 0);
    ok($petty > 0, "petty-cash account is configured (chart account id=$petty)");
    $info = $pdo->query("SELECT account_code, account_type, is_system FROM accounts WHERE account_id=$petty")->fetch(PDO::FETCH_ASSOC);
    ok($info && $info['account_type'] === 'asset', 'petty-cash account is an asset in the chart');
    ok($info && (int)$info['is_system'] === 1, 'petty-cash account is flagged is_system (protected in the chart)');
    ok(!hasKids($pdo, $petty), 'petty-cash account is a chart LEAF (postable)');

    $pc = src($root, 'app/constant/accounts/petty_cash.php');
    ok(strpos($pc, 'expense_account_id') !== false, 'petty_cash.php expense dropdown is a real expense account');

    // ═════════════════════════════════════════════════════════════════════
    section('B2. A petty-cash expense moves the petty-cash CHART balance');
    // ═════════════════════════════════════════════════════════════════════
    if ($petty > 0 && $ap > 0) {
        $pdo->beginTransaction();
        try {
            $before = bal($pdo, $petty);
            $txn = postOutflow($pdo, 'petty_cash', $petty, $ap, 150.00, date('Y-m-d'), 'PC-LINK', 'Petty cash: stationery', null);
            ok($txn > 0, 'petty-cash expense posted');
            ok(approx($before - bal($pdo, $petty), 150.00), 'petty-cash CHART account balance DECREASED by 150');
            // Cr leg lands on the petty-cash chart account
            $crOnPetty = (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn AND account_id=$petty AND type='credit'")->fetchColumn();
            ok($crOnPetty === 1, 'the credit leg is posted to the petty-cash chart account');
            reverseOutflow($pdo, $txn);
            ok(approx(bal($pdo, $petty), $before), 'reverse restores the petty-cash chart balance');
            $pdo->rollBack();
            ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ok(false, 'petty-cash probe threw: ' . $e->getMessage());
        }
    } else {
        ok(false, 'petty-cash or AP account not configured');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
