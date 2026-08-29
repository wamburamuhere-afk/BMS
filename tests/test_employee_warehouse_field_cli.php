<?php
/**
 * BMS — Employee "Assign to Warehouse" Regression Guard
 *
 *   php tests/test_employee_warehouse_field_cli.php
 *
 * Covers the optional employees.warehouse_id field added 2026-08-29 — BMS's
 * equivalent of the reference LMS "Branch" field, built on the existing
 * warehouses table instead of a new branches concept, following the SAME
 * Project -> Warehouse cascade already used in Procurement/Sales
 * (core/warehouse_scope.php + assets/js/warehouse-project-filter.js):
 * a project selected narrows to that project's warehouses; no project shows
 * only warehouses not linked to any project.
 *
 *   A. STATIC — migration + column/index exist; the form field is present,
 *      optional, and deliberately a PLAIN <select> (every other Project+
 *      Warehouse pair in BMS keeps Warehouse native — see
 *      assets/js/warehouse-project-filter.js's own doc comment on why);
 *      the shared cascade helper is wired in (bindWarehouseToProject(),
 *      not a local reimplementation); the edit-restore path sets both
 *      fields and calls refresh().
 *   B. STATIC — api/add_employee.php and api/update_employee.php both gate
 *      the target warehouse with userCan('warehouse', ...) and persist it.
 *   C. LIVE — real DB: warehousesForSelect() reports project_id=0 for an
 *      unassigned warehouse; a real employee row round-trips warehouse_id
 *      through INSERT/UPDATE/NULL-clear exactly like project_id already does;
 *      userCan('warehouse', ...) denies out-of-scope and allows granted.
 *
 * Read-only except one throwaway employees row (fake, out-of-range id range,
 * deleted at the end — see cleanup). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/warehouse_scope.php";
global $pdo;

$pass = 0; $fail = 0;
function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✅\033[0m $msg\n"; }
    else       { $fail++; echo "  \033[31m❌ FAIL: $msg\033[0m\n"; }
}

// ── A. Static — schema, form, shared cascade wiring ────────────────────────
echo "\n[A] Schema + form wiring\n";

ok(is_file("$root/migrations/2026_08_29_employee_warehouse_field.php"), "migration file exists");
ok((bool) $pdo->query("SHOW COLUMNS FROM employees LIKE 'warehouse_id'")->fetch(), "employees.warehouse_id column exists");
$idx = $pdo->query("SHOW INDEX FROM employees")->fetchAll(PDO::FETCH_COLUMN, 2);
ok(in_array('idx_employees_warehouse', $idx, true), "index on employees.warehouse_id exists");

$col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'warehouse_id'")->fetch(PDO::FETCH_ASSOC);
ok($col && strtoupper($col['Null']) === 'YES', "warehouse_id is nullable (optional, matches 'put it optional')");

$empPage = file_get_contents("$root/app/bms/pos/employees.php");
ok(str_contains($empPage, 'warehousesForSelect($pdo)'), "employees.php builds its list via the shared warehousesForSelect()");
ok(str_contains($empPage, 'renderWarehouseOptions('), "employees.php renders options via the shared renderWarehouseOptions()");
ok(str_contains($empPage, "getUrl('assets/js/warehouse-project-filter.js')"), "employees.php loads the shared cascade JS");
ok(str_contains($empPage, 'bindWarehouseToProject('), "employees.php binds via the shared helper (not a local reimplementation)");
ok(str_contains($empPage, "id=\"warehouse_id\" name=\"warehouse_id\""), "warehouse_id field is present in the form");

// Deliberately a plain <select> — every other Project+Warehouse pair in BMS
// keeps Warehouse native so bindWarehouseToProject()'s show/hide + val('')
// stay in sync (Select2 needs an extra .trigger('change') the shared helper
// does not add). Assert the exact tag has no select2/select2-static class.
if (preg_match('/<select[^>]*id="warehouse_id"[^>]*>/', $empPage, $m)) {
    ok(!str_contains($m[0], 'select2'), "warehouse_id select is plain (no select2 class) — matches Sales/Invoice convention");
} else {
    ok(false, "could not locate the warehouse_id <select> tag to check its class");
}

ok(str_contains($empPage, "\$('#warehouse_id').val(emp.warehouse_id"), "edit-load restores emp.warehouse_id");
ok(str_contains($empPage, 'empWarehouseFilter.refresh()'), "edit-load re-filters via empWarehouseFilter.refresh() after restoring both fields");

// ── B. Static — API gates + persistence ────────────────────────────────────
echo "\n[B] API — scope gate + persistence\n";

$addApi = file_get_contents("$root/api/add_employee.php");
ok(str_contains($addApi, "userCan('warehouse', (int)\$_POST['warehouse_id'])"), "add_employee.php gates the target warehouse with userCan('warehouse', ...)");
ok(str_contains($addApi, 'warehouse_id, created_by'), "add_employee.php's INSERT includes warehouse_id");
ok(preg_match('/!empty\(\$_POST\[\'warehouse_id\'\]\)\s*\?\s*\$_POST\[\'warehouse_id\'\]\s*:\s*null/', $addApi) === 1,
   "add_employee.php coerces an empty warehouse_id to NULL (optional, matches project_id handling)");

$updApi = file_get_contents("$root/api/update_employee.php");
ok(str_contains($updApi, "userCan('warehouse', (int)\$_POST['warehouse_id'])"), "update_employee.php gates the target warehouse with userCan('warehouse', ...)");
ok(str_contains($updApi, 'warehouse_id_present'), "update_employee.php explicitly tracks whether warehouse_id was submitted (same '' -> NULL pattern as project_id)");
ok(str_contains($updApi, '"warehouse_id = ?"'), "update_employee.php's UPDATE includes warehouse_id");

// ── C. Live — real DB round-trip ───────────────────────────────────────────
echo "\n[C] Live — warehousesForSelect() + employees round-trip + scope gate\n";

$warehouses = warehousesForSelect($pdo);
ok(is_array($warehouses), "warehousesForSelect() returns an array");
$unassigned = array_filter($warehouses, fn($w) => (int)$w['project_id'] === 0);
$linked     = array_filter($warehouses, fn($w) => (int)$w['project_id'] > 0);
ok(count($unassigned) + count($linked) === count($warehouses), "every active warehouse reports project_id=0 (unassigned) or a real project id — never something else");

// Round-trip a throwaway employee row directly against the schema (the API
// itself needs multipart file uploads to exercise over HTTP — covered by live
// browser verification during development; this proves the COLUMN behaves
// exactly like project_id's already-proven optional/nullable pattern).
$testEmpNumber = '__WH-FIELD-TEST-' . random_int(10000, 99999);
$pdo->exec("DELETE FROM employees WHERE employee_number = " . $pdo->quote($testEmpNumber)); // defensive, in case of a prior aborted run

$pickedWarehouse = $warehouses[0] ?? null;

try {
    $pdo->exec("
        INSERT INTO employees (employee_number, first_name, last_name, gender, date_of_birth,
                                email, phone, address, emergency_contact, hire_date, warehouse_id, created_by)
        VALUES (" . $pdo->quote($testEmpNumber) . ", 'WHFieldTest', 'Row', 'male', '1990-01-01',
                " . $pdo->quote($testEmpNumber . '@example.test') . ", '0700000000', 'Test', 'Test EC', '2026-08-29',
                " . ($pickedWarehouse ? (int)$pickedWarehouse['warehouse_id'] : 'NULL') . ", 4)
    ");
    $testEmpId = (int) $pdo->lastInsertId();

    $row = $pdo->query("SELECT warehouse_id FROM employees WHERE employee_id = $testEmpId")->fetch(PDO::FETCH_ASSOC);
    if ($pickedWarehouse) {
        ok((int) $row['warehouse_id'] === (int) $pickedWarehouse['warehouse_id'], "INSERT with a real warehouse_id round-trips correctly");
    } else {
        ok($row['warehouse_id'] === null, "no active warehouses exist in this DB — NULL round-trips correctly instead");
    }

    // Clear it (the "optional" contract: must be settable back to NULL, not stuck).
    $pdo->exec("UPDATE employees SET warehouse_id = NULL WHERE employee_id = $testEmpId");
    $row2 = $pdo->query("SELECT warehouse_id FROM employees WHERE employee_id = $testEmpId")->fetch(PDO::FETCH_ASSOC);
    ok($row2['warehouse_id'] === null, "warehouse_id can be cleared back to NULL (never a stuck/mandatory assignment)");

} finally {
    if (isset($testEmpId)) {
        $pdo->exec("DELETE FROM employee_checklist_items WHERE checklist_id IN (SELECT checklist_id FROM employee_checklists WHERE employee_id = $testEmpId)");
        $pdo->exec("DELETE FROM employee_checklists WHERE employee_id = $testEmpId");
        $pdo->exec("DELETE FROM employee_documents WHERE employee_id = $testEmpId");
        $pdo->exec("DELETE FROM employees WHERE employee_id = $testEmpId");
        $left = $pdo->query("SELECT COUNT(*) FROM employees WHERE employee_id = $testEmpId")->fetchColumn();
        ok((int) $left === 0, "throwaway employee row cleaned up");
    }
}

// Scope gate itself — same userCan('warehouse', ...) mechanism the pre-existing
// project_id gate already uses; already covered generically by
// tests/test_warehouse_scope_cli.php, spot-checked here against the exact
// resource type this feature's gates call.
$_SESSION['scope'] = ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'warehouse_explicit' => false,
                       'suppliers' => [], 'customers' => [], 'employees' => []];
ok(userCan('warehouse', 999999) === false, "userCan('warehouse', X) denies a warehouse not in scope");
$_SESSION['scope']['warehouses'] = [999999];
ok(userCan('warehouse', 999999) === true, "userCan('warehouse', X) allows a warehouse explicitly granted");
unset($_SESSION['scope']);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m{$pass}\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m{$fail}\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
