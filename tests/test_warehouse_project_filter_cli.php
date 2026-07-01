<?php
/**
 * Warehouse ↔ Project Filter — Regression Guard
 *
 * Rule: when a Project is selected, the Warehouse dropdown must show ONLY
 * that project's warehouses; when no Project is selected, it must show ONLY
 * warehouses not linked to any project (never "all warehouses" as a fallback).
 *
 * Confirmed already correct (no change needed): RFQ create, GRN create,
 * DN create, Sales Order create/edit, Quotation form.
 *
 * This guard covers the three pages that violated the rule and were fixed:
 *   - app/bms/purchase/purchase_order_create.php (had a fallback-to-all)
 *   - app/bms/stock/stock_adjustments.php (showed all when no project)
 *   - app/bms/pos/pos.php + pos_scripts_new.php (warehouse ignored project entirely)
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

$files = [
    'app/bms/purchase/purchase_order_create.php',
    'app/bms/stock/stock_adjustments.php',
    'app/bms/pos/pos.php',
    'app/bms/pos/pos_scripts_new.php',
];

section('1. Lint');
foreach ($files as $f) {
    $out = shell_exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1');
    (strpos($out, 'No syntax errors detected') !== false) ? pass("$f lint-clean") : fail("$f — $out");
}

section('2. purchase_order_create.php — fallback-to-all removed');
$src = file_get_contents("$root/app/bms/purchase/purchase_order_create.php");
(strpos($src, 'fallback when no general warehouses') === false) ? pass('no-project fallback line removed') : fail('no-project fallback line still present');
(strpos($src, 'fallback when no project-linked warehouses') === false) ? pass('project-selected fallback line removed') : fail('project-selected fallback line still present');
(strpos($src, "filtered = allWarehouses.filter(w => !w.project_id || w.project_id === 0);") !== false) ? pass('strict unassigned-only branch intact') : fail('unassigned-only branch missing/changed');
(strpos($src, "filtered = allWarehouses.filter(w => w.project_id == projectId);") !== false) ? pass('strict project-match branch intact') : fail('project-match branch missing/changed');

section('3. stock_adjustments.php — unassigned-only when no project');
$src = file_get_contents("$root/app/bms/stock/stock_adjustments.php");
(strpos($src, 'show ALL warehouses') === false) ? pass('"show ALL warehouses" branch removed') : fail('still shows all warehouses when no project selected');
(strpos($src, 'return w.project_id === 0;') !== false) ? pass('no-project branch now filters to project_id === 0') : fail('no-project branch does not filter to unassigned-only');
(strpos($src, 'leave blank to see all') === false) ? pass('stale "leave blank to see all" hint text removed') : fail('stale hint text still present');

section('4. pos.php + pos_scripts_new.php — warehouse now cascades from project');
$posPhp = file_get_contents("$root/app/bms/pos/pos.php");
$posJs  = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
(strpos($posPhp, "data-project-id='{\$w['project_id']}'") !== false) ? pass('warehouse options carry data-project-id') : fail('warehouse options missing data-project-id');
(strpos($posPhp, 'IFNULL(project_id,0) as project_id') !== false) ? pass('warehouse query now selects project_id') : fail('warehouse query missing project_id');
(strpos($posPhp, "posProjectId\" onchange=\"filterPosWarehousesByProject(); loadProducts();\"") !== false) ? pass('project select triggers the cascade on change') : fail('project select does not trigger the cascade');
(strpos($posJs, 'function filterPosWarehousesByProject()') !== false) ? pass('filterPosWarehousesByProject() defined') : fail('filterPosWarehousesByProject() missing');
$readyPos      = strpos($posJs, '$(document).ready(function()');
$cascadeCallPos = strpos($posJs, 'filterPosWarehousesByProject();', $readyPos === false ? 0 : $readyPos);
$loadProductsPos = strpos($posJs, 'loadProducts();', $readyPos === false ? 0 : $readyPos);
($readyPos !== false && $cascadeCallPos !== false && $loadProductsPos !== false && $cascadeCallPos < $loadProductsPos)
    ? pass('cascade runs on initial page load before products load')
    : fail('cascade not wired into initial page load before loadProducts()');

section('5. Live — warehouses table has real project-linked and unassigned rows to exercise the rule');
try {
    $total      = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active'")->fetchColumn();
    $unassigned = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active' AND project_id IS NULL")->fetchColumn();
    $assigned   = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active' AND project_id IS NOT NULL")->fetchColumn();
    ($total > 0) ? pass("active warehouses present ($total total)") : fail('no active warehouses found — cannot exercise the rule');
    echo "  \033[90m· $unassigned unassigned, $assigned project-linked\033[0m\n";
} catch (Throwable $e) {
    fail('live DB check failed: ' . $e->getMessage());
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: \033[31m$failures\033[0m\n";
exit($failures > 0 ? 1 : 0);
