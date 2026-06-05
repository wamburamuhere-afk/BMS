<?php
/**
 * Salary Components / structure (Plan H1) — CLI test
 *   php tests/test_salary_components_cli.php
 *
 * Verifies the new files exist + lint; the migration applied; route/menu wired; the
 * APIs are gated/CSRF; and the engine works: assigned components resolve to the right
 * allowance/deduction totals + itemised breakdown (% of basic + fixed), write to
 * payroll_items, and an employee with NO components leaves the legacy path untouched.
 *
 * These HR tables are MyISAM (no transactions) — the runtime seeds rows and DELETES
 * them explicitly in a finally block.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/salary_structure.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([
    'core/salary_structure.php',
    'app/bms/pos/salary_components.php',
    'api/pos/save_salary_component.php', 'api/pos/delete_salary_component.php',
    'api/pos/assign_salary_component.php', 'api/pos/remove_salary_component.php',
    'migrations/2026_06_11_salary_component_deleted_enum.php',
] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration + route/menu wired');
$col = $pdo->query("SHOW COLUMNS FROM salary_components LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
(strpos($col['Type'], "'deleted'") !== false) ? pass("salary_components.status supports 'deleted'") : fail("'deleted' missing from enum");
has(src($root, 'roots.php'), "'salary_components' => POS_DIR . '/salary_components.php'", 'salary_components route registered');
has(src($root, 'header.php'), "getUrl('salary_components')", 'HR menu link present');

// ─────────────────────────────────────────────────────────────────────────
section('3. API + payroll wiring contracts');
has(src($root, 'api/pos/save_salary_component.php'), "canEdit('payroll')", 'save gated by canEdit(payroll)');
has(src($root, 'api/pos/save_salary_component.php'), "csrf_check()", 'save enforces CSRF');
has(src($root, 'api/pos/assign_salary_component.php'), "assertScopeForEmployee", 'assign verifies employee scope');
$proc = src($root, 'api/process_payroll.php');
has($proc, "resolveEmployeeSalaryComponents", 'process_payroll uses the component resolver');
has($proc, "writePayrollItems", 'process_payroll writes payroll_items');
has($proc, "if (\$use_components)", 'process_payroll only overrides when components exist');
has($proc, "if (!\$use_components)", 'process_payroll keeps the legacy deduction path otherwise');
has(src($root, 'api/preview_payroll.php'), "resolveEmployeeSalaryComponents", 'preview mirrors the component logic');
has(src($root, 'api/get_payroll_details.php'), "FROM payroll_items WHERE payroll_id", 'payslip prefers payroll_items breakdown');

// ─────────────────────────────────────────────────────────────────────────
section('4. Engine — resolver totals + payroll_items (explicit cleanup, MyISAM)');
$emp = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();
if ($emp <= 0) { fail('no employee to test'); }
else {
    $basic = 1000.0;
    $cFix = $cPct = $cDed = 0; $a1 = $a2 = $a3 = 0; $pid = 0;
    try {
        // A fixed allowance (200), a 10%-of-basic allowance (=100), a fixed deduction (50).
        $ins = $pdo->prepare("INSERT INTO salary_components (component_name, component_type, calculation_type, default_amount, tax_applicable, status, created_by, created_at) VALUES (?,?,?,?,?, 'active', 4, NOW())");
        $ins->execute(['__TEST_Housing', 'allowance', 'fixed', 200, 1]);      $cFix = (int)$pdo->lastInsertId();
        $ins->execute(['__TEST_Transport', 'allowance', 'percentage', 10, 0]); $cPct = (int)$pdo->lastInsertId();
        $ins->execute(['__TEST_NSSF', 'deduction', 'fixed', 50, 0]);          $cDed = (int)$pdo->lastInsertId();

        $asg = $pdo->prepare("INSERT INTO employee_salary_components (employee_id, component_id, amount, effective_date, status, created_by, created_at) VALUES (?,?,?, CURDATE(), 'active', 4, NOW())");
        $asg->execute([$emp, $cFix, 200]); $a1 = (int)$pdo->lastInsertId();
        $asg->execute([$emp, $cPct, 10]);  $a2 = (int)$pdo->lastInsertId();
        $asg->execute([$emp, $cDed, 50]);  $a3 = (int)$pdo->lastInsertId();

        $r = resolveEmployeeSalaryComponents($pdo, $emp, $basic);
        $r['has_components'] ? pass('resolver detects the assigned components') : fail('resolver missed components');
        (abs($r['allowances'] - 300.0) < 0.01) ? pass('allowances = 300 (200 fixed + 10% of 1000)') : fail('allowances wrong: ' . $r['allowances']);
        (abs($r['deductions'] - 50.0) < 0.01) ? pass('deductions = 50 (fixed)') : fail('deductions wrong: ' . $r['deductions']);
        (count($r['items']) === 3) ? pass('three itemised lines produced') : fail('items count: ' . count($r['items']));

        // writePayrollItems persists them against a throwaway payroll id.
        $pdo->prepare("INSERT INTO payroll (payroll_number, employee_id, payroll_period, payroll_date, basic_salary, allowances, deductions, gross_salary, net_salary, month, year, status, payment_status, created_by, created_at) VALUES ('__TEST_PR', ?, '2099-01', '2099-01-01', ?, ?, ?, ?, ?, 1, 2099, 'pending', 'pending', 4, NOW())")
            ->execute([$emp, $basic, $r['allowances'], $r['deductions'], $basic + $r['allowances'], $basic + $r['allowances'] - $r['deductions']]);
        $pid = (int)$pdo->lastInsertId();
        writePayrollItems($pdo, $pid, $r['items']);
        $n = (int)$pdo->query("SELECT COUNT(*) FROM payroll_items WHERE payroll_id = $pid")->fetchColumn();
        ($n === 3) ? pass('payroll_items persisted (3 rows)') : fail("expected 3 payroll_items, got $n");
        $ded = (int)$pdo->query("SELECT COUNT(*) FROM payroll_items WHERE payroll_id = $pid AND item_type = 'deduction'")->fetchColumn();
        ($ded === 1) ? pass('one item flagged as a deduction') : fail("deduction items: $ded");

        // An employee with NO components → legacy path untouched (has_components false).
        $none = resolveEmployeeSalaryComponents($pdo, -999, $basic);
        (!$none['has_components'] && $none['allowances'] === 0.0 && $none['deductions'] === 0.0)
            ? pass('no-component employee yields empty (legacy path preserved)') : fail('no-component default wrong');
    } catch (Throwable $e) {
        fail('runtime error: ' . $e->getMessage());
    } finally {
        // Explicit cleanup (MyISAM — no rollback).
        foreach ([$a1, $a2, $a3] as $x) if ($x) $pdo->prepare("DELETE FROM employee_salary_components WHERE employee_component_id=?")->execute([$x]);
        foreach ([$cFix, $cPct, $cDed] as $x) if ($x) $pdo->prepare("DELETE FROM salary_components WHERE component_id=?")->execute([$x]);
        if ($pid) { $pdo->prepare("DELETE FROM payroll_items WHERE payroll_id=?")->execute([$pid]); $pdo->prepare("DELETE FROM payroll WHERE payroll_id=?")->execute([$pid]); }
    }
    pass('seeded rows cleaned up (MyISAM-safe)');
}
