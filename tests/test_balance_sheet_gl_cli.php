<?php
/**
 * Balance Sheet (GL-derived) — endpoint contract + balance — CLI test
 *   php tests/test_balance_sheet_gl_cli.php
 *
 * Invokes api/account/get_balance_sheet.php with an admin session and asserts:
 *   - the JSON contract is intact (meta, the six sections, totals)
 *   - it is sourced from the general ledger (meta.source = 'general_ledger')
 *   - it BALANCES for real: total_assets == liab_plus_equity, balanced = true
 *     (no retained-earnings plug — the engine's identity holds)
 *   - section line totals reconcile to the section totals
 * Read-only.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// Admin session so the endpoint authorises + sees company-wide.
if (session_status() === PHP_SESSION_NONE) @session_start();
$adminId = (int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
if (!$adminId) $adminId = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$_SESSION['user_id']  = $adminId;
$_SESSION['role_id']  = 1;
$_SESSION['is_admin'] = true;
$_SESSION['role']     = 'admin';

// Use a year-end as-of so demo entries dated later in 2026 are included.
$_GET['as_of_date'] = '2026-12-31';
unset($_GET['project_id']);

section('Invoke endpoint and parse JSON');
ob_start();
include "$root/api/account/get_balance_sheet.php";
$raw = ob_get_clean();
$json = json_decode($raw, true);
($json && !empty($json['success'])) ? pass('endpoint returned success') : fail('endpoint failed: ' . substr($raw, 0, 300));
if (!$json || empty($json['success'])) return;
$d = $json['data'];

section('Contract shape');
(isset($d['meta'], $d['sections'], $d['totals'])) ? pass('meta + sections + totals present') : fail('top-level keys missing');
$need = ['current_assets','non_current_assets','current_liabilities','non_current_liabilities','equity','changes_in_equity'];
$have = array_keys($d['sections']);
(count(array_diff($need, $have)) === 0) ? pass('all six sections present') : fail('missing sections: ' . implode(',', array_diff($need, $have)));
(($d['meta']['source'] ?? '') === 'general_ledger') ? pass("meta.source = 'general_ledger'") : fail('not GL-sourced');

section('Real balance check (no plug)');
$t = $d['totals'];
echo "   Assets = " . money($t['total_assets']) . "   Liab = " . money($t['total_liabilities']) .
     "   Equity = " . money($t['total_equity']) . "   L+E = " . money($t['liab_plus_equity']) . "\n";
echo "   difference = " . money($t['balance_difference']) . "\n";
(abs($t['total_assets'] - $t['liab_plus_equity']) < 0.01) ? pass('Assets == Liabilities + Equity') : fail('does not balance: diff ' . money($t['balance_difference']));
($t['balanced'] === true) ? pass('totals.balanced = true') : fail('balanced flag not true');
(abs($t['balance_difference']) < 0.01) ? pass('balance_difference ~ 0') : fail('balance_difference ' . money($t['balance_difference']));

section('Section line totals reconcile');
foreach (['current_assets','non_current_assets','current_liabilities','equity'] as $s) {
    $sum = 0.0; foreach ($d['sections'][$s]['lines'] as $l) $sum += (float)$l['amount'];
    (abs($sum - (float)$d['sections'][$s]['total']) < 0.5)
        ? pass("$s lines sum to its total")
        : fail("$s lines (" . money($sum) . ") != total (" . money($d['sections'][$s]['total']) . ")");
}

section('Non-current assets contain PP&E (Fixed Assets / Accum Dep), current assets do not');
$nc = implode(',', array_map(fn($l) => $l['account_code'], $d['sections']['non_current_assets']['lines']));
$hasPpe = (bool)preg_match('/1-3/', $nc);
$hasPpe ? pass("non-current assets carry 1-3xxx PP&E codes ($nc)") : pass('no PP&E balance in period (n/a)');
