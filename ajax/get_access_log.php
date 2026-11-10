<?php
/**
 * ajax/get_access_log.php — the "Access Audit" tab of User Roles & Permissions.
 *
 * Who did what to roles and role assignments: created / edited (with the rights added and removed) /
 * deleted roles, a user's role changed, and the ready-made restaurant roles the system added for you.
 * Read-only, admin-only (same gate as the page). Source: audit_logs, plus the activity_logs lines the
 * system writes for the ready-made restaurant roles.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('user_roles') || !isAdmin()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$labels = [
    'create_role'      => t('Created role'),
    'update_role'      => t('Edited role'),
    'delete_role'      => t('Deleted role'),
    'update_user_role' => t('Changed user role'),
    'assign_role'      => t('Changed user role'),
    'ready_roles'      => t('Restaurant roles'),
];
$limit = 200;

try {
    $in = implode(',', array_fill(0, 5, '?'));
    $sql = "
        SELECT x.* FROM (
            SELECT al.id, al.user_id, al.action, al.description, al.ip_address, al.created_at
              FROM audit_logs al
             WHERE al.action IN ($in)
            UNION ALL
            SELECT ac.id, ac.user_id, 'ready_roles' AS action, COALESCE(NULLIF(ac.description, ''), ac.action) AS description, ac.ip_address, ac.created_at
              FROM activity_logs ac
             WHERE ac.action LIKE 'Restaurant: ready-made role%'
        ) x
        ORDER BY x.created_at DESC, x.id DESC   -- same second: the later row first
        LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute(['create_role', 'update_role', 'delete_role', 'update_user_role', 'assign_role']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $names = [];
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['user_id'], $rows))));
    if ($ids) {
        $u = $pdo->prepare("SELECT user_id, username, TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) AS n
                              FROM users WHERE user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $u->execute($ids);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['user_id']] = $r['n'] !== '' ? $r['n'] : $r['username'];
    }

    $logs = [];
    $count = [];
    $today = date('Y-m-d');
    $todayCount = 0;
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $who = $uid > 0 ? ($names[$uid] ?? ('#' . $uid)) : t('System');
        $logs[] = [
            'user_name'  => $who,
            'action'     => $labels[$r['action']] ?? $r['action'],
            'resource'   => (string)$r['description'],
            'timestamp'  => (string)$r['created_at'],
            'ip_address' => (string)($r['ip_address'] ?? ''),
        ];
        $count[$who] = ($count[$who] ?? 0) + 1;
        if (substr((string)$r['created_at'], 0, 10) === $today) $todayCount++;
    }
    arsort($count);

    echo json_encode([
        'success' => true,
        'logs'    => $logs,
        'stats'   => [
            'total_logs'       => count($logs),
            'today_activities' => $todayCount,
            'most_active_user' => $count ? (string)array_key_first($count) : '—',
            'last_updated'     => $logs ? $logs[0]['timestamp'] : '—',
        ],
    ]);
} catch (Throwable $e) {
    error_log('get_access_log: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load the access log.']);
}
