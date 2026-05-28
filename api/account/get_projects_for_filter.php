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

    // Use the canonical scopeFilterSql() helper so this endpoint is scoped
    // exactly like every other project-scoped list page in BMS.
    //   - admin                          -> '' (sees all active projects)
    //   - non-admin with assignments     -> ' AND project_id IN (...) '
    //   - non-admin with no assignments  -> ' AND 0 ' (default-deny)
    $stmt = $pdo->query("
        SELECT project_id, project_name
          FROM projects
         WHERE (status != 'archived' OR status IS NULL)
           " . scopeFilterSql('project', 'projects') . "
      ORDER BY project_name ASC
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'projects' => $projects,
    ]);
} catch (Throwable $e) {
    error_log('get_projects_for_filter error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
