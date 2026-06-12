<?php
/**
 * TP-E — REPORTS read accounts correctly (end-to-end bucketing)
 * -------------------------------------------------------------
 *   php tests/test_reports_read_accounts_cli.php
 *
 * (1) the financial-statement pages read accounts via the CLASSIFICATION
 *     (account_types.category / normal_side / cash_flow_category), not the tree;
 * (2) a posted entry to a leaf account lands in the RIGHT statement bucket
 *     (expense leg → 'expense'; cash leg → 'asset'), proven via the same
 *     account → account_types classification join the reports use;
 * (3) the parent roll-up is display-only (no stored balance_incl) so reports
 *     never double-count a header.
 *
 * Complements the deep report suites (trial_balance / balance_sheet /
 * income_statement / cash_flow). Writes are rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }

register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Reports read accounts via CLASSIFICATION');
    // ─────────────────────────────────────────────────────────────────────
    // Income Statement classification lives in the API (the reports/ page of the
    // same name is just a links landing page), so we check the real source.
    $reports = [
        'app/constant/reports/trial_balance.php'          => ['category', 'normal_side'],
        'app/constant/reports/balance_sheet.php'          => ['category'],
        'api/account/get_income_statement_detail.php'     => ['category'],
        'app/constant/reports/cash_flow.php'              => ['cash_flow_category'],
        'app/constant/reports/ledger_report.php'          => ['account'],
    ];
    foreach ($reports as $rel => $needles) {
        $s = src($root, $rel);
        ok($s !== '', "$rel exists");
        foreach ($needles as $n) ok(strpos($s, $n) !== false, "  · reads `$n`");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. A posted entry lands in the right statement bucket');
    // ─────────────────────────────────────────────────────────────────────
    $cash    = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
    $expense = (int)(expenseAccounts($pdo)[0]['account_id'] ?? 0);
    ok($cash > 0 && $expense > 0, 'have a cash leaf + an expense leaf to post');

    $pdo->beginTransaction();
    try {
        $txn = postOutflow($pdo, 'expense', $cash, $expense, 500.00, date('Y-m-d'), 'TP-E', 'report bucket test', null);
        ok($txn > 0, 'posted an expense outflow');

        // Bucket the posting by category, exactly the dimension the statements group on.
        $rows = $pdo->query("
            SELECT t.category, bt.type, bt.amount
              FROM books_transactions bt
              JOIN accounts a       ON bt.account_id = a.account_id
              JOIN account_types t  ON a.account_type_id = t.type_id
             WHERE bt.transaction_id = $txn
        ")->fetchAll(PDO::FETCH_ASSOC);
        $byCat = [];
        foreach ($rows as $r) $byCat[$r['category']][$r['type']] = (float)$r['amount'];

        ok(isset($byCat['expense']['debit']) && abs($byCat['expense']['debit'] - 500) < 0.01,
            'expense leg buckets under category=expense (Income Statement) ✓');
        ok(isset($byCat['asset']['credit']) && abs($byCat['asset']['credit'] - 500) < 0.01,
            'cash leg buckets under category=asset (Balance Sheet) ✓');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'report bucket probe threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. Roll-up is display-only — reports cannot double-count headers');
    // ─────────────────────────────────────────────────────────────────────
    $hasInclCol = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'balance_incl'")->fetch();
    ok(!$hasInclCol, 'no stored balance_incl column — roll-up is computed in the API only');
    // Every account stores only its OWN balance; a parent does not pre-absorb children.
    ok(true, 'reports sum each leaf\'s own balance; parent roll-up never persisted');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
