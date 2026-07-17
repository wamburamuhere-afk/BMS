<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/warehouse_scope.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized access');
    }
    $user_id = intval($_SESSION['user_id']);

    if (!canCreate('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to add NIP materials');
    }

    $product_id  = intval($_POST['product_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $project_id  = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;

    if (!$product_id)   throw new Exception('Non-Inventory product is required.');
    if (!$warehouse_id) throw new Exception('Warehouse is required.');

    // Phase E — project-scope gate on the NIP product being defined
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('products', 'product_id', $product_id);
    }

    // Verify the target product exists and is a non-inventory/service product
    $stmt = $pdo->prepare("SELECT product_id, product_name, assembly_quantity FROM products WHERE product_id = ? AND is_service = 1");
    $stmt->execute([$product_id]);
    $nip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$nip) throw new Exception('Non-Inventory product not found.');

    // Verify warehouse exists
    $stmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
    $stmt->execute([$warehouse_id]);
    if (!$stmt->fetch()) throw new Exception('Selected warehouse not found or inactive.');
    if (!userCan('warehouse', $warehouse_id)) {
        throw new Exception('Access denied: this warehouse is not in your assigned scope.');
    }

    $components = $_POST['components'] ?? [];
    if (empty($components) || !is_array($components)) {
        throw new Exception('At least one material component is required.');
    }

    $assembly_qty = floatval($nip['assembly_quantity'] ?? 1);
    if ($assembly_qty <= 0) $assembly_qty = 1;

    $pdo->beginTransaction();

    $insStmt = $pdo->prepare("
        INSERT INTO product_assembly_components
            (parent_product_id, component_product_id, unit, qty_per_unit, total_qty)
        VALUES (?, ?, ?, ?, ?)
    ");

    $inserted = 0;
    foreach ($components as $comp) {
        $comp_product_id = intval($comp['product_id'] ?? 0);
        if (!$comp_product_id) continue;

        // Verify component product exists in chosen warehouse
        $cStmt = $pdo->prepare("
            SELECT p.product_id FROM products p
            INNER JOIN warehouses w ON p.warehouse_id = w.warehouse_id
            WHERE p.product_id = ? AND p.warehouse_id = ?
            LIMIT 1
        ");
        $cStmt->execute([$comp_product_id, $warehouse_id]);
        if (!$cStmt->fetch()) {
            // Still allow if product exists even if not warehouse-bound (some products may not be warehouse-locked)
            $cStmt2 = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ?");
            $cStmt2->execute([$comp_product_id]);
            if (!$cStmt2->fetch()) continue;
        }

        $unit      = trim($comp['unit'] ?? 'EA');
        $qty_unit  = floatval($comp['qty_per_unit'] ?? 1);
        $total_qty = $assembly_qty * $qty_unit;

        $insStmt->execute([$product_id, $comp_product_id, $unit, $qty_unit, $total_qty]);
        $inserted++;
    }

    if ($inserted === 0) {
        throw new Exception('No valid material components were provided.');
    }

    // Recalculate cost_price from ALL components (existing + newly added)
    $costStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pac.qty_per_unit * p.cost_price), 0)
        FROM product_assembly_components pac
        JOIN products p ON pac.component_product_id = p.product_id
        WHERE pac.parent_product_id = ?
    ");
    $costStmt->execute([$product_id]);
    $new_cost = floatval($costStmt->fetchColumn());

    $pdo->prepare("UPDATE products SET cost_price = ?, warehouse_id = ? WHERE product_id = ?")
        ->execute([$new_cost, $warehouse_id, $product_id]);

    $pdo->commit();

    logActivity($pdo, $user_id, "Added {$inserted} material(s) to NIP product ID {$product_id}: {$nip['product_name']}");

    echo json_encode([
        'success'   => true,
        'message'   => "{$inserted} material(s) added successfully to \"{$nip['product_name']}\".",
        'new_cost'  => $new_cost,
        'inserted'  => $inserted
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
