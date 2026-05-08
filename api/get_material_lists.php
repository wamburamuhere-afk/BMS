<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');

    // Auto-migrate missing columns (safe — ignored if already exist)
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN warehouse_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN list_no VARCHAR(50) NULL DEFAULT NULL"); } catch (Exception $e) {}

    $project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? intval($_GET['project_id']) : null;

    $sql = "
        SELECT
            ml.id,
            ml.name,
            COALESCE(ml.list_no,
                CONCAT('ML-', DATE_FORMAT(ml.created_at,'%Y%m%d'), '-', LPAD(ml.id,4,'0'))
            ) AS list_no,
            ml.project_id,
            COALESCE(p.project_name,'') AS project_name,
            ml.warehouse_id,
            COALESCE(w.warehouse_name,'') AS warehouse_name,
            COUNT(mln.id) AS nip_count,
            ml.created_at
        FROM nip_material_lists ml
        LEFT JOIN projects   p ON ml.project_id   = p.project_id
        LEFT JOIN warehouses w ON ml.warehouse_id = w.warehouse_id
        LEFT JOIN nip_material_list_nips mln ON mln.material_list_id = ml.id
    ";
    if ($project_id !== null) {
        $sql .= " WHERE ml.project_id = " . $project_id;
    }
    $sql .= "
        GROUP BY ml.id, ml.name, ml.list_no, ml.project_id, p.project_name,
                 ml.warehouse_id, w.warehouse_name, ml.created_at
        ORDER BY ml.created_at DESC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'lists' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
