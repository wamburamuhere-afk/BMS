<?php
/**
 * api/save_combo_component.php
 *
 * Phase 23 (pos_upgrade_plan.md §8) — add/update one component of a combo
 * product (reuses product_assembly_components — see the migration's comment
 * for why that's safe alongside the existing service/NIP usage of this table).
 * POST: id (blank = create), product_id (the combo/parent), component_product_id,
 *       qty_per_unit
 * Permission: canEdit('products')
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('products')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$product_id = (int)($_POST['product_id'] ?? 0);
$component_product_id = (int)($_POST['component_product_id'] ?? 0);
$qty_per_unit = (float)($_POST['qty_per_unit'] ?? 0);

if (!$product_id || !$component_product_id || $qty_per_unit <= 0) {
    echo json_encode(['success' => false, 'message' => 'A component product and a quantity greater than zero are required.']);
    exit;
}
if ($component_product_id === $product_id) {
    echo json_encode(['success' => false, 'message' => 'A combo cannot include itself as a component.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$parent) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

    $stmt = $pdo->prepare("SELECT product_name, unit FROM products WHERE product_id = ?");
    $stmt->execute([$component_product_id]);
    $component = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$component) { echo json_encode(['success' => false, 'message' => 'Component product not found']); exit; }

    $dupSql = "SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = ? AND component_product_id = ?" . ($id > 0 ? " AND id != ?" : "");
    $dupParams = $id > 0 ? [$product_id, $component_product_id, $id] : [$product_id, $component_product_id];
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute($dupParams);
    if ($dupStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'This product is already a component of this combo.']);
        exit;
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE product_assembly_components SET component_product_id = ?, component_name = ?, unit = ?, qty_per_unit = ?, total_qty = ?, updated_at = NOW() WHERE id = ? AND parent_product_id = ?")
            ->execute([$component_product_id, $component['product_name'], $component['unit'], $qty_per_unit, $qty_per_unit, $id, $product_id]);
        $message = 'Combo component updated.';
    } else {
        $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit, total_qty, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$product_id, $component_product_id, $component['product_name'], $component['unit'], $qty_per_unit, $qty_per_unit]);
        $id = (int)$pdo->lastInsertId();
        $message = 'Combo component added.';
    }

    logActivity($pdo, $_SESSION['user_id'], "Set combo component '{$component['product_name']}' (x$qty_per_unit) for combo: {$parent['product_name']}");
    echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);

} catch (PDOException $e) {
    error_log('save_combo_component: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
