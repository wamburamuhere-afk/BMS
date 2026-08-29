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

// user_sessions timestamps are stored/read in the app's configured timezone
// (Africa/Dar_es_Salaam by default — see roots.php), but MySQL returns bare
// "Y-m-d H:i:s" strings with no timezone marker. A bare string handed to JS's
// Date() constructor is parsed in the BROWSER's own local timezone, not the
// server's — so on any viewer whose machine isn't also set to that zone,
// "new Date(login_at) - Date.now()" is wrong by exactly the offset between
// the two. This is what made the live "so far" duration ticker for Active
// rows get stuck at 0 (or worse) instead of counting up: the browser saw a
// login time that looked hours in the future relative to its own clock.
// Appending the real UTC offset turns the string into unambiguous ISO-8601,
// which Date() parses correctly on every viewer regardless of their machine's
// own timezone setting.
$__tzName = function_exists('get_setting') ? get_setting('timezone', 'Africa/Dar_es_Salaam') : 'Africa/Dar_es_Salaam';
try {
    $__tzOffset = (new DateTime('now', new DateTimeZone($__tzName)))->format('P');
} catch (Throwable $e) {
    $__tzOffset = '+00:00';
}
if (!function_exists('withTzOffset')) {
    function withTzOffset(?string $dt): ?string {
        global $__tzOffset;
        if (empty($dt)) return null;
        return str_replace(' ', 'T', $dt) . $__tzOffset;
    }
}

// 'Unfamiliar': the first time THIS user has ever signed in from this
// country+device combination, as of this login. Purely informational — flags
// a row for the admin to look at, never triggers any action on its own (see
// "must be admin physically" in the changelog for this feature). Country+
// device rather than raw IP: matches the page's own Approximate-location
// caveat that IP/city jitters on mobile networks, so IP alone would flag
// normal travel constantly. Defined once and reused in both SELECT and an
// optional WHERE so the two can never drift apart.
if (!defined('UNFAMILIAR_SQL')) {
    define('UNFAMILIAR_SQL', "(
        us.country IS NOT NULL AND us.country NOT IN ('', 'Local')
        AND us.device_type IS NOT NULL AND us.device_type != ''
        AND NOT EXISTS (
            SELECT 1 FROM user_sessions us2
             WHERE us2.user_id = us.user_id
               AND us2.id <> us.id
               AND us2.login_at < us.login_at
               AND us2.country = us.country
               AND us2.device_type = us.device_type
        )
    )");
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
$ended    = $_GET['ended']    ?? '';   // '', 'active', 'manual', 'timeout', 'revoked', 'admin_ended', 'blocked'
$device   = $_GET['device']   ?? '';   // '', 'Desktop', 'Mobile', 'Tablet'
$country  = $_GET['country']  ?? '';
$locSrc   = $_GET['location_source'] ?? ''; // '', 'precise', 'approximate', 'none'
$unfamiliarOnly = ($_GET['unfamiliar'] ?? '') === '1';

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
} elseif (in_array($ended, ['manual', 'timeout', 'revoked', 'admin_ended', 'blocked'], true)) {
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
if ($unfamiliarOnly) {
    $where[] = UNFAMILIAR_SQL;
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
               u.username, u.email, u.is_active AS user_is_active, r.role_name, ru.username AS revoked_by_name,
               " . UNFAMILIAR_SQL . " AS is_unfamiliar
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
            'user_id'            => $row['user_id'],
            'username'           => $row['username'] ?? 'Deleted User',
            'email'              => $row['email']    ?? '',
            'role_name'          => $row['role_name'] ?? '',
            'user_is_active'     => $row['username'] === null ? null : (bool) $row['user_is_active'],
            'is_unfamiliar'      => (bool) $row['is_unfamiliar'],
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
            'login_at'           => withTzOffset($row['login_at']),
            'last_seen_at'       => withTzOffset($row['last_seen_at']),
            'logout_at'          => withTzOffset($row['logout_at']),
            'duration_seconds'   => $row['duration_seconds'],
            'logout_type'        => $row['logout_type'] ?? '',
            'revoked_by_name'    => $row['revoked_by_name'] ?? '',
            'revoked_at'         => withTzOffset($row['revoked_at']),
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
