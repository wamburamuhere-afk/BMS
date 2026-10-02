<?php
// scope-audit: skip — admin-only user management (same gate as the web Users page)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
mobileUsersBootstrap('GET');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

try {
    $where = ['1=1']; $params = [];
    if ($search !== '') {
        $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR r.role_name LIKE ?)";
        array_push($params, ...array_fill(0, 5, '%' . $search . '%'));
    }
    if ($status === 'active')   $where[] = 'u.is_active = 1';
    if ($status === 'inactive') $where[] = 'u.is_active = 0';
    $w = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE $w");
    $cnt->execute($params);
    $st = $pdo->prepare("SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.phone, u.role_id,
                                COALESCE(r.role_name, u.role, u.user_role) AS role_name, u.is_active, u.last_login, u.avatar, u.created_at
                           FROM users u LEFT JOIN roles r ON r.role_id = u.role_id
                          WHERE $w ORDER BY u.is_active DESC, u.first_name, u.last_name LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = array_map(fn($u) => mobileUserShape($pdo, $u), $st->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true, 'total' => (int)$cnt->fetchColumn(), 'limit' => $limit, 'offset' => $offset,
        'user_limit_reached' => function_exists('tenantWithinUserLimit') ? !tenantWithinUserLimit($pdo) : false,
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('mobile/users/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
