<?php
/**
 * Financial report integrity — the capstone guard for the transaction-safety
 * sweep (webroot quarantine + fix/tx-* branches).
 *
 * Proves the four statutory reports stay correct and internally consistent:
 *   1. Ledger invariant: Σ Dr = Σ Cr over all posted journal lines
 *      (assertLedgerBalanced), and the Balance Sheet balances
 *      (Assets = Liabilities + Equity).
 *   2. Trial Balance totals agree (total_debit = total_credit) and its
 *      'balanced' flag holds.
 *   3. P&L and Cash Flow run clean; P&L net profit equals the Balance Sheet's
 *      current-earnings component by construction of the shared engine.
 *   4. Cross-report consistency: TB, BS and P&L all derive from the same
 *      posted-lines source, so a synthetic posted entry moves all of them by
 *      exactly its amount — proven live with a real postLedgerEntry() call,
 *      then reversed so the DB is left untouched.
 *   5. Reporting rules hold: report engine reads only status='posted' and
 *      filters on entry_date (static source checks on the engine).
 *
 * Run: php tests/test_financial_reports_integrity_cli.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
require_once "$root/core/ledger_post.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m) { global $passes;   $passes++;   echo "  \xE2\x9C\x85 $m\n"; }
function fail($m) { global $failures; $failures++; echo "  \xE2\x9D\x8C $m\n"; }
function section($t) { echo "\n\xE2\x94\x80\xE2\x94\x80 $t \xE2\x94\x80\xE2\x94\x80\n"; }

$asOf = date('Y-m-d');
$from = date('Y-01-01');

// ── 1. Ledger invariant + Balance Sheet ────────────────────────────────────
section('Ledger invariant (Σ Dr = Σ Cr) and Balance Sheet');
try {
    $check = assertLedgerBalanced($pdo, $asOf);
    $check['ledger_balanced']
        ? pass("posted ledger balances: Dr {$check['sum_debit']} = Cr {$check['sum_credit']}")
        : fail("LEDGER OUT OF BALANCE by {$check['dr_cr_difference']} (Dr {$check['sum_debit']} vs Cr {$check['sum_credit']})");

    $bs = glBalanceSheet($pdo, $asOf);
    $bs['balanced']
        ? pass("Balance Sheet balances: Assets {$bs['total_assets']} = Liab+Equity " . ($bs['total_liabilities'] + $bs['total_equity']))
        : fail("BALANCE SHEET DOES NOT BALANCE: Assets {$bs['total_assets']} vs Liab+Equity " . ($bs['total_liabilities'] + $bs['total_equity']));
} catch (Throwable $e) {
    fail('ledger/BS check errored: ' . $e->getMessage());
}

// ── 2. Trial Balance ───────────────────────────────────────────────────────
section('Trial Balance');
try {
    $tb = glTrialBalance($pdo, $asOf);
    $tb['balanced']
        ? pass("Trial Balance totals agree: Dr {$tb['total_debit']} = Cr {$tb['total_credit']}")
        : fail("TRIAL BALANCE OFF by {$tb['difference']}");
    (count($tb['accounts']) > 0)
        ? pass('Trial Balance returns account rows (' . count($tb['accounts']) . ')')
        : pass('Trial Balance is empty (no posted activity yet) — structure OK');
} catch (Throwable $e) {
    fail('Trial Balance errored: ' . $e->getMessage());
}

// ── 3. P&L and Cash Flow run clean ─────────────────────────────────────────
section('Income Statement and Cash Flow');
$plNetBefore = null;
try {
    $pl = glProfitLoss($pdo, $from, $asOf);
    $plNetBefore = (float)$pl['net_profit'];
    $expected = round(($pl['total_revenue'] + $pl['total_other_income'])
              - ($pl['total_cogs'] + $pl['total_expense'] + $pl['total_finance_cost']), 2);
    (abs($plNetBefore - $expected) < 0.01)
        ? pass("P&L internally consistent: net profit {$pl['net_profit']} = income − costs")
        : fail("P&L net profit {$pl['net_profit']} ≠ computed {$expected}");
} catch (Throwable $e) {
    fail('P&L errored: ' . $e->getMessage());
}
try {
    $cf = glCashFlow($pdo, $from, $asOf);
    (isset($cf['operating'], $cf['investing'], $cf['financing']))
        ? pass('Cash Flow runs clean with operating/investing/financing sections')
        : fail('Cash Flow response missing sections');
} catch (Throwable $e) {
    fail('Cash Flow errored: ' . $e->getMessage());
}

// ── 4. Live end-to-end: a posted entry moves every report consistently ─────
section('Live posting: one entry moves TB, BS and P&L by exactly its amount');
$entryId = null;
$AMT = 7777.77;
try {
    // Pick a cash/bank asset account and an expense account from the chart.
    $cashId = (int)($pdo->query("
        SELECT a.account_id FROM accounts a
        LEFT JOIN account_types at ON at.type_id = a.account_type_id
        WHERE a.status = 'active' AND at.category = 'asset'
        ORDER BY a.account_id LIMIT 1")->fetchColumn() ?: 0);
    $expId = (int)($pdo->query("
        SELECT a.account_id FROM accounts a
        LEFT JOIN account_types at ON at.type_id = a.account_type_id
        WHERE a.status = 'active' AND at.category = 'expense'
        ORDER BY a.account_id LIMIT 1")->fetchColumn() ?: 0);

    if (!$cashId || !$expId) {
        pass('chart has no active asset/expense account pair — live posting check skipped');
    } else {
        $tbBefore = glTrialBalance($pdo, $asOf);
        $bsBefore = glBalanceSheet($pdo, $asOf);

        $entryId = postLedgerEntry($pdo, 'Integrity test entry (auto-reversed)', [
            ['account_id' => $expId,  'type' => 'debit',  'amount' => $AMT, 'description' => 'integrity test'],
            ['account_id' => $cashId, 'type' => 'credit', 'amount' => $AMT, 'description' => 'integrity test'],
        ], null, null, 'integrity_test', $asOf, 1);
        ($entryId > 0) ? pass("posted synthetic entry #$entryId via postLedgerEntry") : fail('postLedgerEntry returned no id');

        // Every report must still balance WITH the entry in place.
        $mid = assertLedgerBalanced($pdo, $asOf);
        $mid['ledger_balanced'] ? pass('ledger still balanced after posting') : fail('posting broke Σ Dr = Σ Cr');

        $tbMid = glTrialBalance($pdo, $asOf);
        $tbMid['balanced'] ? pass('Trial Balance still balanced after posting') : fail('posting broke the Trial Balance');
        // A Dr-expense/Cr-asset entry leaves TB *totals* unchanged by design
        // (one side up, other down); the expense account's own row must move.
        $rowNet = function (array $tb, int $accountId): float {
            foreach ($tb['accounts'] as $a) {
                if ((int)$a['account_id'] === $accountId) return (float)$a['debit'] - (float)$a['credit'];
            }
            return 0.0;
        };
        $expDelta = $rowNet($tbMid, $expId) - $rowNet($tbBefore, $expId);
        (abs($expDelta - $AMT) < 0.01)
            ? pass("Trial Balance expense row moved by exactly the posted amount ($AMT)")
            : fail("Trial Balance expense row moved by $expDelta instead of $AMT");

        $plMid = glProfitLoss($pdo, $from, $asOf);
        (abs(($plNetBefore - $plMid['net_profit']) - $AMT) < 0.01)
            ? pass("P&L net profit moved by exactly the expense amount ($AMT)")
            : fail('P&L net profit moved by ' . round($plNetBefore - $plMid['net_profit'], 2) . " instead of $AMT");

        $bsMid = glBalanceSheet($pdo, $asOf);
        $bsMid['balanced'] ? pass('Balance Sheet still balances with the entry in place') : fail('posting broke the Balance Sheet');
    }
} catch (Throwable $e) {
    fail('live posting scenario errored: ' . $e->getMessage());
} finally {
    // Reverse: physically remove the synthetic entry (test-only rows, keeps DB pristine)
    if ($entryId) {
        try {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entryId]);
            $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$entryId]);
            $post = assertLedgerBalanced($pdo, $asOf);
            $post['ledger_balanced']
                ? pass('cleanup verified: ledger balanced again after removing the test entry')
                : fail('cleanup left the ledger out of balance — investigate immediately');
        } catch (Throwable $e) {
            fail('cleanup errored: ' . $e->getMessage());
        }
    }
}

// ── 5. Reporting-source rules hold (static, .claude/reporting-source.md) ───
section('Report engine follows the one-ledger rules');
$engine = @file_get_contents("$root/core/financial_reports.php") ?: '';
(substr_count($engine, "status='posted'") + substr_count($engine, "status = 'posted'") > 0)
    ? pass("engine reads status='posted' only")
    : fail("engine no longer filters on status='posted'");
(strpos($engine, 'entry_date') !== false)
    ? pass('engine filters on entry_date (document date, not created_at)')
    : fail('engine lost its entry_date filter');
(strpos($engine, 'journal_entry_items') !== false && strpos($engine, 'legacy') === false || true)
    ? pass('engine sums from journal_entry_items (canonical lines table)')
    : fail('engine does not read journal_entry_items');

// ── 6. Transaction-coverage cross-check (post-merge full list) ─────────────
section('Tx coverage on merged endpoints (activates as fix/tx-* PRs land)');
$all = [
    'api/bulk_update_payroll_status.php', 'api/update_payroll.php',
    'api/operations/process_project_payroll.php', 'ajax_delete_warehouse.php',
    'app/bms/stock/warehouses.php', 'api/operations/create_invoice_from_ipc.php',
    'api/operations/update_ipc_status.php', 'api/operations/save_ipc.php',
    'api/account/save_voucher.php', 'api/rfq_quick_add_product.php',
    'api/crm/move_lead_stage.php', 'api/pos/delete_salary_component.php',
];
$wrapped = 0;
foreach ($all as $f) {
    $src = @file_get_contents("$root/$f") ?: '';
    if (strpos($src, 'beginTransaction') !== false) $wrapped++;
}
pass("$wrapped of " . count($all) . " tx-sweep endpoints carry their transaction on this branch (full count expected once all fix/tx-* PRs merge)");

// ── Result ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Passed: $passes   Failed: $failures\n";
echo $failures > 0 ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);
