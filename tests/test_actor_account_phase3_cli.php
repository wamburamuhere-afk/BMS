<?php
/**
 * tests/test_actor_account_phase3_cli.php
 *   php tests/test_actor_account_phase3_cli.php
 *
 * Phase 3 (actor-as-account) — verifies the backfill migration ran completely:
 * every active actor has a ledger_account_id, and every linked account_id
 * resolves to a real row in accounts with the correct code pattern.
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

// ── 1. Migration file integrity ───────────────────────────────────────────────
section('1. Migration file present + lint-clean + idempotent');
$mig = "$root/migrations/2026_06_19_actor_ledger_account_backfill.php";
file_exists($mig) ? pass('backfill migration file exists') : fail('backfill migration file missing');
$lint = shell_exec('php -l ' . escapeshellarg($mig) . ' 2>&1');
(strpos((string)$lint, 'No syntax errors') !== false) ? pass('migration lint-clean') : fail("lint: $lint");
$src = file_get_contents($mig);
(strpos($src, 'ledger_account_id IS NULL') !== false)
    ? pass('skips already-linked rows (idempotent)') : fail('does not guard on ledger_account_id IS NULL');
(strpos($src, 'beginTransaction') !== false)
    ? pass('wraps in a transaction') : fail('no transaction (data integrity risk)');
(strpos($src, 'rollBack') !== false)
    ? pass('rolls back on failure') : fail('no rollBack on failure');
(strpos($src, 'exit(1)') !== false)
    ? pass('exit(1) on failure') : fail('does not exit(1) on failure');

// ── 2. No actor is left unlinked ──────────────────────────────────────────────
section('2. All active actors have ledger_account_id set');
$checks = [
    ['customers',      'customer_id',  "status != 'deleted'"],
    ['suppliers',      'supplier_id',  "status != 'deleted'"],
    ['sub_contractors','supplier_id',  "status != 'deleted'"],
    ['employees',      'employee_id',  '1=1'],
];
$total_linked  = 0;
$total_unlinked = 0;
foreach ($checks as [$table, $pk, $where]) {
    $unlinked = (int) $pdo->query(
        "SELECT COUNT(*) FROM `$table` WHERE ledger_account_id IS NULL AND ($where)"
    )->fetchColumn();
    $linked = (int) $pdo->query(
        "SELECT COUNT(*) FROM `$table` WHERE ledger_account_id IS NOT NULL AND ($where)"
    )->fetchColumn();
    $total_linked   += $linked;
    $total_unlinked += $unlinked;
    ($unlinked === 0)
        ? pass("$table: all $linked active rows linked")
        : fail("$table: $unlinked row(s) still have NULL ledger_account_id");
}
pass("total linked actor accounts: $total_linked");
($total_linked > 0) ? pass('at least one actor is linked (backfill ran)') : fail('no linked actors at all');

// ── 3. Every linked account_id resolves and has the right code pattern ────────
section('3. Linked account rows exist with correct code format');
$patterns = [
    ['customers',      'customer_id',  "t.status != 'deleted'", 'CUST'],
    ['suppliers',      'supplier_id',  "t.status != 'deleted'", 'SUP'],
    ['sub_contractors','supplier_id',  "t.status != 'deleted'", 'SUB'],
    ['employees',      'employee_id',  '1=1',                   'EMP'],
];
$bad = 0;
foreach ($patterns as [$table, $pk, $where, $prefix]) {
    $rows = $pdo->query(
        "SELECT t.`$pk`, t.ledger_account_id, a.account_code
         FROM `$table` t
         JOIN accounts a ON a.account_id = t.ledger_account_id
         WHERE t.ledger_account_id IS NOT NULL AND ($where)"
    )->fetchAll(PDO::FETCH_ASSOC);

    $pattern_ok = 0;
    foreach ($rows as $row) {
        if (!preg_match('/^\d-\d{4}-' . $prefix . '-\d{5}$/', $row['account_code'])) {
            fail("$table #{$row[$pk]} has bad code: {$row['account_code']}");
            $bad++;
        } else {
            $pattern_ok++;
        }
    }
    if ($bad === 0) {
        pass("$table: all $pattern_ok codes match *-$prefix-NNNNN pattern");
    }
}

// ── 4. Accounts hang under the right control parents ─────────────────────────
section('4. Sub-accounts are children of the correct control account');
$parentChecks = [
    ['customers',       'customer_id',  "t.status != 'deleted'", '1-1200'],
    ['suppliers',       'supplier_id',  "t.status != 'deleted'", '2-1200'],
    ['sub_contractors', 'supplier_id',  "t.status != 'deleted'", '2-1200'],
    ['employees',       'employee_id',  '1=1',                   '2-1440'],
];
foreach ($parentChecks as [$table, $pk, $where, $expectedParentCode]) {
    $wrong = (int) $pdo->query(
        "SELECT COUNT(*) FROM `$table` t
         JOIN accounts a  ON a.account_id  = t.ledger_account_id
         JOIN accounts ap ON ap.account_id = a.parent_account_id
         WHERE t.ledger_account_id IS NOT NULL AND ($where)
           AND ap.account_code != '$expectedParentCode'"
    )->fetchColumn();
    ($wrong === 0)
        ? pass("$table: all sub-accounts hang under $expectedParentCode")
        : fail("$table: $wrong sub-account(s) have the wrong parent (expected $expectedParentCode)");
}

// ── 5. Idempotency — re-running migration changes nothing ─────────────────────
section('5. Migration is idempotent (re-run creates 0 new accounts)');
$before = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code REGEXP '^[12]-[0-9]+-[A-Z]+-[0-9]+$'")->fetchColumn();
$output = shell_exec('php ' . escapeshellarg($mig) . ' 2>&1');
$after  = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE account_code REGEXP '^[12]-[0-9]+-[A-Z]+-[0-9]+$'")->fetchColumn();
($before === $after)
    ? pass("re-run created 0 new accounts ($before before = $after after)")
    : fail("re-run created " . ($after - $before) . " duplicate account(s)");
(strpos((string)$output, 'Migration complete') !== false)
    ? pass('migration reports "Migration complete" on re-run') : fail("re-run output unexpected: $output");
