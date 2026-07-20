<?php
// Project + warehouse scope enforced below via userCan()/scopeFilterSqlNullable()
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');

    // Auto-migrate missing columns (safe — ignored if already exist)
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN warehouse_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE nip_material_lists ADD COLUMN list_no VARCHAR(50) NULL DEFAULT NULL"); } catch (Exception $e) {}

    $project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;

    // A chosen project_id is verified before use — a hand-crafted request
    // can't read another project's material lists.
    if ($project_id !== null && !userCan('project', $project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
        exit;
    }

    $where  = [];
    $params = [];
    if ($project_id !== null) {
        $where[] = "ml.project_id = ?";
        $params[] = $project_id;
    } else {
        // No project chosen — default-scope to the user's assigned projects
        // (+ untagged) so the list can never leak another project's rows.
        $where[] = "1=1" . scopeFilterSqlNullable('project', 'ml');
    }
    // Warehouse scope always applies, same as every other list in the app —
    // a user restricted to one warehouse inside their project must not see
    // material lists tied to a different warehouse.
    $where[] = "1=1" . scopeFilterSqlNullable('warehouse', 'ml');

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
        WHERE " . implode(' AND ', $where) . "
        GROUP BY ml.id, ml.name, ml.list_no, ml.project_id, p.project_name,
                 ml.warehouse_id, w.warehouse_name, ml.created_at
        ORDER BY ml.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'lists' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
