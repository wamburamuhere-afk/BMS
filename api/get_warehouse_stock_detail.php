<?php
// File: api/get_warehouse_stock_detail.php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/warehouse_scope.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $warehouse_id = intval($_GET['warehouse_id'] ?? 0);
    $project_id   = intval($_GET['project_id']   ?? 0);

    if ($warehouse_id <= 0) throw new Exception('Warehouse ID is required.');
    if ($project_id <= 0)   throw new Exception('Project ID is required.');

    // Validate warehouse belongs to project
    $wh = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE warehouse_id = ? AND project_id = ?");
    $wh->execute([$warehouse_id, $project_id]);
    if (!$wh->fetch()) throw new Exception('Warehouse not found in this project.');

    // Phase 6 (pos_upgrade_plan.md): a non-admin may only view stock for a
    // warehouse in their assigned scope.
    if (!userCan('warehouse', $warehouse_id)) {
        throw new Exception('Access denied: this warehouse is not in your assigned scope.');
    }

    // ── 1. STOCK SUMMARY — from product_stocks (current real stock) ──
    $stmt = $pdo->prepare("
        SELECT
            ps.product_id,
            p.product_name,
            p.sku,
            p.unit,
            c.category_name,
            ps.stock_quantity,
            ps.reserved_quantity,
            ps.available_quantity
        FROM product_stocks ps
        JOIN products p          ON ps.product_id  = p.product_id
        LEFT JOIN categories c   ON p.category_id  = c.category_id
        WHERE ps.warehouse_id = ?
        ORDER BY p.product_name ASC
    ");
    $stmt->execute([$warehouse_id]);
    $stock_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 2. MATERIALS RECEIVED — from GRN receipt_items ───────────────
    $stmt = $pdo->prepare("
        SELECT
            ri.item_id,
            ri.receipt_id,
            ri.product_id,
            ri.product_name,
            ri.sku,
            ri.quantity_received,
            ri.unit,
            pr.receipt_number,
            pr.receipt_date,
            pr.status,
            s.supplier_name
        FROM receipt_items ri
        JOIN purchase_receipts pr ON ri.receipt_id    = pr.receipt_id
        LEFT JOIN suppliers s     ON pr.supplier_id   = s.supplier_id
        WHERE pr.warehouse_id = ?
          AND (pr.project_id = ? OR EXISTS (
              SELECT 1 FROM purchase_orders po
              WHERE po.purchase_order_id = pr.purchase_order_id
              AND po.project_id = ?
          ))
        ORDER BY pr.receipt_date DESC, ri.item_id ASC
    ");
    $stmt->execute([$warehouse_id, $project_id, $project_id]);
    $received = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. MATERIALS ISSUED — from delivery_items (DN) ───────────────
    $stmt = $pdo->prepare("
        SELECT
            di.delivery_item_id,
            di.delivery_id,
            di.product_id,
            di.product_name,
            di.sku,
            di.quantity_delivered,
            di.unit,
            d.delivery_number,
            d.delivery_date,
            d.status as dn_status,
            s.supplier_name
        FROM delivery_items di
        JOIN deliveries d     ON di.delivery_id  = d.delivery_id
        LEFT JOIN suppliers s ON d.supplier_id   = s.supplier_id
        WHERE d.warehouse_id = ?
          AND d.project_id   = ?
        ORDER BY d.delivery_date DESC, di.delivery_item_id ASC
    ");
    $stmt->execute([$warehouse_id, $project_id]);
    $issued = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 4. ADJUSTMENTS — from stock_movements (adj types only) ───────
    $stmt = $pdo->prepare("
        SELECT
            sm.movement_id,
            sm.product_id,
            sm.movement_type,
            sm.quantity,
            sm.unit,
            sm.movement_date,
            sm.created_at,
            sm.notes,
            sm.reference_number,
            p.product_name,
            p.sku,
            u.username as adjusted_by
        FROM stock_movements sm
        JOIN products p      ON sm.product_id  = p.product_id
        LEFT JOIN users u    ON sm.created_by  = u.user_id
        WHERE sm.warehouse_id = ?
          AND sm.project_id   = ?
          AND sm.movement_type IN (
              'adjustment_in','adjustment_out','correction',
              'damaged','expired','found','theft','adjustment','stock_adjustment'
          )
        ORDER BY sm.created_at DESC
    ");
    $stmt->execute([$warehouse_id, $project_id]);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 5. MOVEMENT HISTORY — all movements ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            sm.movement_id,
            sm.product_id,
            sm.movement_type,
            sm.quantity,
            sm.unit,
            sm.movement_date,
            sm.created_at,
            sm.reference_number,
            sm.notes,
            p.product_name,
            p.sku
        FROM stock_movements sm
        JOIN products p ON sm.product_id = p.product_id
        WHERE sm.warehouse_id = ?
          AND sm.project_id   = ?
        ORDER BY sm.created_at DESC
    ");
    $stmt->execute([$warehouse_id, $project_id]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => [
            'stock_summary' => $stock_summary,
            'received'      => $received,
            'issued'        => $issued,
            'adjustments'   => $adjustments,
            'movements'     => $movements,
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
