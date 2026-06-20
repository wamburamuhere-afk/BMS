<?php
/**
 * tests/test_actor_ledger_account_link_cli.php
 *   php tests/test_actor_ledger_account_link_cli.php
 *
 * Phase 1 (actor-as-account) — schema guard. Proves the migration that links each
 * actor register to its own GL sub-account is present, idempotent, and that the
 * `ledger_account_id` column + index exist on all four registers in the live DB.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
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

$mig = "$root/migrations/2026_06_19_actor_ledger_account_link.php";
$registers = ['customers', 'suppliers', 'sub_contractors', 'employees'];

// ── 1. Migration present + valid + idempotent + halts on failure ─────────────
section('1. Migration file is present, valid and idempotent');
file_exists($mig) ? pass('migration file exists') : fail('migration file missing');
$out = shell_exec('php -l ' . escapeshellarg($mig) . ' 2>&1');
(strpos((string)$out, 'No syntax errors') !== false) ? pass('migration lint-clean') : fail("lint: $out");
$src = file_get_contents($mig);
(strpos($src, "SHOW COLUMNS FROM") !== false && strpos($src, "ledger_account_id'") !== false)
    ? pass('guards each ADD with a SHOW COLUMNS check (idempotent)') : fail('ALTER not guarded by a column check');
(strpos($src, 'SHOW TABLES LIKE') !== false)
    ? pass('guards on table existence (skips an absent register)') : fail('no table-existence guard');
(strpos($src, 'exit(1)') !== false)
    ? pass('exit(1) on failure (halts the deploy)') : fail('does not exit(1) on failure');
(strpos($src, 'beginTransaction') === false)
    ? pass('no transaction around DDL (per migrations.md)') : fail('wraps DDL in a transaction');

// ── 2. Live schema — column + index on every register ────────────────────────
section('2. Live schema carries ledger_account_id on all four registers');
foreach ($registers as $t) {
    $col = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'ledger_account_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { fail("`$t`.ledger_account_id missing — run the migration"); continue; }
    pass("`$t`.ledger_account_id exists");
    (stripos($col['Type'], 'int') !== false) ? pass("`$t`.ledger_account_id is INT (got {$col['Type']})") : fail("`$t`.ledger_account_id wrong type ({$col['Type']})");
    (strtoupper((string)$col['Null']) === 'YES') ? pass("`$t`.ledger_account_id is NULLable (unlinked until backfill)") : fail("`$t`.ledger_account_id should be NULLable");
    $idx = $pdo->query("SHOW INDEX FROM `$t` WHERE Key_name = 'idx_ledger_account_id'")->fetch();
    $idx ? pass("`$t` has index idx_ledger_account_id") : fail("`$t` missing idx_ledger_account_id");
}

// ── 3. The control parents the sub-accounts will hang under still exist ───────
section('3. Control parents exist (so Phase 2/3 can attach the children)');
$parents = [
    'Trade Debtors (customers)'        => "account_code='1-1200'",
    'Trade Creditors (suppliers+SC)'   => "account_code='2-1200'",
    'Salaries Payable (employees)'     => "account_code='2-1440'",
];
foreach ($parents as $label => $where) {
    $r = $pdo->query("SELECT account_id, account_name FROM accounts WHERE $where LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $r ? pass("$label control account present (#{$r['account_id']} {$r['account_name']})") : fail("$label control account not found ($where)");
}
