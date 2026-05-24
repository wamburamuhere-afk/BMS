<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized access');
    }
    $user_id      = intval($_SESSION['user_id']);

    if (!canDelete('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete NIP components');
    }

    $component_id = intval($_POST['component_id'] ?? 0);
    if (!$component_id) throw new Exception('Component ID is required.');

    // Fetch the component to get the parent product ID
    $stmt = $pdo->prepare("SELECT id, parent_product_id FROM product_assembly_components WHERE id = ?");
    $stmt->execute([$component_id]);
    $comp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp) throw new Exception('Component not found.');

    $parent_id = intval($comp['parent_product_id']);

    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM product_assembly_components WHERE id = ?")->execute([$component_id]);

    // Recalculate cost_price from remaining components
    $costStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pac.qty_per_unit * p.cost_price), 0)
        FROM product_assembly_components pac
        JOIN products p ON pac.component_product_id = p.product_id
        WHERE pac.parent_product_id = ?
    ");
    $costStmt->execute([$parent_id]);
    $new_cost = floatval($costStmt->fetchColumn());

    $pdo->prepare("UPDATE products SET cost_price = ? WHERE product_id = ?")->execute([$new_cost, $parent_id]);

    $pdo->commit();

    logActivity($pdo, $user_id, "Deleted component ID {$component_id} from NIP product ID {$parent_id}");

    echo json_encode([
        'success'  => true,
        'message'  => 'Component removed successfully.',
        'new_cost' => $new_cost
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
