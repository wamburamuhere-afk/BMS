<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');

    if (!canDelete('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete material components');
    }

    $component_id = intval($_POST['component_product_id'] ?? 0);
    if (!$component_id) throw new Exception('Invalid component.');

    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM nip_material_component_status WHERE component_product_id = ?")
        ->execute([$component_id]);

    $pdo->prepare("DELETE FROM product_assembly_components WHERE component_product_id = ?")
        ->execute([$component_id]);

    $pdo->commit();

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], "Removed material component #$component_id from all BOMs");

    echo json_encode(['success' => true, 'message' => 'Component removed from all material specifications.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
