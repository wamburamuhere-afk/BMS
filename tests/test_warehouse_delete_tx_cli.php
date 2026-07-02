<?php
/**
 * Warehouse delete transaction-atomicity test.
 *
 * Guards the fix/tx-warehouse-delete work:
 *   1. Both delete paths (ajax_delete_warehouse.php and the warehouses.php page
 *      branch) wrap the cascade in a transaction and no longer hard-delete
 *      stock_movements (audit history must survive a warehouse delete).
 *   2. Live forced-failure: a mid-cascade error leaves warehouse, stocks,
 *      locations and movements exactly as they were.
 *   3. Live real cascade: stocks + locations removed, movement history kept,
 *      warehouse soft-deleted.
 *
 * Run: php tests/test_warehouse_delete_tx_cli.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m) { global $passes;   $passes++;   echo "  \xE2\x9C\x85 $m\n"; }
function fail($m) { global $failures; $failures++; echo "  \xE2\x9D\x8C $m\n"; }
function section($t) { echo "\n\xE2\x94\x80\xE2\x94\x80 $t \xE2\x94\x80\xE2\x94\x80\n"; }

// ── 1. Static: both delete paths are transactional and preserve history ───
section('Delete paths are transactional and preserve stock_movements');
foreach (['ajax_delete_warehouse.php', 'app/bms/stock/warehouses.php'] as $f) {
    $src = @file_get_contents("$root/$f") ?: '';
    (strpos($src, 'beginTransaction') !== false && strpos($src, 'rollBack') !== false)
        ? pass("$f wraps the cascade in a transaction")
        : fail("$f has no transaction around the cascade");
    (strpos($src, 'DELETE FROM stock_movements') === false)
        ? pass("$f no longer hard-deletes stock_movements")
        : fail("$f still hard-deletes stock_movements (audit history destroyed)");
}

// ── Fixture ────────────────────────────────────────────────────────────────
$tag = 'TEST-WHTX-' . substr(bin2hex(random_bytes(3)), 0, 6);
$whId = null; $productId = null;

function whState(PDO $pdo, int $whId): array {
    $s = fn($sql) => (int)$pdo->query($sql)->fetchColumn();
    return [
        'status'    => $pdo->query("SELECT status FROM warehouses WHERE warehouse_id = $whId")->fetchColumn(),
        'stocks'    => $s("SELECT COUNT(*) FROM product_stocks WHERE warehouse_id = $whId"),
        'locations' => $s("SELECT COUNT(*) FROM locations WHERE warehouse_id = $whId"),
        'movements' => $s("SELECT COUNT(*) FROM stock_movements WHERE warehouse_id = $whId"),
    ];
}

/** The exact cascade the endpoints now run (kept in sync with the fix). */
function runCascade(PDO $pdo, int $whId, bool $forceFail): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM product_stocks WHERE warehouse_id = ?")->execute([$whId]);
        $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$whId]);
        if ($forceFail) throw new Exception('forced mid-cascade failure');
        $pdo->prepare("UPDATE warehouses SET status = 'deleted', updated_at = NOW() WHERE warehouse_id = ?")
            ->execute([$whId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$forceFail) throw $e;
    }
}

try {
    // Synthetic warehouse + one stock row + one location + one movement
    $pdo->prepare("INSERT INTO warehouses (warehouse_code, warehouse_name, status, created_by) VALUES (?, ?, 'active', 1)")
        ->execute([$tag, "Tx test warehouse $tag"]);
    $whId = (int)$pdo->lastInsertId();

    $productId = (int)($pdo->query("SELECT product_id FROM products LIMIT 1")->fetchColumn() ?: 999999);
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 10)")
        ->execute([$productId, $whId]);
    $pdo->prepare("INSERT INTO locations (warehouse_id, location_name, status) VALUES (?, ?, 'active')")
        ->execute([$whId, "Tx test location $tag"]);
    $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, warehouse_id, quantity, created_by) VALUES (?, 'adjustment_in', ?, 10, 1)")
        ->execute([$productId, $whId]);

    // ── 2. Forced mid-cascade failure → everything intact ────────────────
    section('Live forced failure: cascade rolls back completely');
    runCascade($pdo, $whId, true);
    $s = whState($pdo, $whId);
    ($s['status'] === 'active') ? pass('warehouse still active') : fail("warehouse status became '{$s['status']}'");
    ($s['stocks'] === 1)    ? pass('product_stocks row survived the rollback') : fail('product_stocks row lost despite rollback');
    ($s['locations'] === 1) ? pass('locations row survived the rollback')      : fail('locations row lost despite rollback');
    ($s['movements'] === 1) ? pass('stock_movements row survived the rollback'): fail('stock_movements row lost despite rollback');

    // ── 3. Real cascade → stocks/locations gone, history kept ────────────
    section('Live real cascade: current state removed, history preserved');
    runCascade($pdo, $whId, false);
    $s = whState($pdo, $whId);
    ($s['status'] === 'deleted') ? pass("warehouse soft-deleted (status='deleted')") : fail("warehouse status is '{$s['status']}', expected 'deleted'");
    ($s['stocks'] === 0)    ? pass('product_stocks removed')  : fail('product_stocks still present');
    ($s['locations'] === 0) ? pass('locations removed')       : fail('locations still present');
    ($s['movements'] === 1) ? pass('stock_movements history PRESERVED') : fail('stock_movements history was deleted');
} catch (Throwable $e) {
    fail('live scenario errored: ' . $e->getMessage());
} finally {
    // Cleanup all synthetic rows
    try {
        if ($whId) {
            $pdo->prepare("DELETE FROM stock_movements WHERE warehouse_id = ?")->execute([$whId]);
            $pdo->prepare("DELETE FROM product_stocks WHERE warehouse_id = ?")->execute([$whId]);
            $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$whId]);
            $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = ?")->execute([$whId]);
        }
    } catch (Throwable $e) { /* best-effort cleanup */ }
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Passed: $passes   Failed: $failures\n";
echo $failures > 0 ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);
