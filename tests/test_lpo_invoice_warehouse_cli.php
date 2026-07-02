<?php
/**
 * LPO + Invoice — Warehouse Field Regression Guard
 * ---------------------------------------------------
 * Customer LPO and Invoice creation had no warehouse field at all, so
 * inventory-product stock figures fell back to products.current_stock (a
 * non-warehouse-specific total) instead of the selected warehouse's actual
 * stock. Neither table even had a warehouse_id column.
 *
 * migrations/2026_07_01_lpo_invoice_warehouse.php adds warehouse_id to both
 * customer_lpos and invoices. lpo_create.php, invoice_create.php and
 * invoice_edit.php now offer a project-filtered warehouse picker, same rule
 * as every other module: project selected -> only that project's
 * warehouses; no project -> only unassigned warehouses.
 *
 * Rule: LPO always requires a warehouse (it only ever deals in inventory
 * products). Invoice requires one only when NOT in Service (Non-Inventory)
 * mode, and only on CREATE (existing invoices predate this field and
 * invoice_edit.php has no service/inventory toggle to judge their intent).
 *
 * Run: php tests/test_lpo_invoice_warehouse_cli.php
 * Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc($root, $rel){ $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }

echo "\n\033[1m═══ LPO + Invoice — Warehouse Field Guard ═══\033[0m\n";

section('1. Migration + schema');
$migPath = "$root/migrations/2026_07_01_lpo_invoice_warehouse.php";
file_exists($migPath) ? pass('migration file exists') : fail('migration file MISSING');
$out = shell_exec('php -l ' . escapeshellarg($migPath) . ' 2>&1');
(strpos($out, 'No syntax errors detected') !== false) ? pass('migration lint-clean') : fail("migration lint error — $out");
(bool)$pdo->query("SHOW COLUMNS FROM customer_lpos LIKE 'warehouse_id'")->fetch()
    ? pass('customer_lpos.warehouse_id exists') : fail('customer_lpos.warehouse_id missing — run the migration');
(bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'warehouse_id'")->fetch()
    ? pass('invoices.warehouse_id exists') : fail('invoices.warehouse_id missing — run the migration');

section('2. Syntax — all touched files');
foreach ([
    'app/bms/sales/lpo/lpo_create.php',
    'app/bms/invoice/invoice_create.php',
    'app/bms/invoice/invoice_edit.php',
    'api/customer/save_lpo.php',
    'api/account/save_invoice.php',
] as $f) {
    $o = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos($o, 'No syntax errors detected') !== false) ? pass("$f lint-clean") : fail("$f — $o");
}

$lpoCreate  = readSrc($root, 'app/bms/sales/lpo/lpo_create.php');
$invCreate  = readSrc($root, 'app/bms/invoice/invoice_create.php');
$invEdit    = readSrc($root, 'app/bms/invoice/invoice_edit.php');
$saveLpo    = readSrc($root, 'api/customer/save_lpo.php');
$saveInv    = readSrc($root, 'api/account/save_invoice.php');

section('3. lpo_create.php — warehouse field, always required, project-filtered');
(strpos($lpoCreate, 'id="warehouse_id" name="warehouse_id" required') !== false) ? pass('warehouse select present and required') : fail('warehouse select missing or not required');
(strpos($lpoCreate, 'function filterWarehousesByProject') !== false) ? pass('filterWarehousesByProject() defined') : fail('filterWarehousesByProject() missing');
(strpos($lpoCreate, "\$('#project_id').on('change', function() { filterWarehousesByProject(); });") !== false) ? pass('project change triggers the cascade') : fail('project change does not trigger the cascade');
(strpos($lpoCreate, 'filterWarehousesByProject(true);') !== false) ? pass('cascade runs on initial page load') : fail('cascade not wired into initial page load');
(strpos($lpoCreate, "warehouse_id=' + whId") !== false) ? pass('product fetch passes warehouse_id (warehouse-scoped stock)') : fail('product fetch does not pass warehouse_id');
(strpos($lpoCreate, '$warehouse_id = (int)($lpo_data[\'warehouse_id\'] ?? 0);') !== false) ? pass('edit mode pre-fills the saved warehouse') : fail('edit mode does not pre-fill warehouse');

section('4. invoice_create.php — warehouse required unless Service Invoice mode');
(strpos($invCreate, 'id="warehouse_id" name="warehouse_id" required') !== false) ? pass('warehouse select present, required by default (inventory mode)') : fail('warehouse select missing or not required by default');
(strpos($invCreate, "\$('#warehouse_id').prop('required', !isService);") !== false) ? pass('required toggled off in Service Invoice mode') : fail('required is not tied to Service Invoice toggle');
(strpos($invCreate, 'function filterWarehousesByProject') !== false) ? pass('filterWarehousesByProject() defined') : fail('filterWarehousesByProject() missing');
(strpos($invCreate, "\$('#project_id').on('change', function() { filterWarehousesByProject(); });") !== false) ? pass('project change triggers the cascade') : fail('project change does not trigger the cascade');
(strpos($invCreate, 'is_service: isService, warehouse_id: whId') !== false) ? pass('product cache load passes warehouse_id') : fail('product cache load does not pass warehouse_id');

section('5. invoice_edit.php — warehouse field present, project-filtered, pre-filled');
(strpos($invEdit, 'id="warehouse_id" name="warehouse_id"') !== false) ? pass('warehouse select present') : fail('warehouse select missing');
(strpos($invEdit, 'function filterWarehousesByProject') !== false) ? pass('filterWarehousesByProject() defined') : fail('filterWarehousesByProject() missing');
(strpos($invEdit, "\$invoice['warehouse_id'] == \$w['warehouse_id']) ? 'selected'") !== false) ? pass('pre-fills the invoice\'s saved warehouse') : fail('does not pre-fill saved warehouse');
(strpos($invEdit, 'warehouse_id: whId') !== false) ? pass('product cache load passes warehouse_id') : fail('product cache load does not pass warehouse_id');

section('6. Backend — save_lpo.php always requires warehouse_id, persists it');
(strpos($saveLpo, "if (!\$warehouse_id) {") !== false) ? pass('rejects a save with no warehouse') : fail('does not reject a missing warehouse');
(strpos($saveLpo, 'project_id = ?, warehouse_id = ?, issue_date') !== false) ? pass('UPDATE persists warehouse_id') : fail('UPDATE does not persist warehouse_id');
(strpos($saveLpo, 'project_id, warehouse_id, issue_date') !== false) ? pass('INSERT persists warehouse_id') : fail('INSERT does not persist warehouse_id');

section('7. Backend — save_invoice.php requires warehouse_id on CREATE only, unless service');
(strpos($saveInv, 'if (!$is_update && !$is_service_invoice && !$warehouse_id)') !== false) ? pass('required gate is create-only + service-aware') : fail('required gate does not match the intended rule');
(strpos($saveInv, 'project_id = ?, warehouse_id = ?, invoice_date') !== false) ? pass('UPDATE persists warehouse_id') : fail('UPDATE does not persist warehouse_id');
(strpos($saveInv, 'project_id, warehouse_id, invoice_date') !== false) ? pass('INSERT persists warehouse_id') : fail('INSERT does not persist warehouse_id');

section('8. Live — project/warehouse filter data + round-trip (transaction, rolled back)');
try {
    $pdo->beginTransaction();

    $whCount = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active'")->fetchColumn();
    ($whCount > 0) ? pass("active warehouses present ($whCount) — cascade has real data to exercise") : fail('no active warehouses found');

    $custId = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetchColumn();
    $whId   = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();

    if (!$custId || !$whId) {
        pass('no active customer/warehouse available — round trip skipped (not a failure)');
    } else {
        require_once "$root/core/code_generator.php";
        $lpoNumber = nextCode($pdo, 'LPO');
        $pdo->prepare("
            INSERT INTO customer_lpos (lpo_number, customer_id, warehouse_id, issue_date, amount, currency, status, created_by, prepared_by_name, prepared_by_role, prepared_at, created_at)
            VALUES (?, ?, ?, CURDATE(), 1000, 'TZS', 'pending', 1, 'Test Runner', 'Tester', NOW(), NOW())
        ")->execute([$lpoNumber, $custId, $whId]);
        $lpoId = (int)$pdo->lastInsertId();
        $storedWh = (int)$pdo->query("SELECT warehouse_id FROM customer_lpos WHERE lpo_id = $lpoId")->fetchColumn();
        ($storedWh === $whId) ? pass("customer_lpos.warehouse_id round-trips correctly (id $lpoId -> warehouse $whId)") : fail("customer_lpos.warehouse_id mismatch: expected $whId got $storedWh");

        $invNumber = nextCode($pdo, 'INV');
        $pdo->prepare("
            INSERT INTO invoices (invoice_number, customer_id, warehouse_id, invoice_date, due_date, subtotal, tax_amount, discount_amount, shipping_cost, grand_total, paid_amount, balance_due, currency, status, created_by, created_at)
            VALUES (?, ?, ?, CURDATE(), CURDATE(), 1000, 0, 0, 0, 1000, 0, 1000, 'TZS', 'pending', 1, NOW())
        ")->execute([$invNumber, $custId, $whId]);
        $invId = (int)$pdo->lastInsertId();
        $storedWh2 = (int)$pdo->query("SELECT warehouse_id FROM invoices WHERE invoice_id = $invId")->fetchColumn();
        ($storedWh2 === $whId) ? pass("invoices.warehouse_id round-trips correctly (id $invId -> warehouse $whId)") : fail("invoices.warehouse_id mismatch: expected $whId got $storedWh2");
    }

    $pdo->rollBack();
    pass('transaction rolled back — no test data persisted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Live round trip error: ' . $e->getMessage());
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: \033[31m$failures\033[0m\n";
exit($failures > 0 ? 1 : 0);
