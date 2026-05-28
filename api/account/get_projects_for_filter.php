<?php
/**
 * api/account/get_projects_for_filter.php
 *
 * Lightweight endpoint that returns the list of projects available to the
 * current user, used to populate the Project dropdown filter on the Income
 * Statement (and any other report that needs the same dropdown later).
 *
 * Scope: respects $_SESSION['scope']['projects'] for non-admins. Admins see
 * all active projects.
 *
 * Response:
 *   { success: true, projects: [{ project_id, project_name }, ...] }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    if (isAdmin()) {
        $stmt = $pdo->query("
            SELECT project_id, project_name
              FROM projects
             WHERE status != 'archived' OR status IS NULL
          ORDER BY project_name ASC
        ");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $assigned = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
        if (empty($assigned)) {
            $projects = [];
        } else {
            $ph = implode(',', array_fill(0, count($assigned), '?'));
            $stmt = $pdo->prepare("
                SELECT project_id, project_name
                  FROM projects
                 WHERE project_id IN ($ph)
                   AND (status != 'archived' OR status IS NULL)
              ORDER BY project_name ASC
            ");
            $stmt->execute($assigned);
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    echo json_encode([
        'success'  => true,
        'projects' => $projects,
    ]);
} catch (Throwable $e) {
    error_log('get_projects_for_filter error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
