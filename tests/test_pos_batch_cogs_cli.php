<?php
/**
 * Phase 18 (pos_upgrade_plan.md §8) — per-batch COGS — CLI test
 *   php tests/test_pos_batch_cogs_cli.php
 *
 * Verifies core/sales_posting.php::posSaleCogs() prefers the real batch
 * unit_cost (Phase 17's pos_sale_item_batches link) over the average
 * products.cost_price, per sale line — falling back to the pre-existing
 * average-cost convention for any line with no batch consumption recorded
 * (not batch-tracked, or sold before Phase 17). No DB writes persist —
 * everything runs inside one rolled-back transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/sales_posting.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Files lint clean');
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg("$root/core/sales_posting.php") . ' 2>&1', $o, $rc);
$rc === 0 ? pass('core/sales_posting.php lints clean') : fail('lint failed: ' . implode(' ', $o));

section('2. Wiring');
$src = file_get_contents("$root/core/sales_posting.php");
strpos($src, 'pos_sale_item_batches') !== false
    ? pass('posSaleCogs() reads pos_sale_item_batches')
    : fail('posSaleCogs() does not reference pos_sale_item_batches');

section('3. Runtime — average-cost fallback unchanged (no batch data)');
$sale = $pdo->query("
    SELECT s.sale_id, SUM(i.quantity * COALESCE(p.cost_price,0)) AS expected_avg_cogs
    FROM pos_sales s
    JOIN pos_sale_items i ON i.sale_id = s.sale_id
    JOIN products p ON p.product_id = i.product_id
    WHERE p.is_service = 0 AND NOT (p.cost_price > p.selling_price AND p.selling_price > 0)
    GROUP BY s.sale_id
    HAVING SUM(i.quantity) > 0
    ORDER BY s.sale_id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    pass('no suitable existing sale to test the fallback path — skipped (n/a)');
} else {
    $cogs = posSaleCogs($pdo, (int)$sale['sale_id']);
    (abs($cogs - (float)$sale['expected_avg_cogs']) < 0.02)
        ? pass("average-cost fallback reconciles for sale #{$sale['sale_id']} (COGS=" . number_format($cogs, 2) . ")")
        : fail("average-cost fallback mismatch: got $cogs, expected {$sale['expected_avg_cogs']}");
}

section('4. Runtime — batch cost preferred over average cost (rolled back)');
$prodRow = $pdo->query("SELECT product_id, cost_price FROM products WHERE status='active' AND is_service=0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whRow   = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$anySale = $pdo->query("SELECT sale_id FROM pos_sales ORDER BY sale_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$prodRow || !$whRow || !$anySale) {
    pass('missing product/warehouse/sale fixtures — skipped (n/a)');
} else {
    $pdo->beginTransaction();

    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];
    $sid = (int)$anySale['sale_id'];

    // A synthetic sale line, deliberately using a product cost_price that
    // would give a DIFFERENT (wrong) answer if the fallback were used.
    $avgCostPrice = 999.99; // clearly distinct from the batch unit_cost below
    $pdo->prepare("UPDATE products SET cost_price = ? WHERE product_id = ?")->execute([$avgCostPrice, $pid]);

    $itemStmt = $pdo->prepare("
        INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, tax_rate, tax_amount, discount_rate, discount_amount, line_total)
        VALUES (?, ?, 'COGS Test Product', 6, 500, 0, 0, 0, 0, 3000)
    ");
    $itemStmt->execute([$sid, $pid]);
    $saleItemId = (int)$pdo->lastInsertId();

    // Two batches feeding this one line: 4 units @ cost 70, 2 units @ cost 90.
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost) VALUES (?, ?, 'COGS-A', 4, 0, 70.00)")->execute([$pid, $wid]);
    $batchA = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost) VALUES (?, ?, 'COGS-B', 2, 0, 90.00)")->execute([$pid, $wid]);
    $batchB = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO pos_sale_item_batches (sale_item_id, batch_id, quantity) VALUES (?, ?, 4)")->execute([$saleItemId, $batchA]);
    $pdo->prepare("INSERT INTO pos_sale_item_batches (sale_item_id, batch_id, quantity) VALUES (?, ?, 2)")->execute([$saleItemId, $batchB]);

    $expectedBatchCogs = (4 * 70.00) + (2 * 90.00); // 280 + 180 = 460
    $expectedAvgCogs   = 6 * $avgCostPrice;          // would be 5999.94 if the fallback wrongly won

    $before = posSaleCogs($pdo, $sid);
    // Isolate this one synthetic line's contribution by removing it after
    // measuring, then re-measuring — avoids needing a brand-new empty sale.
    $pdo->prepare("DELETE FROM pos_sale_item_batches WHERE sale_item_id = ?")->execute([$saleItemId]);
    $pdo->prepare("DELETE FROM pos_sale_items WHERE sale_item_id = ?")->execute([$saleItemId]);
    $after = posSaleCogs($pdo, $sid);
    $lineContribution = round($before - $after, 2);

    (abs($lineContribution - $expectedBatchCogs) < 0.02)
        ? pass("batch-linked line contributed exactly Σ(qty × batch unit_cost) = " . number_format($expectedBatchCogs, 2))
        : fail("batch cost not preferred — line contributed $lineContribution, expected $expectedBatchCogs (avg-cost would have given $expectedAvgCogs)");

    (abs($lineContribution - $expectedAvgCogs) > 1)
        ? pass('confirmed NOT using the average-cost fallback for a batch-linked line (values are clearly different)')
        : fail('batch cost and average cost coincidentally too close to distinguish — test fixture invalid');

    $pdo->rollBack();
}
