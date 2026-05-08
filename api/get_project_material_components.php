<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $project_id = intval($_GET['project_id'] ?? 0);
    if (!$project_id) throw new Exception('Invalid project ID.');

    $rows = $pdo->prepare("
        SELECT
            cp.product_id AS component_product_id,
            cp.product_name,
            cp.sku,
            MAX(pac.unit) AS unit,
            ROUND(SUM(pac.qty_per_unit * mln.quantity), 4) AS total_quantity,
            COALESCE(nmcs.status, 'pending') AS status,
            GROUP_CONCAT(DISTINCT COALESCE(ml.project_id, 0) SEPARATOR ',') AS project_ids
        FROM nip_material_list_nips mln
        JOIN nip_material_lists ml ON mln.material_list_id = ml.id
        JOIN product_assembly_components pac ON pac.parent_product_id = mln.nip_product_id
        JOIN products cp ON pac.component_product_id = cp.product_id
        LEFT JOIN nip_material_component_status nmcs ON nmcs.component_product_id = cp.product_id
        WHERE ml.project_id = ?
        GROUP BY cp.product_id, cp.product_name, cp.sku
        ORDER BY cp.product_name ASC
    ");
    $rows->execute([$project_id]);
    $materials = $rows->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'materials' => $materials]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
