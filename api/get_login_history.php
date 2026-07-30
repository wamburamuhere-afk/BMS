<?php
/**
 * api/get_login_history.php
 * DataTables server-side source for the Login History page.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}
if (!isAdmin()) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

$draw   = intval($_GET['draw']   ?? 1);
$start  = intval($_GET['start']  ?? 0);
$length = intval($_GET['length'] ?? 25);
$search = trim($_GET['search']['value'] ?? '');

// Filters
$userId    = intval($_GET['user_id']    ?? 0);
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to']   ?? '';

$where  = ["1=1"];
$params = [];

if ($userId > 0) {
    $where[] = "us.user_id = ?";
    $params[] = $userId;
}
if (!empty($dateFrom)) {
    $where[] = "DATE(us.login_at) >= ?";
    $params[] = $dateFrom;
}
if (!empty($dateTo)) {
    $where[] = "DATE(us.login_at) <= ?";
    $params[] = $dateTo;
}
if (!empty($search)) {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR us.ip_address LIKE ? OR us.city LIKE ? OR us.country LIKE ? OR us.isp LIKE ? OR us.browser LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s, $s, $s);
}

$whereSQL = implode(' AND ', $where);

try {
    // Total count (no filters)
    $total = $pdo->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn();

    // Filtered count
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM user_sessions us
        LEFT JOIN users u ON u.user_id = us.user_id
        WHERE $whereSQL
    ");
    $countStmt->execute($params);
    $filtered = $countStmt->fetchColumn();

    // Column ordering
    $colMap = [
        1 => 'u.username',
        2 => 'us.ip_address',
        3 => 'us.city',
        4 => 'us.isp',
        6 => 'us.login_at',
        7 => 'us.duration_seconds',
    ];
    $orderCol = intval($_GET['order'][0]['column'] ?? 6);
    $orderDir = (($_GET['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $orderSQL = 'ORDER BY ' . ($colMap[$orderCol] ?? 'us.login_at') . ' ' . $orderDir;

    // Data rows
    $limitSQL = $length > 0 ? "LIMIT " . intval($start) . ", " . intval($length) : "";
    $dataStmt = $pdo->prepare("
        SELECT us.id, us.user_id, us.login_at, us.logout_at, us.duration_seconds,
               us.logout_type, us.ip_address, us.user_agent,
               us.city, us.region, us.country, us.country_code, us.isp, us.org, us.timezone,
               us.browser, us.os, us.device_type,
               u.username, u.email, r.role_name
        FROM user_sessions us
        LEFT JOIN users u ON u.user_id = us.user_id
        LEFT JOIN roles r ON r.role_id = u.role_id
        WHERE $whereSQL
        $orderSQL
        $limitSQL
    ");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
        // Build location: "City, Region, Country" — skip empty parts
        $locParts = array_filter([
            $row['city']    ?? '',
            $row['region']  ?? '',
            $row['country'] ?? '',
        ]);
        $location = implode(', ', $locParts);
        $device = '';
        if (!empty($row['browser'])) {
            $device = $row['browser'];
            if (!empty($row['os']))          $device .= ' on ' . $row['os'];
            if (!empty($row['device_type'])) $device .= ' (' . $row['device_type'] . ')';
        }

        $data[] = [
            'id'               => $row['id'],
            'username'         => $row['username'] ?? 'Deleted User',
            'email'            => $row['email']    ?? '',
            'role_name'        => $row['role_name'] ?? '',
            'ip_address'       => $row['ip_address'] ?? '',
            'location'         => $location,
            'city'             => $row['city']     ?? '',
            'region'           => $row['region']   ?? '',
            'country'          => $row['country']  ?? '',
            'country_code'     => $row['country_code'] ?? '',
            'isp'              => $row['isp']      ?? '',
            'org'              => $row['org']      ?? '',
            'timezone'         => $row['timezone'] ?? '',
            'device'           => $device,
            'browser'          => $row['browser']  ?? '',
            'os'               => $row['os']       ?? '',
            'device_type'      => $row['device_type'] ?? '',
            'login_at'         => $row['login_at'] ?? '',
            'logout_at'        => $row['logout_at'] ?? '',
            'duration_seconds' => $row['duration_seconds'],
            'logout_type'      => $row['logout_type'] ?? '',
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => intval($total),
        'recordsFiltered' => intval($filtered),
        'data'            => $data,
    ]);

} catch (PDOException $e) {
    error_log('get_login_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
}
