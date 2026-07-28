<?php
// api/operations/get_project_inventory.php
// Split out of get_project.php: the "Inventory" tab's stock data (purchased/sold
// items, adjustments, movements, stock summary, warehouses) is the genuinely
// expensive part of the project view — several stock_movements aggregations —
// and is only needed once the user opens that tab. Loaded independently so it
// no longer blocks the initial project view render.
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;
$id = $_GET['id'] ?? null;

try {
    // Same project-scope gate as get_project.php — this endpoint stands alone,
    // so it re-checks scope rather than trusting the caller already did.
    if (!userCan('project', (int)$id)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Access denied: this project is not in your scope"]);
        exit;
    }

    $inventory = [
        "purchased_items" => (function($pdo, $id) {
            $stmt = $pdo->prepare("
                SELECT poi.*, p.product_name, p.sku, p.unit as product_unit, po.order_number, po.status as po_status
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.product_id
                JOIN purchase_orders po ON poi.purchase_order_id = po.purchase_order_id
                WHERE po.project_id = ?
                ORDER BY po.order_date DESC
            ");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        })($pdo, $id),
        "sold_items" => (function($pdo, $id) {
            $stmt = $pdo->prepare("
                SELECT soi.*, p.product_name, p.sku, p.unit as product_unit, so.order_number, so.status as so_status
                FROM sales_order_items soi
                JOIN products p ON soi.product_id = p.product_id
                JOIN sales_orders so ON soi.order_id = so.sales_order_id
                WHERE so.project_id = ?
                ORDER BY so.order_date DESC
            ");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        })($pdo, $id),
        "adjustments" => (function($pdo, $id) {
            $stmt = $pdo->prepare("
                SELECT sm.*, p.product_name, p.sku, w.warehouse_name, u.username as adjusted_by
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.product_id
                LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                LEFT JOIN users u ON sm.created_by = u.user_id
                WHERE sm.project_id = ? AND sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft', 'adjustment', 'stock_adjustment')
                ORDER BY sm.movement_date DESC, sm.created_at DESC
            ");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        })($pdo, $id),
        "movements" => (function($pdo, $id) {
            $stmt = $pdo->prepare("
                SELECT sm.*, p.product_name, p.sku, w.warehouse_name
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.product_id
                LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                WHERE sm.project_id = ?
                ORDER BY sm.movement_date DESC, sm.created_at DESC
            ");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        })($pdo, $id),
        "stock_summary" => (function($pdo, $id) {
            // Calculate current balance for this project specifically
            $stmt = $pdo->prepare("
                SELECT
                    p.product_id,
                    p.product_name,
                    p.sku,
                    p.unit,
                    p.cost_price as default_cost,
                    c.category_name,
                    w.warehouse_name,
                    SUM(CASE
                        WHEN sm.movement_type IN ('purchase_in', 'adjustment_in', 'transfer_in', 'return_in', 'found', 'production_in') THEN sm.quantity
                        WHEN sm.movement_type IN ('sale_out', 'adjustment_out', 'transfer_out', 'return_out', 'damaged', 'expired', 'theft', 'production_out') THEN -sm.quantity
                        ELSE 0
                    END) as project_balance,
                    SUM(CASE
                        WHEN sm.movement_type IN ('purchase_in', 'adjustment_in', 'transfer_in', 'return_in', 'found', 'production_in') THEN (sm.quantity * sm.unit_cost)
                        WHEN sm.movement_type IN ('sale_out', 'adjustment_out', 'transfer_out', 'return_out', 'damaged', 'expired', 'theft', 'production_out') THEN -(sm.quantity * sm.unit_cost)
                        ELSE 0
                    END) as project_value
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                WHERE sm.project_id = ?
                GROUP BY p.product_id, w.warehouse_id
                HAVING project_balance != 0
                ORDER BY p.product_name ASC
            ");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        })($pdo, $id),
        "warehouses" => (function($pdo, $id) {
            // Phase 6 (pos_upgrade_plan.md): a user assigned to this
            // project doesn't automatically see every one of its
            // warehouses — only the ones they're actually granted.
            $stmt = $pdo->prepare("
                SELECT w.*, u.username as creator_name
                FROM warehouses w
                LEFT JOIN users u ON w.created_by = u.user_id
                WHERE w.project_id = ?" . scopeFilterSqlNullable('warehouse', 'w') . "
                ORDER BY w.warehouse_name ASC
            ");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        })($pdo, $id)
    ];

    echo json_encode(["success" => true, "inventory" => $inventory]);

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
