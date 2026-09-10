<?php
/**
 * api/account/get_warehouses_for_filter.php
 *
 * Lightweight endpoint that returns the list of warehouses available to the
 * current user, used to populate the Warehouse dropdown filter on Income
 * Statement / Balance Sheet / Cash Flow (mirrors get_projects_for_filter.php).
 *
 * Optional ?project_id=N narrows to that project's own linked warehouses
 * (warehouses.project_id) PLUS every warehouse not tied to any project — the
 * same "assigned OR untagged" shape scopeFilterSqlNullable() already uses
 * elsewhere, so picking a project never hides a general-purpose warehouse that
 * simply isn't tagged to one.
 *
 * Scope: respects $_SESSION['scope']['warehouses'] for non-admins (via
 * scopeFilterSql('warehouse', ...)). Admins see all active warehouses.
 *
 * Response:
 *   { success: true, warehouses: [{ warehouse_id, warehouse_name }, ...] }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    // A switched-off Warehouses module never deletes existing warehouse rows, so
    // without this guard the dropdown would keep showing warehouses the tenant
    // can no longer use at all. Mirrors get_projects_for_filter.php's guard.
    if (!tenantFeatureEnabled('warehouses')) {
        echo json_encode(['success' => true, 'warehouses' => []]);
        exit;
    }

    $project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;
    $params = [];
    $projectCond = '';
    if ($project_id !== null) {
        $projectCond = ' AND (project_id = ? OR project_id IS NULL)';
        $params[] = $project_id;
    }

    $stmt = $pdo->prepare("
        SELECT warehouse_id, warehouse_name
          FROM warehouses
         WHERE status = 'active'
           $projectCond
           " . scopeFilterSql('warehouse', 'warehouses') . "
      ORDER BY warehouse_name ASC
    ");
    $stmt->execute($params);
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'warehouses' => $warehouses,
    ]);
} catch (Throwable $e) {
    error_log('get_warehouses_for_filter error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
