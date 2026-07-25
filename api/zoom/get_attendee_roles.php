<?php
/**
 * api/zoom/get_attendee_roles.php — Role -> user picker data for the Zoom
 * meeting Attendees field (plan: zoom.md, attendee-picker follow-up).
 *
 * Returns only roles that are BOTH:
 *   1. Have 'meetings' view access — either an explicit role_permissions row
 *      (can_view = 1), OR roles.is_admin = 1 (the same "full system access"
 *      bypass canView()/isAdmin() apply everywhere else in the app — a role
 *      like Managing Director can be flagged is_admin=1 with NO row at all in
 *      role_permissions, and still correctly have access to everything).
 *   2. Have at least one active user.
 * A role with meetings access but zero users, or users but no meetings
 * access, is excluded entirely — every role returned always has at least one
 * pickable user, so the UI never shows a dead-end role.
 *
 * No employee-record link is required (dropped per follow-up — a Zoom
 * attendee only needs a BMS login, not HR employee data). Zoom meetings store
 * attendees by user_id (meeting_attendees.user_id, added alongside the
 * existing employee_id column used by in-person meetings — see migration
 * 2026_07_25_meeting_attendees_user_id.php). Each returned user carries their
 * user_id as the value to submit.
 */
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('meetings') && !canEdit('meetings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $rows = $pdo->query("
        SELECT r.role_id, r.role_name, u.user_id, u.username, u.first_name, u.last_name
        FROM roles r
        JOIN users u ON u.role_id = r.role_id AND u.is_active = 1
        WHERE r.is_admin = 1
           OR EXISTS (
                SELECT 1 FROM role_permissions rp
                JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'meetings'
                WHERE rp.role_id = r.role_id AND rp.can_view = 1
           )
        ORDER BY r.role_name, u.first_name, u.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $roles = [];
    foreach ($rows as $r) {
        $rid = (int)$r['role_id'];
        if (!isset($roles[$rid])) {
            $roles[$rid] = ['role_id' => $rid, 'role_name' => $r['role_name'], 'users' => []];
        }
        $uid = (int)$r['user_id'];
        $name = trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['username'];
        $roles[$rid]['users'][$uid] = ['user_id' => $uid, 'name' => $name];
    }
    foreach ($roles as &$role) { $role['users'] = array_values($role['users']); }

    echo json_encode(['success' => true, 'roles' => array_values($roles)]);
} catch (Throwable $e) {
    error_log('get_attendee_roles: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load roles.']);
}
