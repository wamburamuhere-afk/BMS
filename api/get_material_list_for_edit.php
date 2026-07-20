<?php
// Project scope enforced below via assertScopeForRecord()
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');

    $id = intval($_GET['id'] ?? 0);
    if (!$id) throw new Exception('Invalid ID');

    // Blocks a non-admin from loading another project's list into the edit
    // modal — mirrors update_material_list.php's existing gate.
    assertScopeForRecord('nip_material_lists', 'id', $id);

    // Warehouse-scope gate — a user restricted to one warehouse must not
    // load a list belonging to a different warehouse in the same project.
    $whChk = $pdo->prepare("SELECT warehouse_id FROM nip_material_lists WHERE id = ?");
    $whChk->execute([$id]);
    $curWarehouseId = $whChk->fetchColumn();
    if (!empty($curWarehouseId) && function_exists('userCan') && !userCan('warehouse', (int)$curWarehouseId)) {
        http_response_code(403);
        throw new Exception('Access denied: this material list is not in your warehouse scope.');
    }

    // Auto-migrate: add warehouse_id and list_no columns if not yet present
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN warehouse_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN list_no VARCHAR(50) NULL DEFAULT NULL"); } catch (Exception $e) {}


    $stmt = $pdo->prepare("
        SELECT ml.id, ml.name,
               COALESCE(ml.list_no,
                   CONCAT('ML-', DATE_FORMAT(ml.created_at,'%Y%m%d'), '-', LPAD(ml.id,4,'0'))
               ) AS list_no,
               ml.project_id,  ml.warehouse_id,
               COALESCE(p.project_name,'')   AS project_name,
               COALESCE(w.warehouse_name,'') AS warehouse_name
        FROM nip_material_lists ml
        LEFT JOIN projects   p ON ml.project_id   = p.project_id
        LEFT JOIN warehouses w ON ml.warehouse_id = w.warehouse_id
        WHERE ml.id = ?
    ");
    $stmt->execute([$id]);
    $list = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$list) throw new Exception('Material list not found');

    $nipStmt = $pdo->prepare("
        SELECT mln.id, mln.nip_product_id, mln.quantity, p.product_name, p.warehouse_id
        FROM nip_material_list_nips mln
        JOIN products p ON mln.nip_product_id = p.product_id
        WHERE mln.material_list_id = ?
        ORDER BY mln.id ASC
    ");
    $nipStmt->execute([$id]);
    $list['nips'] = $nipStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'list' => $list]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
