<?php
/**
 * API: warehouse-scoped batch/lot list — "Batches" tab on warehouse_view.php.
 * ----------------------------------------------------------------------------
 * Simple POS only, same discipline as every other new surface built this
 * round (pos_credit_receivables_plan.md-style gate). Reads product_batches
 * DIRECTLY — the exact same table core/pos_batch_consumption.php consumes on
 * every sale (FEFO) and restores on every void/return, the same table
 * app/bms/product/product_view.php's own read-only batch section reads, and
 * the same table dashboard.php's expiring-batch alert reads. No parallel
 * calculation, no cache — this is a straight SELECT against the one source
 * of truth, so this list can never disagree with any of those.
 *
 * GET: warehouse_id (required)
 * Permission: userCan('warehouse', $warehouse_id)
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

header('Content-Type: application/json');

if (!isAuthenticated())      { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('warehouses'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This view is only available in Simple POS mode.')]); exit; }

$warehouseId = (int)($_GET['warehouse_id'] ?? 0);
if ($warehouseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('A valid warehouse is required.')]);
    exit;
}
if (!userCan('warehouse', $warehouseId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

try {
    global $pdo;

    // Same field list, same ordering (soonest-to-expire first, nulls last,
    // then newest batch first) as product_view.php's own read-only batch
    // table — so a batch never sorts or displays differently between the
    // two pages.
    $stmt = $pdo->prepare("
        SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.expiry_date, pb.manufacturing_date,
               pb.quantity_received, pb.quantity_remaining, pb.unit_cost, pb.wholesale_price, pb.selling_price,
               pb.receipt_id, pb.created_at,
               p.product_name, p.sku,
               DATEDIFF(pb.expiry_date, CURDATE()) AS days_remaining
        FROM product_batches pb
        JOIN products p ON p.product_id = pb.product_id
        WHERE pb.warehouse_id = ?
        ORDER BY (pb.expiry_date IS NULL), pb.expiry_date ASC, pb.batch_id DESC
    ");
    $stmt->execute([$warehouseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['quantity_received']  = round((float)$r['quantity_received'], 3);
        $r['quantity_remaining'] = round((float)$r['quantity_remaining'], 3);
        $r['unit_cost']          = round((float)$r['unit_cost'], 2);
        $r['wholesale_price']    = $r['wholesale_price'] !== null ? round((float)$r['wholesale_price'], 2) : null;
        $r['selling_price']      = $r['selling_price'] !== null ? round((float)$r['selling_price'], 2) : null;
        $r['days_remaining']     = $r['days_remaining'] !== null ? (int)$r['days_remaining'] : null;

        // Same three-state classification product_view.php's own batch
        // table already uses (exhausted / expired / active) — identical
        // thresholds, so a batch is never "Active" on one page and
        // "Expired" on the other.
        $isExhausted = $r['quantity_remaining'] <= 0.0001;
        $isExpired   = $r['days_remaining'] !== null && $r['days_remaining'] <= 0;
        $r['status'] = $isExhausted ? 'exhausted' : ($isExpired ? 'expired' : 'active');
    }
    unset($r);

    echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
} catch (Throwable $e) {
    error_log('get_warehouse_batches: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
