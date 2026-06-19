<?php
/**
 * tests/test_voucher_payment_money_safety_cli.php
 *   php tests/test_voucher_payment_money_safety_cli.php
 *
 * Step 5 — record_voucher_payment.php (money OUT): the ledger post is now loud
 * (postOutflowOrFail), the whole payment is wrapped in ONE transaction, and the
 * I3 "warn but allow" funds note is surfaced (never blocks).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/money_guard.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$h = file_get_contents("$root/api/account/record_voucher_payment.php");

// ── 1. Lint ──────────────────────────────────────────────────────────────────
section('1. File lint-clean');
$out = shell_exec('php -l ' . escapeshellarg("$root/api/account/record_voucher_payment.php") . ' 2>&1');
(strpos((string)$out, 'No syntax errors') !== false) ? pass('record_voucher_payment.php lint-clean') : fail($out);

// ── 2. Loud posting (no fire-and-forget) ─────────────────────────────────────
section('2. Posting is loud, not fire-and-forget');
(strpos($h, 'core/money_guard.php') !== false) ? pass('includes money_guard foundation') : fail('money_guard.php not included');
(preg_match('/=\s*postOutflowOrFail\s*\(/', $h) === 1) ? pass('uses postOutflowOrFail() (throws the real reason)') : fail('not using postOutflowOrFail()');
(preg_match('/=\s*postOutflow\s*\(/', $h) === 0) ? pass('the old unchecked postOutflow() call is gone') : fail('a bare postOutflow() result is still ignored');

// ── 3. Atomic — one transaction wraps every write ────────────────────────────
section('3. All-or-nothing transaction');
(strpos($h, '$pdo->beginTransaction()') !== false) ? pass('opens a transaction') : fail('no beginTransaction()');
(strpos($h, '$pdo->commit()') !== false)           ? pass('commits on success')   : fail('no commit()');
(preg_match('/catch\s*\([^)]*\)\s*\{[^}]*inTransaction\(\)[^}]*rollBack\(\)/s', $h) === 1)
    ? pass('catch rolls back a half-written payment') : fail('catch does not roll back');

// ── 4. I3 "warn but allow" funds note ────────────────────────────────────────
section('4. Funds warning (warn but allow — never blocks)');
(strpos($h, 'accountFundsWarning(') !== false) ? pass('computes a funds warning') : fail('no funds warning computed');
(strpos($h, "'funds_warning'") !== false)      ? pass('surfaces funds_warning in the response') : fail('funds_warning not returned');
// Must NOT hard-block on low funds.
(stripos($h, 'Insufficient balance') === false && stripos($h, 'throw') !== false)
    ? pass('does not hard-block on low funds (warn but allow)') : fail('appears to block on low funds');

// ── 5. Account still mandatory (unchanged guarantee) ─────────────────────────
section('5. Account still mandatory');
(strpos($h, 'paid from (Paid From)') !== false || strpos($h, 'Paid From') !== false)
    ? pass('still requires the Paid-From account up front') : fail('lost the Paid-From requirement');
