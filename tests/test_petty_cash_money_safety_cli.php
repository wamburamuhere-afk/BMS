<?php
/**
 * tests/test_petty_cash_money_safety_cli.php
 *   php tests/test_petty_cash_money_safety_cli.php
 *
 * Step 9 — petty_cash/save_transaction.php (money OUT, the messiest handler):
 * now wrapped in ONE transaction, the ledger post is checked and FAILS LOUDLY,
 * the catch rolls back, and the I3 funds note is surfaced (never blocks).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$h = file_get_contents("$root/api/petty_cash/save_transaction.php");

section('1. File lint-clean');
$out = shell_exec('php -l ' . escapeshellarg("$root/api/petty_cash/save_transaction.php") . ' 2>&1');
(strpos((string)$out, 'No syntax errors') !== false) ? pass('save_transaction.php lint-clean') : fail($out);

section('2. Atomic — one transaction wraps record + posting (handler had none)');
(strpos($h, '$pdo->beginTransaction()') !== false) ? pass('opens a transaction') : fail('no beginTransaction()');
(strpos($h, '$pdo->commit()') !== false)           ? pass('commits on success')   : fail('no commit()');
(preg_match('/catch[^{]*\{[^}]*inTransaction\(\)[^}]*rollBack\(\)/s', $h) === 1)
    ? pass('catch rolls back a half-written entry') : fail('catch does not roll back');

section('3. Both branches FAIL LOUDLY on a null post (no silent save)');
$loudPosts = preg_match_all('/if\s*\(\s*!\s*\$petty_txn\s*\)\s*\{\s*throw new Exception/', $h);
($loudPosts >= 2) ? pass("both UPDATE and INSERT branches throw when the post is null ($loudPosts found)") : fail("a branch still ignores the post result ($loudPosts loud checks)");
// The old silent "if ($petty_txn) { store id }" must be gone.
(preg_match('/if\s*\(\s*\$petty_txn\s*\)\s*\{/', $h) === 0) ? pass('old silent "if ($petty_txn)" guard removed') : fail('an old silent guard remains');

section('4. Fund resolved up front with a clear error');
(strpos($h, 'No petty cash fund is configured') !== false)
    ? pass('missing fund throws a clear, specific error') : fail('no clear error for a missing fund');

section('5. I3 funds note (warn but allow)');
(strpos($h, 'accountFundsWarning(') !== false) ? pass('computes a funds warning') : fail('no funds warning computed');
(strpos($h, "'funds_warning'") !== false) ? pass('surfaces funds_warning in the response') : fail('funds_warning not returned');
(strpos($h, 'core/money_guard.php') !== false) ? pass('includes the money_guard foundation') : fail('money_guard.php not included');
