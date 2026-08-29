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

// ── Period is the authoritative date filter — same pattern as app/activity_log.php,
//    so From/To and the server never disagree. 'custom' keeps the raw From/To
//    inputs; everything else is computed here.
$period = $_GET['period'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$today = date('Y-m-d');
switch ($period) {
    case 'today': $dateFrom = $today; $dateTo = $today; break;
    case 'week':  $dateFrom = date('Y-m-d', strtotime('monday this week')); $dateTo = $today; break;
    case 'month': $dateFrom = date('Y-m-01'); $dateTo = $today; break;
    case 'year':  $dateFrom = date('Y-01-01'); $dateTo = $today; break;
    case 'all':   $dateFrom = ''; $dateTo = ''; break;
    case 'custom': /* keep the From/To inputs as submitted */ break;
    default: $period = 'all'; $dateFrom = ''; $dateTo = '';
}

// Other filters
$userId   = intval($_GET['user_id'] ?? 0);
$ended    = $_GET['ended']    ?? '';   // '', 'active', 'manual', 'timeout', 'superseded', 'revoked', 'admin_ended'
$device   = $_GET['device']   ?? '';   // '', 'Desktop', 'Mobile', 'Tablet'
$country  = $_GET['country']  ?? '';
$locSrc   = $_GET['location_source'] ?? ''; // '', 'precise', 'approximate', 'none'

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
if ($ended === 'active') {
    $where[] = "us.logout_at IS NULL";
} elseif (in_array($ended, ['manual', 'timeout', 'superseded', 'revoked', 'admin_ended'], true)) {
    $where[] = "us.logout_type = ?";
    $params[] = $ended;
}
if (in_array($device, ['Desktop', 'Mobile', 'Tablet'], true)) {
    $where[] = "us.device_type = ?";
    $params[] = $device;
}
if (!empty($country)) {
    $where[] = "us.country = ?";
    $params[] = $country;
}
if ($locSrc === 'precise') {
    $where[] = "us.precise_captured_at IS NOT NULL";
} elseif ($locSrc === 'approximate') {
    $where[] = "us.precise_captured_at IS NULL AND us.city IS NOT NULL AND us.city != '' AND us.city != 'Local'";
} elseif ($locSrc === 'none') {
    $where[] = "us.precise_captured_at IS NULL AND (us.city IS NULL OR us.city = '' OR us.city = 'Local')";
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
        7 => 'COALESCE(us.logout_at, us.last_seen_at, us.login_at)',
        8 => 'us.duration_seconds',
    ];
    $orderCol = intval($_GET['order'][0]['column'] ?? 6);
    $orderDir = (($_GET['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $orderSQL = 'ORDER BY ' . ($colMap[$orderCol] ?? 'us.login_at') . ' ' . $orderDir;

    // Data rows
    $limitSQL = $length > 0 ? "LIMIT " . intval($start) . ", " . intval($length) : "";
    $dataStmt = $pdo->prepare("
        SELECT us.id, us.user_id, us.login_at, us.last_seen_at, us.logout_at, us.duration_seconds,
               us.logout_type, us.revoked_by, us.revoked_at, us.ip_address, us.user_agent,
               us.city, us.region, us.country, us.country_code, us.isp, us.org, us.timezone,
               us.precise_lat, us.precise_lng, us.precise_accuracy_m, us.precise_captured_at,
               us.browser, us.os, us.device_type,
               u.username, u.email, r.role_name, ru.username AS revoked_by_name
        FROM user_sessions us
        LEFT JOIN users u  ON u.user_id  = us.user_id
        LEFT JOIN roles r  ON r.role_id  = u.role_id
        LEFT JOIN users ru ON ru.user_id = us.revoked_by
        WHERE $whereSQL
        $orderSQL
        $limitSQL
    ");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
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

        $hasPrecise = !empty($row['precise_captured_at']);
        $locationSource = $hasPrecise ? 'precise'
            : ((!empty($row['city']) && $row['city'] !== 'Local') ? 'approximate' : 'none');

        $data[] = [
            'id'                 => $row['id'],
            'username'           => $row['username'] ?? 'Deleted User',
            'email'              => $row['email']    ?? '',
            'role_name'          => $row['role_name'] ?? '',
            'ip_address'         => $row['ip_address'] ?? '',
            'location'           => $location,
            'city'               => $row['city']     ?? '',
            'region'             => $row['region']   ?? '',
            'country'            => $row['country']  ?? '',
            'country_code'       => $row['country_code'] ?? '',
            'isp'                => $row['isp']      ?? '',
            'org'                => $row['org']      ?? '',
            'timezone'           => $row['timezone'] ?? '',
            'device'             => $device,
            'browser'            => $row['browser']  ?? '',
            'os'                 => $row['os']       ?? '',
            'device_type'        => $row['device_type'] ?? '',
            'login_at'           => $row['login_at'] ?? '',
            'last_seen_at'       => $row['last_seen_at'] ?? '',
            'logout_at'          => $row['logout_at'] ?? '',
            'duration_seconds'   => $row['duration_seconds'],
            'logout_type'        => $row['logout_type'] ?? '',
            'revoked_by_name'    => $row['revoked_by_name'] ?? '',
            'revoked_at'         => $row['revoked_at'] ?? '',
            'location_source'    => $locationSource,
            'precise_lat'        => $row['precise_lat'] !== null ? (float) $row['precise_lat'] : null,
            'precise_lng'        => $row['precise_lng'] !== null ? (float) $row['precise_lng'] : null,
            'precise_accuracy_m' => $row['precise_accuracy_m'],
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
