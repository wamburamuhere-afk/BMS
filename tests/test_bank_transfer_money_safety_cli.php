<?php
/**
 * tests/test_bank_transfer_money_safety_cli.php
 *   php tests/test_bank_transfer_money_safety_cli.php
 *
 * Step 10 — bank transfer adopts the I3 "warn but allow" funds policy: it must NO
 * LONGER hard-block a transfer when the source is short; it warns and proceeds,
 * consistent with every other money-out flow.
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

$add  = file_get_contents("$root/api/account/add_bank_transfer.php");
$post = file_get_contents("$root/api/account/update_bank_transfer_status.php");

section('1. Files lint-clean');
foreach (['api/account/add_bank_transfer.php', 'api/account/update_bank_transfer_status.php'] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? pass("$f lint-clean") : fail("$f: $out");
}

section('2. Create step: short funds WARNS, does not block');
(strpos($add, "throw new Exception('Insufficient balance") === false)
    ? pass('add_bank_transfer no longer THROWS on a short balance')
    : fail('add_bank_transfer still hard-blocks on a short balance');
(strpos($add, '$funds_warn') !== false && strpos($add, "'funds_warning'") !== false)
    ? pass('add_bank_transfer computes + surfaces a funds warning')
    : fail('add_bank_transfer does not surface a funds warning');

section('3. Post step: short funds WARNS, does not block');
(strpos($post, "throw new Exception('Insufficient balance") === false)
    ? pass('update_bank_transfer_status no longer THROWS on a short balance')
    : fail('post step still hard-blocks on a short balance');
(strpos($post, '$funds_warn') !== false && strpos($post, "'funds_warning'") !== false)
    ? pass('post step computes + surfaces a funds warning')
    : fail('post step does not surface a funds warning');
// The balance is still READ (so the warning is real), just not used to block.
(strpos($post, 'accountLedgerBalance($pdo, $from)') !== false)
    ? pass('post step still reads the real ledger balance for the warning')
    : fail('post step no longer reads the balance');

section('4. The real double-entry post is unchanged (still balanced + atomic)');
(strpos($post, "recordGlobalTransaction(") !== false)
    ? pass('post step still writes the balanced transfer entry') : fail('lost the transfer posting');
(preg_match('/catch[^{]*\{[^}]*rollBack\(\)/s', $post) === 1)
    ? pass('post step still rolls back on error') : fail('post step lost its rollback');
