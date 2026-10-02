<?php
// scope-audit: skip — admin-only user management (same gate as the web Users page)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
mobileUsersBootstrap('GET');

try {
    $rows = $pdo->query("SELECT role_id, role_name, is_admin, description FROM roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['role_id']  = (int)$r['role_id'];
        $r['is_admin'] = (int)$r['is_admin'] === 1 || $r['role_id'] === 1;
    }
    unset($r);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    error_log('mobile/users/roles.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
