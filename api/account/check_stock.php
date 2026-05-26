<?php
// scope-audit: skip — stock availability check helper for forms; product catalog is global; no project scope needed
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;
$items_json = $_POST['items'] ?? '[]';
$items = json_decode($items_json, true);

if ($warehouse_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'warehouse_id is required']);
    exit;
}

if (!is_array($items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid items payload']);
    exit;
}

try {
    global $pdo;

    // Aggregate by product_id to handle duplicates in the order table.
    $requested = [];
    foreach ($items as $item) {
        $pid = isset($item['product_id']) ? (int)$item['product_id'] : 0;
        if ($pid <= 0) continue;
        $qty = (float)($item['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $requested[$pid] = ($requested[$pid] ?? 0) + $qty;
    }

    if (!$requested) {
        echo json_encode(['success' => true, 'ok' => true, 'shortages' => []]);
        exit;
    }

    $ids = array_keys($requested);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$warehouse_id], $ids);

    $stmt = $pdo->prepare("
        SELECT
            p.product_id,
            p.product_name,
            p.sku,
            COALESCE(ps.stock_quantity, 0) AS stock_quantity
        FROM products p
        LEFT JOIN product_stocks ps
            ON ps.product_id = p.product_id
           AND ps.warehouse_id = ?
        WHERE p.product_id IN ($placeholders)
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stockMap = [];
    foreach ($rows as $r) {
        $stockMap[(int)$r['product_id']] = $r;
    }

    $shortages = [];
    foreach ($requested as $pid => $qtyNeeded) {
        $stockQty = isset($stockMap[$pid]) ? (float)$stockMap[$pid]['stock_quantity'] : 0.0;
        if ($stockQty + 1e-9 < $qtyNeeded) {
            $row = $stockMap[$pid] ?? ['product_id' => $pid, 'product_name' => 'Unknown', 'sku' => null, 'stock_quantity' => 0];
            $shortages[] = [
                'product_id' => (int)$pid,
                'product_name' => (string)($row['product_name'] ?? 'Unknown'),
                'sku' => $row['sku'] ?? null,
                'available' => (float)($row['stock_quantity'] ?? 0),
                'requested' => (float)$qtyNeeded,
                'short_by' => (float)max(0, $qtyNeeded - $stockQty),
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'ok' => count($shortages) === 0,
        'shortages' => $shortages
    ]);
} catch (Exception $e) {
    error_log("Error checking stock: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

