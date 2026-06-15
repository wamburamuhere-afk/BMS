<?php
/**
 * Oversized junk-voucher reversal + Income-Statement margin guard — CLI test
 *   php tests/test_junk_voucher_reversal_cli.php
 *
 * Guards two related fixes for the "OPERATING PROFIT (EBIT) -176,467,440.3% of revenue" bug:
 *   #1 migrations/2026_06_15_reverse_oversized_junk_vouchers.php — reverses payment vouchers
 *      whose amount is implausibly large (data-entry junk) via a balanced contra entry,
 *      criteria-based on a sanity ceiling (no hard-coded ids), idempotent, balance-guarded.
 *   #2 app/bms/invoice/income_statement.php — margin labels show "(n/m)" when the
 *      "% of revenue" ratio is not meaningful (near-zero revenue or absurd magnitude).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle, string $label): void { strpos($hay,$needle)!==false ? pass($label) : fail("$label — missing"); }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

$CEIL = 100000000000; // must match JUNK_VOUCHER_CEILING

section('1. Files lint clean');
foreach (['migrations/2026_06_15_reverse_oversized_junk_vouchers.php','app/bms/invoice/income_statement.php'] as $f) {
    $rc=0;$o=[]; exec('php -l '.escapeshellarg("$root/$f").' 2>&1',$o,$rc);
    $rc===0 ? pass("$f lints clean") : fail("php -l failed: $f");
}

section('2. Migration — criteria-based, idempotent, balanced contra');
$mig = file_get_contents("$root/migrations/2026_06_15_reverse_oversized_junk_vouchers.php");
has($mig, 'JUNK_VOUCHER_CEILING', 'uses a named sanity-ceiling constant');
has($mig, "100000000000", 'ceiling is 100 billion TZS');
has($mig, "entity_type='oversized_voucher_reversal'", 'idempotency key on the reversal entry');
has($mig, "pv.amount >=", 'detects by voucher amount (criteria, not id)');
has($mig, "'cancelled'", 'cancels the junk voucher to match the reversed GL');
has($mig, 'assertLedgerBalanced', 'runs the balance guardrail');
has($mig, "? 'credit' : 'debit'", 'posts the exact inverse of every line (balanced contra)');
(preg_match('/voucher_number\s*=\s*[\'"]PV-/', $mig) === 0) ? pass('no hard-coded voucher number') : fail('hard-coded voucher number present');

section('3. Income-Statement margin guard (n/m for non-meaningful ratios)');
$tpl = file_get_contents("$root/app/bms/invoice/income_statement.php");
has($tpl, 'marginLabel', 'has a marginLabel() helper');
has($tpl, "'(n/m)'", 'renders "(n/m)" when not meaningful');
has($tpl, 'Math.abs(p) > 1000', 'treats |margin| > 1000% as not meaningful');
has($tpl, 'rev < 1', 'treats near-zero revenue as not meaningful');

section('4. Runtime invariant — no oversized junk left un-reversed');
// Every oversized posted voucher-mirror entry must carry a matching reversal.
$unreversed = (int)$pdo->query("
    SELECT COUNT(*) FROM journal_entries je
     WHERE je.status='posted' AND je.amount >= $CEIL AND je.entity_type='books_transaction'
       AND NOT EXISTS (SELECT 1 FROM journal_entries r
                        WHERE r.entity_type='oversized_voucher_reversal'
                          AND r.entity_id = je.entry_id AND r.status='posted')
")->fetchColumn();
$unreversed === 0 ? pass('no oversized voucher GL entry left un-reversed') : fail("$unreversed oversized entr(ies) un-reversed");

// No oversized voucher should remain in a live (paid/approved) state.
$activeJunk = (int)$pdo->query("SELECT COUNT(*) FROM payment_vouchers WHERE amount >= $CEIL AND status IN ('paid','approved')")->fetchColumn();
$activeJunk === 0 ? pass('no oversized voucher left paid/approved (all cancelled)') : fail("$activeJunk oversized voucher(s) still live");

// The ledger still balances after the reversal.
$g = assertLedgerBalanced($pdo);
(($g['ledger_balanced'] ?? false)) ? pass('ledger balanced') : fail('ledger out of balance');

section('5. Sanity ceiling cleanly separates junk from legitimate data');
$maxLegit = (float)$pdo->query("SELECT COALESCE(MAX(amount),0) FROM journal_entries WHERE status='posted' AND amount < $CEIL")->fetchColumn();
($maxLegit < $CEIL) ? pass('largest legit entry ('.number_format($maxLegit,0).') is below the ceiling') : fail('legit entry exceeds ceiling — ceiling too low');
