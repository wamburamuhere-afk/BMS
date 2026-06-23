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

section('2. Create + post step: short funds WARNS, does not block');
// Posting now happens at CREATE (auto-post). The funds policy + the balanced
// double-entry both live in add_bank_transfer.php.
(strpos($add, "throw new Exception('Insufficient balance") === false)
    ? pass('add_bank_transfer no longer THROWS on a short balance')
    : fail('add_bank_transfer still hard-blocks on a short balance');
(strpos($add, '$funds_warn') !== false && strpos($add, "'funds_warning'") !== false)
    ? pass('add_bank_transfer computes + surfaces a funds warning')
    : fail('add_bank_transfer does not surface a funds warning');
(strpos($add, 'accountLedgerBalance($pdo, $from_id)') !== false)
    ? pass('add_bank_transfer still reads the real ledger balance for the warning')
    : fail('add_bank_transfer no longer reads the balance');

section('3. The real double-entry post is unchanged (still balanced + atomic)');
(strpos($add, "recordGlobalTransaction(") !== false)
    ? pass('create step writes the balanced transfer entry') : fail('lost the transfer posting');
(preg_match('/catch[^{]*\{[^}]*rollBack\(\)/s', $add) === 1)
    ? pass('create step rolls back on error (atomic create+post)') : fail('create step lost its rollback');

section('4. Reverse step: atomic + removes the journal mirror');
(strpos($post, "unmirrorTransactionFromJournal") !== false)
    ? pass('reverse removes the journal mirror') : fail('reverse does not unmirror');
(preg_match('/catch[^{]*\{[^}]*rollBack\(\)/s', $post) === 1)
    ? pass('reverse rolls back on error (atomic)') : fail('reverse lost its rollback');
