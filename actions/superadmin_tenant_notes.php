<?php
/**
 * actions/superadmin_tenant_notes.php — save free-text operator notes for one tenant.
 *
 * Notes are visible to all superadmin operators. The last-editor's superadmin_id
 * and timestamp are stored so any operator can see who wrote them.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$tenantId = (int)($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

$notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 1000);

try {
    $ctrl = getControlPdo();

    $row = $ctrl->prepare("SELECT id, subdomain FROM tenants WHERE id = ?");
    $row->execute([$tenantId]);
    $tenant = $row->fetch(\PDO::FETCH_ASSOC);

    if (!$tenant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
        exit;
    }

    $sa = currentSuperadmin();

    $ctrl->prepare(
        "UPDATE tenants
            SET notes             = ?,
                notes_updated_at  = NOW(),
                notes_updated_by  = ?
          WHERE id = ?"
    )->execute([$notes !== '' ? $notes : null, $sa['id'], $tenantId]);

    logTenantAdminAction(
        $tenantId,
        (string)$tenant['subdomain'],
        'note_update',
        'Operator notes updated'
    );

    echo json_encode(['success' => true, 'message' => 'Notes saved.']);

} catch (\Throwable $e) {
    error_log('superadmin_tenant_notes.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
