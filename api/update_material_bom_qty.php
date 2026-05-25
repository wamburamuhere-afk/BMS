<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');

    if (!canEdit('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to edit material BOM quantities');
    }

    $component_id = intval($_POST['component_product_id'] ?? 0);
    $rows         = $_POST['rows'] ?? [];

    if (!$component_id) throw new Exception('Invalid component.');
    if (empty($rows) || !is_array($rows)) throw new Exception('No rows provided.');

    // Phase E — project-scope gate on the component product
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('products', 'product_id', $component_id);
    }

    $pdo->beginTransaction();

    $pacStmt = $pdo->prepare("
        UPDATE product_assembly_components
        SET qty_per_unit = ?
        WHERE parent_product_id = ? AND component_product_id = ?
    ");
    $nipStmt = $pdo->prepare("
        UPDATE nip_material_list_nips
        SET quantity = ?
        WHERE nip_product_id = ?
    ");

    $updated = 0;
    foreach ($rows as $row) {
        $parent_id = intval($row['parent_product_id'] ?? 0);
        $qty_pac   = floatval($row['qty_per_unit'] ?? 0);
        $qty_nip   = floatval($row['nip_qty'] ?? 0);

        if (!$parent_id || $qty_pac <= 0 || $qty_nip <= 0) continue;

        $pacStmt->execute([$qty_pac, $parent_id, $component_id]);
        $nipStmt->execute([$qty_nip, $parent_id]);
        $updated++;
    }

    if (!$updated) throw new Exception('No valid rows to update.');

    $pdo->commit();

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], "Updated material BOM quantities for component #$component_id ($updated rows)");

    echo json_encode(['success' => true, 'message' => "Updated $updated component specification(s)."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
