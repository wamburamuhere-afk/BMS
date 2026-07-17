<?php
// scope-audit: skip — NIP materials form data; project_id required param
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/warehouse_scope.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized access');
    }

    $project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? intval($_GET['project_id']) : null;
    if ($project_id !== null && !userCan('project', $project_id)) {
        throw new Exception('Access denied: this project is not in your assigned scope.');
    }

    // 1. Active NIP products — scoped to project when project_id is given
    if ($project_id) {
        $nipStmt = $pdo->prepare("
            SELECT p.product_id, p.product_name, p.sku, p.cost_price, p.selling_price,
                   p.assembly_quantity, p.warehouse_id, p.unit,
                   w.warehouse_name,
                   COALESCE(w.project_id, 0) AS project_id,
                   COALESCE(pr.project_name, '') AS project_name,
                   (SELECT COUNT(*) FROM product_assembly_components pac WHERE pac.parent_product_id = p.product_id) AS component_count
            FROM products p
            LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
            LEFT JOIN projects pr ON w.project_id = pr.project_id
            WHERE p.is_service = 1 AND p.status = 'active'
              AND COALESCE(w.project_id, 0) = ?" . scopeFilterSqlNullable('warehouse', 'w') . "
            ORDER BY p.product_name ASC
        ");
        $nipStmt->execute([$project_id]);
    } else {
        $nipStmt = $pdo->query("
            SELECT p.product_id, p.product_name, p.sku, p.cost_price, p.selling_price,
                   p.assembly_quantity, p.warehouse_id, p.unit,
                   w.warehouse_name,
                   COALESCE(w.project_id, 0) AS project_id,
                   COALESCE(pr.project_name, '') AS project_name,
                   (SELECT COUNT(*) FROM product_assembly_components pac WHERE pac.parent_product_id = p.product_id) AS component_count
            FROM products p
            LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
            LEFT JOIN projects pr ON w.project_id = pr.project_id
            WHERE p.is_service = 1 AND p.status = 'active'" . scopeFilterSqlNullable('warehouse', 'w') . "
            ORDER BY p.product_name ASC
        ");
    }
    $nip_products = $nipStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Warehouses — project-scoped or all non-project, plus the user's own
    // warehouse grant (Phase 6, pos_upgrade_plan.md).
    if ($project_id) {
        $whStmt = $pdo->prepare("
            SELECT warehouse_id, warehouse_name, project_id
            FROM warehouses
            WHERE status = 'active' AND project_id = ?" . scopeFilterSqlNullable('warehouse') . "
            ORDER BY warehouse_name ASC
        ");
        $whStmt->execute([$project_id]);
    } else {
        $whStmt = $pdo->prepare("
            SELECT warehouse_id, warehouse_name, project_id
            FROM warehouses
            WHERE status = 'active' AND (project_id IS NULL OR project_id = 0)" . scopeFilterSqlNullable('warehouse') . "
            ORDER BY warehouse_name ASC
        ");
        $whStmt->execute();
    }
    $warehouses = $whStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Active projects (for the project selector) — scoped to the user's
    // assigned projects.
    $projects = $pdo->query("
        SELECT project_id, project_name FROM projects WHERE status = 'active'" . scopeFilterSql('project', 'projects') . " ORDER BY project_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'nip_products' => $nip_products,
        'warehouses'   => $warehouses,
        'projects'     => $projects
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
