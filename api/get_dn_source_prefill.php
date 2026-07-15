<?php
// File: api/get_dn_source_prefill.php
// Given a Sales Order or Customer LPO picked on dn_outbound.php's in-form
// "Sales Order (Optional)" / "Customer LPO (Optional)" dropdowns, returns the
// remaining-to-deliver items plus whatever the source document already
// carries (warehouse, project, delivery address) to prefill the DN form —
// same eligibility + remaining-quantity logic as the existing ?order=/
// ?lpo_id= URL-arrival paths in dn_outbound.php itself. Nothing here is
// mandatory on the DN form; this only supplies defaults the user can still
// edit freely.
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!in_array($type, ['order', 'lpo'], true) || $id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    global $pdo;

    if ($type === 'order') {
        $sostmt = $pdo->prepare("SELECT * FROM sales_orders WHERE sales_order_id = ? AND status IN ('approved', 'processing', 'shipped')");
        $sostmt->execute([$id]);
        $so = $sostmt->fetch(PDO::FETCH_ASSOC);
        if (!$so) {
            echo json_encode(['success' => false, 'message' => 'Sales Order not found or not eligible']);
            exit;
        }

        $items = [];
        $soi_stmt = $pdo->prepare("
            SELECT soi.order_item_id, soi.product_id, soi.product_name,
                   (soi.quantity - soi.quantity_delivered) AS remaining, p.unit
            FROM sales_order_items soi
            LEFT JOIN products p ON soi.product_id = p.product_id
            WHERE soi.order_id = ? AND soi.product_id IS NOT NULL
        ");
        $soi_stmt->execute([$id]);
        foreach ($soi_stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if ((float)$o['remaining'] > 0.0001) {
                $items[] = [
                    'order_item_id' => $o['order_item_id'],
                    'product_id'    => $o['product_id'],
                    'product_name'  => $o['product_name'],
                    'remaining'     => (float)$o['remaining'],
                    'unit'          => $o['unit'] ?: 'pcs',
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => [
            'customer_id'      => (int)$so['customer_id'],
            'project_id'       => (int)($so['project_id'] ?? 0),
            'warehouse_id'     => (int)($so['warehouse_id'] ?? 0),
            'delivery_address' => $so['shipping_address'] ?? '',
            'items'            => $items,
        ]]);
    } else {
        $lstmt = $pdo->prepare("SELECT * FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
        $lstmt->execute([$id]);
        $lpo = $lstmt->fetch(PDO::FETCH_ASSOC);
        if (!$lpo || !in_array($lpo['status'], ['approved', 'partially_fulfilled'], true)) {
            echo json_encode(['success' => false, 'message' => 'Customer LPO not found or not eligible']);
            exit;
        }

        $iistmt = $pdo->prepare("
            SELECT loi.product_id, loi.product_name, loi.quantity, p.unit
            FROM customer_lpo_items loi
            LEFT JOIN products p ON loi.product_id = p.product_id
            WHERE loi.lpo_id = ? AND loi.product_id IS NOT NULL
        ");
        $iistmt->execute([$id]);
        $ordered = $iistmt->fetchAll(PDO::FETCH_ASSOC);

        $dstmt = $pdo->prepare("
            SELECT di.product_id, SUM(di.quantity_delivered) AS delivered
            FROM delivery_items di
            JOIN deliveries d ON di.delivery_id = d.delivery_id
            WHERE d.customer_lpo_id = ? AND d.status != 'cancelled'
            GROUP BY di.product_id
        ");
        $dstmt->execute([$id]);
        $delivered_by_product = [];
        foreach ($dstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $delivered_by_product[$r['product_id']] = (float)$r['delivered'];
        }

        $items = [];
        foreach ($ordered as $o) {
            $remaining = (float)$o['quantity'] - ($delivered_by_product[$o['product_id']] ?? 0);
            if ($remaining > 0.0001) {
                $items[] = ['product_id' => $o['product_id'], 'product_name' => $o['product_name'], 'remaining' => $remaining, 'unit' => $o['unit'] ?: 'pcs'];
            }
        }

        echo json_encode(['success' => true, 'data' => [
            'customer_id'      => (int)$lpo['customer_id'],
            'project_id'       => (int)($lpo['project_id'] ?? 0),
            'warehouse_id'     => (int)($lpo['warehouse_id'] ?? 0),
            'delivery_address' => '',
            'items'            => $items,
        ]]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
