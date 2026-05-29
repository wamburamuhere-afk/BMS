<?php
/**
 * Phase 0.2 — account-classification cleanup CLI test
 * ----------------------------------------------------
 *   php tests/test_phase0_account_classification_cli.php
 *
 * Verifies:
 *   1. Migration file exists and is lint-clean.
 *   2. The 3 target accounts now have the correct account_type_id:
 *        account_id=2  → 3 (equity)  and name = 'Opening Balance Equity'
 *        account_id=3  → 1 (asset)
 *        account_id=6  → 1 (asset)
 *   3. Accounts NOT in the migration scope are untouched (id=4, 5, 9, 12, 13).
 *   4. No account has account_type_id outside the 5 known values (1..5).
 *   5. account_types reference rows are intact.
 *   6. Idempotency: re-running the migration is a no-op (rowCount=0 on each UPDATE).
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

global $pdo;

// ─────────────────────────────────────────────────────────────────────────
section('1. Migration file exists and lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$mfile = "$root/migrations/2026_05_28_fix_account_classifications.php";
if (!file_exists($mfile)) { fail('migration missing'); exit(1); }
pass('migration file present');
$rc = 0;
exec("php -l " . escapeshellarg($mfile) . " 2>&1", $out, $rc);
$rc === 0 ? pass('migration lint-clean') : fail('migration lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Target accounts now have correct account_type_id');
// ─────────────────────────────────────────────────────────────────────────
$expected = [
    2 => ['name' => 'Opening Balance Equity', 'type_id' => 3, 'type_name' => 'equity'],
    3 => ['name' => 'Fixed Assets',            'type_id' => 1, 'type_name' => 'asset'],
    6 => ['name' => 'NMB',                     'type_id' => 1, 'type_name' => 'asset'],
];
foreach ($expected as $id => $want) {
    $stmt = $pdo->prepare("SELECT account_name, account_type_id FROM accounts WHERE account_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { fail("account_id=$id not found"); continue; }
    if ($row['account_name'] === $want['name']) {
        pass("id=$id name = '{$want['name']}'");
    } else {
        fail("id=$id name mismatch: got '{$row['account_name']}', expected '{$want['name']}'");
    }
    if ((int)$row['account_type_id'] === $want['type_id']) {
        pass("id=$id type_id = {$want['type_id']} ({$want['type_name']})");
    } else {
        fail("id=$id type_id mismatch: got {$row['account_type_id']}, expected {$want['type_id']}");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Non-target accounts untouched');
// ─────────────────────────────────────────────────────────────────────────
$untouched = [
    4  => ['Salaries and Wages',     5],  // expense
    5  => ['CRDB Bank - Main Account', 1],  // asset
    9  => ['CRDB Bank - Main Account', 1],
    12 => ['CRDB Bank - Main Account', 1],
    13 => ['Marketing',              5],  // expense
];
foreach ($untouched as $id => [$name, $type_id]) {
    $stmt = $pdo->prepare("SELECT account_name, account_type_id FROM accounts WHERE account_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { pass("account_id=$id not present (skipped — fine)"); continue; }
    if ((int)$row['account_type_id'] === $type_id) {
        pass("id=$id ('{$name}') still has type_id=$type_id");
    } else {
        fail("id=$id ('{$name}') type_id changed unexpectedly: got {$row['account_type_id']}, expected $type_id");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. No account has out-of-range account_type_id');
// ─────────────────────────────────────────────────────────────────────────
$bad = $pdo->query("
    SELECT account_id, account_name, account_type_id
      FROM accounts
     WHERE status = 'active'
       AND (account_type_id IS NULL OR account_type_id NOT IN (1,2,3,4,5))
")->fetchAll(PDO::FETCH_ASSOC);
if (empty($bad)) {
    pass('all active accounts reference a valid account_type_id (1-5)');
} else {
    fail('found ' . count($bad) . ' accounts with bad type_id: ' . json_encode($bad));
}

// ─────────────────────────────────────────────────────────────────────────
section('5. account_types reference table intact');
// ─────────────────────────────────────────────────────────────────────────
$nTypes = (int)$pdo->query("SELECT COUNT(*) FROM account_types")->fetchColumn();
$nTypes === 5 ? pass("account_types has 5 rows (asset/liability/equity/income/expense)")
              : fail("account_types row count is $nTypes, expected 5");

// Verify the 5 canonical types are by name (not by ID alone)
$expectedTypes = ['asset','liability','equity','income','expense'];
$gotTypes = $pdo->query("SELECT type_name FROM account_types ORDER BY type_id")->fetchAll(PDO::FETCH_COLUMN);
if ($gotTypes === $expectedTypes) {
    pass('account_types names match canonical 5-type set in expected order');
} else {
    fail('account_types names: got ' . json_encode($gotTypes) . ', expected ' . json_encode($expectedTypes));
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Idempotency: re-running the migration is a no-op');
// ─────────────────────────────────────────────────────────────────────────
// Simulate the 3 UPDATEs the migration does. Each should match 0 rows
// because the migration was already applied.
$updates = [
    ["UPDATE accounts SET account_type_id = 3, account_name = 'Opening Balance Equity' WHERE account_name = 'opening balance equit' AND account_type_id = 1", 'OBE'],
    ["UPDATE accounts SET account_type_id = 1 WHERE account_name = 'Fixed Assets' AND account_type_id = 4", 'Fixed Assets'],
    ["UPDATE accounts SET account_type_id = 1 WHERE account_name = 'NMB' AND account_type_id = 5", 'NMB'],
];
foreach ($updates as [$sql, $label]) {
    $n = $pdo->exec($sql);
    $n === 0 ? pass("re-run UPDATE for $label is a no-op (0 rows)")
             : fail("re-run UPDATE for $label affected $n row(s) — NOT idempotent");
}

exit($failures === 0 ? 0 : 1);
