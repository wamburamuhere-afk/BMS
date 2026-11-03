<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
if (!isAuthenticated()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

require_once __DIR__ . '/../core/dt_order.php';

// Header sorting; S/No = the normal newest-first order (core/dt_order.php).
$order_sql = dtOrderSql($_GET, [
    'user_id'    => 'u.user_id',
    'username'   => 'u.username',
    'full_name'  => "CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))",
    'email'      => 'u.email',
    'role_name'  => 'role_name',
    'is_active'  => 'u.is_active',
    'last_login' => 'u.last_login',
], 'u.user_id DESC', 'u.user_id ASC', 'u.user_id');
if (!isAdmin()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

try {
    // 1. Parameters
    $draw = $_GET['draw'] ?? 1;
    $start = $_GET['start'] ?? 0;
    $length = dt_page_length($_GET['length'] ?? null, 10);
    $search_value = $_GET['search']['value'] ?? '';

    // 2. Base Query
    $base_query = "
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE 1=1
    ";
    
    $params = [];

    // 3. Search
    if (!empty($search_value)) {
        $base_query .= " AND (
            u.username LIKE :search 
            OR u.email LIKE :search 
            OR u.first_name LIKE :search
            OR u.last_name LIKE :search
            OR r.role_name LIKE :search
        )";
        $params[':search'] = "%$search_value%";
    }

    // 4. Counts
    $count_sql = "SELECT COUNT(*) " . $base_query;
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $recordsFiltered = $stmt->fetchColumn();

    $total_sql = "SELECT COUNT(*) FROM users";
    $recordsTotal = $pdo->query($total_sql)->fetchColumn();

    // 5. Fetch Data
    $sql = "
        SELECT 
            u.user_id,
            u.username,
            u.email,
            u.first_name,
            u.last_name,
            u.is_active,
            u.last_login,
            u.role_id,
            COALESCE(r.role_name, u.role, u.user_role) as role_name
        " . $base_query . "
        ORDER BY $order_sql
        LIMIT :start, :length
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':start', (int)$start, PDO::PARAM_INT);
    $stmt->bindValue(':length', (int)$length, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Format
    $data = [];
    foreach ($users as $user) {
        $data[] = [
            'user_id' => $user['user_id'],
            'username' => htmlspecialchars($user['username']),
            'full_name' => htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])),
            'email' => htmlspecialchars($user['email']),
            'role_name' => htmlspecialchars($user['role_name'] ?? 'Unknown'),
            'role_id' => $user['role_id'],
            'is_active' => $user['is_active'],
            'last_login' => $user['last_login']
        ];
    }

    echo json_encode([
        "draw" => intval($draw),
        "recordsTotal" => intval($recordsTotal),
        "recordsFiltered" => intval($recordsFiltered),
        "data" => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
