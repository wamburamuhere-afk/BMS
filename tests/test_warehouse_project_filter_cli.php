<?php
/**
 * Warehouse ↔ Project Filter — Regression Guard
 *
 * Rule: when a Project is selected, the Warehouse dropdown must show ONLY
 * that project's warehouses; when no Project is selected, it must show ONLY
 * warehouses not linked to any project (never "all warehouses" as a fallback).
 *
 * The rule is centralised in ONE shared mechanism:
 *   - core/warehouse_scope.php            (warehousesForSelect + renderWarehouseOptions)
 *   - assets/js/warehouse-project-filter.js
 *     (warehouseMatchesProject + filterWarehousesForProject + bindWarehouseToProject)
 *
 * Sales-side pages consume the shared mechanism and must NOT carry a local
 * re-implementation. Purchase-side pages (purchase_order_create.php,
 * stock_adjustments.php) still carry their own verified copies — they are
 * guarded string-for-string below until they migrate too.
 *
 * Run:  php tests/test_warehouse_project_filter_cli.php
 *   Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$passes = 0; $failures = 0;

function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ Warehouse ↔ Project Filter Guard ═══\033[0m\n";

// Pages migrated to the shared mechanism. 'bind' = native-select cascade
// (bindWarehouseToProject); 'list' = Select2 rebuild (filterWarehousesForProject).
$shared_pages = [
    'app/bms/sales/quotations/quotation_form.php' => 'bind',
    'app/bms/sales/sales_order_create.php'        => 'bind',
    'app/bms/sales/sales_order_edit.php'          => 'bind',
    'app/bms/sales/lpo/lpo_create.php'            => 'bind',
    'app/bms/invoice/invoice_create.php'          => 'bind',
    'app/bms/invoice/invoice_edit.php'            => 'bind',
    'app/bms/grn/dn_create.php'                   => 'list',
    'app/bms/grn/dn_outbound.php'                 => 'list',
    'app/bms/pos/pos_scripts_new.php'             => 'bind',
];

$legacy_pages = [
    'app/bms/purchase/purchase_order_create.php',
    'app/bms/stock/stock_adjustments.php',
];

section('1. Lint');
$lint = array_merge(
    ['core/warehouse_scope.php', 'app/bms/pos/pos.php'],
    array_keys($shared_pages),
    $legacy_pages
);
foreach ($lint as $f) {
    $out = shell_exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1');
    (strpos($out, 'No syntax errors detected') !== false) ? pass("$f lint-clean") : fail("$f — $out");
}

section('2. Shared mechanism exists and defines the API');
$helper = @file_get_contents("$root/core/warehouse_scope.php") ?: '';
(strpos($helper, 'function warehousesForSelect')   !== false) ? pass('warehousesForSelect() defined')   : fail('warehousesForSelect() missing');
(strpos($helper, 'function renderWarehouseOptions') !== false) ? pass('renderWarehouseOptions() defined') : fail('renderWarehouseOptions() missing');
(strpos($helper, "scopeFilterSqlNullable('project'") !== false) ? pass('helper query is user-project scoped') : fail('helper query missing scopeFilterSqlNullable');
(strpos($helper, 'data-project-id') !== false) ? pass('options carry data-project-id') : fail('options missing data-project-id');

$js = @file_get_contents("$root/assets/js/warehouse-project-filter.js") ?: '';
(strpos($js, 'function warehouseMatchesProject')    !== false) ? pass('JS: warehouseMatchesProject() defined')    : fail('JS: warehouseMatchesProject() missing');
(strpos($js, 'function filterWarehousesForProject') !== false) ? pass('JS: filterWarehousesForProject() defined') : fail('JS: filterWarehousesForProject() missing');
(strpos($js, 'function bindWarehouseToProject')     !== false) ? pass('JS: bindWarehouseToProject() defined')     : fail('JS: bindWarehouseToProject() missing');
(strpos($js, 'pid === 0 ? wpid === 0 : wpid === pid') !== false) ? pass('JS: strict rule intact (unassigned-only / project-only, no fallback)') : fail('JS: strict rule line changed/missing');

section('3. Every migrated page uses the shared mechanism — and no local copies');
foreach ($shared_pages as $f => $mode) {
    $src = file_get_contents("$root/$f");
    $needle = $mode === 'list' ? 'filterWarehousesForProject(' : 'bindWarehouseToProject(';
    (strpos($src, $needle) !== false) ? pass("$f uses $needle...)") : fail("$f does not call $needle...)");
    (strpos($src, 'function filterWarehousesByProject')    === false &&
     strpos($src, 'function filterPosWarehousesByProject') === false)
        ? pass("$f carries no local filter copy") : fail("$f re-implements the filter locally");
    (strpos($src, 'warehouse-project-filter.js') !== false) ? pass("$f includes the shared JS module") : fail("$f missing shared JS include");
}
// PHP side: pages that render the warehouse <select> server-side must build it
// from the shared helper (pos.php renders it; pos_scripts_new.php is JS-only).
$php_render_pages = array_merge(
    array_diff(array_keys($shared_pages), ['app/bms/pos/pos_scripts_new.php']),
    ['app/bms/pos/pos.php']
);
foreach ($php_render_pages as $f) {
    $src = file_get_contents("$root/$f");
    (strpos($src, 'warehousesForSelect(') !== false) ? pass("$f builds its list via warehousesForSelect()") : fail("$f does not use warehousesForSelect()");
}

section('4. POS — warehouse cascade wired before products load');
$posJs = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
$posPhp = file_get_contents("$root/app/bms/pos/pos.php");
(strpos($posPhp, 'renderWarehouseOptions(') !== false) ? pass('pos.php warehouse options rendered by shared helper') : fail('pos.php options not rendered by shared helper');
$readyPos       = strpos($posJs, '$(document).ready(function()');
$cascadeCallPos = strpos($posJs, 'bindWarehouseToProject(', $readyPos === false ? 0 : $readyPos);
$loadProductsPos = strpos($posJs, 'loadProducts();', $cascadeCallPos === false ? 0 : $cascadeCallPos + 25);
($readyPos !== false && $cascadeCallPos !== false && $loadProductsPos !== false)
    ? pass('cascade bound on page load before initial loadProducts()')
    : fail('cascade not wired into initial page load before loadProducts()');

section('5. Legacy copies (purchase side, pending migration) — still strict');
$src = file_get_contents("$root/app/bms/purchase/purchase_order_create.php");
(strpos($src, 'fallback when no general warehouses') === false) ? pass('PO create: no-project fallback line removed') : fail('PO create: no-project fallback line still present');
(strpos($src, 'fallback when no project-linked warehouses') === false) ? pass('PO create: project-selected fallback line removed') : fail('PO create: project-selected fallback line still present');
(strpos($src, "filtered = allWarehouses.filter(w => !w.project_id || w.project_id === 0);") !== false) ? pass('PO create: strict unassigned-only branch intact') : fail('PO create: unassigned-only branch missing/changed');
(strpos($src, "filtered = allWarehouses.filter(w => w.project_id == projectId);") !== false) ? pass('PO create: strict project-match branch intact') : fail('PO create: project-match branch missing/changed');

$src = file_get_contents("$root/app/bms/stock/stock_adjustments.php");
(strpos($src, 'show ALL warehouses') === false) ? pass('stock_adjustments: "show ALL warehouses" branch removed') : fail('stock_adjustments: still shows all warehouses when no project selected');
(strpos($src, 'return w.project_id === 0;') !== false) ? pass('stock_adjustments: no-project branch filters to project_id === 0') : fail('stock_adjustments: no-project branch does not filter to unassigned-only');

section('6. Live — warehousesForSelect() returns scoped, rule-ready rows');
try {
    require_once "$root/core/warehouse_scope.php";

    // Admin view: helper must return every active warehouse.
    $_SESSION['scope'] = ['is_admin' => true, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()];
    $adminRows = warehousesForSelect($pdo);
    $total = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active'")->fetchColumn();
    (count($adminRows) === $total) ? pass("admin sees all $total active warehouses") : fail('admin row count (' . count($adminRows) . ") != active warehouses ($total)");
    $nullProj = array_filter($adminRows, fn($w) => !isset($w['project_id']) || $w['project_id'] === null || $w['project_id'] === '');
    empty($nullProj) ? pass('every row carries a non-null project_id (0 = unassigned)') : fail('some rows have null/empty project_id');

    // Non-admin with no assignments: unassigned warehouses only.
    $_SESSION['scope'] = ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()];
    $noScopeRows = warehousesForSelect($pdo);
    $unassigned = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active' AND project_id IS NULL")->fetchColumn();
    (count($noScopeRows) === $unassigned) ? pass("no-scope user sees only the $unassigned unassigned warehouses") : fail('no-scope row count (' . count($noScopeRows) . ") != unassigned ($unassigned)");

    // Non-admin assigned to one project: unassigned + that project's warehouses.
    $projWithWh = $pdo->query("SELECT project_id FROM warehouses WHERE status='active' AND project_id IS NOT NULL LIMIT 1")->fetchColumn();
    if ($projWithWh) {
        $_SESSION['scope'] = ['is_admin' => false, 'projects' => [(int)$projWithWh], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()];
        $scopedRows = warehousesForSelect($pdo);
        $expStmt = $pdo->prepare("SELECT COUNT(*) FROM warehouses WHERE status='active' AND (project_id IS NULL OR project_id = ?)");
        $expStmt->execute([(int)$projWithWh]);
        $expected = (int)$expStmt->fetchColumn();
        (count($scopedRows) === $expected) ? pass("scoped user (project $projWithWh) sees $expected warehouses (unassigned + own project)") : fail('scoped row count (' . count($scopedRows) . ") != expected ($expected)");
    } else {
        pass('no project-linked warehouse in DB — scoped-user case skipped (nothing to exercise)');
    }
    unset($_SESSION['scope']);
} catch (Throwable $e) {
    fail('live DB check failed: ' . $e->getMessage());
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: \033[31m$failures\033[0m\n";
exit($failures > 0 ? 1 : 0);
