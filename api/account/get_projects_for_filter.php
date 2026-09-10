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

// This endpoint is also consumed as an internal partial — the report pages
// include it after sending their own HTML/headers to populate the Project
// dropdown. An unguarded header() there emits a "headers already sent" warning
// that corrupts the JSON, leaving the dropdown empty. Guard it.
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

    // Empty when Projects isn't active for this tenant — see
    // projectsModuleActive() (core/project_scope.php): a switched-off module
    // never deletes existing project rows, and a tenant's own "Enable
    // Projects Module" setting can never override the platform grant back on.
    $projects = projectsForSelect($pdo);

    echo json_encode([
        'success'  => true,
        'projects' => $projects,
    ]);
} catch (Throwable $e) {
    error_log('get_projects_for_filter error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
