<?php
/**
 * api/zoom/get_attendee_roles.php — Role -> linked-user picker data for the
 * Zoom meeting Attendees field (plan: zoom.md, attendee-picker follow-up).
 *
 * Returns only roles that are BOTH:
 *   1. Granted view access to 'meetings' (role_permissions.can_view = 1)
 *   2. Have at least one active user linked to an employee record
 * A role with meetings access but zero linkable users, or linkable users but
 * no meetings access, is excluded entirely — every role returned always has
 * at least one pickable user, so the UI never shows a dead-end role.
 *
 * Attendees are stored by employee_id (unchanged schema — meeting_attendees,
 * attendance-marking, and notifications all already key off employee_id), so
 * each returned user carries their employee_id as the value to submit.
 */
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('meetings') && !canEdit('meetings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $rows = $pdo->query("
        SELECT r.role_id, r.role_name, u.employee_id, e.first_name, e.last_name
        FROM roles r
        JOIN role_permissions rp ON rp.role_id = r.role_id AND rp.can_view = 1
        JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'meetings'
        JOIN users u ON u.role_id = r.role_id AND u.is_active = 1 AND u.employee_id IS NOT NULL
        JOIN employees e ON e.employee_id = u.employee_id AND (e.status IS NULL OR e.status != 'deleted')
        ORDER BY r.role_name, e.first_name, e.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $roles = [];
    foreach ($rows as $r) {
        $rid = (int)$r['role_id'];
        if (!isset($roles[$rid])) {
            $roles[$rid] = ['role_id' => $rid, 'role_name' => $r['role_name'], 'users' => []];
        }
        // A role can have multiple users linked to the same employee only in a data-error
        // scenario; dedupe defensively so the checklist never shows a phantom duplicate.
        $eid = (int)$r['employee_id'];
        if (!isset($roles[$rid]['users'][$eid])) {
            $roles[$rid]['users'][$eid] = ['employee_id' => $eid, 'name' => trim($r['first_name'] . ' ' . $r['last_name'])];
        }
    }
    foreach ($roles as &$role) { $role['users'] = array_values($role['users']); }

    echo json_encode(['success' => true, 'roles' => array_values($roles)]);
} catch (Throwable $e) {
    error_log('get_attendee_roles: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load roles.']);
}
