<?php
/**
 * Payroll Items — 'employer_cost' ENUM regression guard
 * -------------------------------------------------------
 * The 2026-06-24 NSSF-employer feature made api/process_payroll.php write an
 * 'employer_cost' item_type into payroll_items (core/salary_structure.php's
 * writePayrollItems()), but the migration that shipped alongside it never
 * added 'employer_cost' to payroll_items.item_type's ENUM. Every payroll run
 * (nssf_employer > 0 for every employee) hit MySQL warning 1265 "Data
 * truncated for column 'item_type'" and failed for the whole batch.
 *
 * migrations/2026_07_01_payroll_items_employer_cost.php adds the missing
 * ENUM member. This guard proves the schema now accepts it and that the
 * exact write path (writePayrollItems) succeeds end-to-end.
 *
 * Run: php tests/test_payroll_items_employer_cost_cli.php
 * Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/salary_structure.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ Payroll Items — 'employer_cost' ENUM Guard ═══\033[0m\n";

section('1. Migration file');
$migPath = "$root/migrations/2026_07_01_payroll_items_employer_cost.php";
file_exists($migPath) ? pass('migration file exists') : fail('migration file MISSING');
$out = shell_exec('php -l ' . escapeshellarg($migPath) . ' 2>&1');
(strpos($out, 'No syntax errors detected') !== false) ? pass('migration lint-clean') : fail("migration lint error — $out");
$migSrc = file_get_contents($migPath);
(strpos($migSrc, "SHOW COLUMNS FROM payroll_items") !== false) ? pass('migration guards on existing column state (idempotent)') : fail('migration missing idempotency guard');

section('2. Live schema — payroll_items.item_type ENUM');
$col = $pdo->query("SHOW COLUMNS FROM payroll_items LIKE 'item_type'")->fetch(PDO::FETCH_ASSOC);
($col && strpos($col['Type'], "'employer_cost'") !== false)
    ? pass("payroll_items.item_type includes 'employer_cost' — {$col['Type']}")
    : fail("payroll_items.item_type still missing 'employer_cost' — got: " . ($col['Type'] ?? 'MISSING COLUMN'));

section('3. Live — exact previously-failing write path now succeeds');
// Use a payroll_id that cannot collide with a real row (payroll.payroll_id is a normal
// AUTO_INCREMENT int, so a large sentinel id is safe) and clean up unconditionally after.
$testPayrollId = 999999001;
try {
    // writePayrollItems() itself starts with `DELETE FROM payroll_items WHERE payroll_id = ?`,
    // so no pre-cleanup needed; this is exactly the call process_payroll.php makes.
    writePayrollItems($pdo, $testPayrollId, [
        ['item_type' => 'deduction',     'item_name' => 'NSSF (employee)', 'amount' => 100000, 'tax_applicable' => 0],
        ['item_type' => 'employer_cost', 'item_name' => 'NSSF (employer)', 'amount' => 100000, 'tax_applicable' => 0],
        ['item_type' => 'deduction',     'item_name' => 'PAYE',            'amount' => 103000, 'tax_applicable' => 0],
    ]);
    pass("writePayrollItems() with an 'employer_cost' line executes without a truncation error");

    $rows = $pdo->prepare("SELECT item_type, item_name, amount FROM payroll_items WHERE payroll_id = ? ORDER BY item_id");
    $rows->execute([$testPayrollId]);
    $items = $rows->fetchAll(PDO::FETCH_ASSOC);
    (count($items) === 3) ? pass('all 3 items persisted (none silently dropped)') : fail('expected 3 items, got ' . count($items));
    $hasEmployerCost = false;
    foreach ($items as $it) { if ($it['item_type'] === 'employer_cost' && (float)$it['amount'] === 100000.0) $hasEmployerCost = true; }
    $hasEmployerCost ? pass("'employer_cost' row stored with the correct amount (not truncated to empty/other)") : fail("'employer_cost' row missing or corrupted");
} catch (Throwable $e) {
    fail('writePayrollItems() threw: ' . $e->getMessage());
} finally {
    $pdo->prepare("DELETE FROM payroll_items WHERE payroll_id = ?")->execute([$testPayrollId]);
    $remaining = $pdo->prepare("SELECT COUNT(*) FROM payroll_items WHERE payroll_id = ?");
    $remaining->execute([$testPayrollId]);
    ((int)$remaining->fetchColumn() === 0) ? pass('test rows cleaned up — no data left behind') : fail('test rows NOT fully cleaned up');
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: \033[31m$failures\033[0m\n";
exit($failures > 0 ? 1 : 0);
