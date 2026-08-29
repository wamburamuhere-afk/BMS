<?php
/**
 * api/get_login_history_stats.php
 * Live refresh source for the 5 "today" stat cards on Login History
 * (app/constant/settings/login_history.php). Same query the page runs on
 * its own initial load — pulled out here so the cards can be re-fetched
 * after every table redraw instead of staying frozen at page-load values
 * (e.g. "Signed In Now" not moving after an End Session action or an idle
 * timeout while the admin is still looking at the page).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $stats = $pdo->query("
        SELECT
            SUM(CASE WHEN logout_at IS NULL THEN 1 ELSE 0 END)                                      AS signed_in_now,
            SUM(CASE WHEN DATE(login_at) = CURDATE() THEN 1 ELSE 0 END)                              AS signins_today,
            COUNT(DISTINCT CASE WHEN DATE(login_at) = CURDATE() THEN user_id END)                    AS people_today,
            SUM(CASE WHEN logout_type = 'timeout' AND DATE(logout_at) = CURDATE() THEN 1 ELSE 0 END) AS expired_today,
            SUM(CASE WHEN precise_captured_at IS NOT NULL AND DATE(login_at) = CURDATE() THEN 1 ELSE 0 END) AS precise_today
        FROM user_sessions
    ")->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'        => true,
        'signed_in_now'  => intval($stats['signed_in_now']),
        'signins_today'  => intval($stats['signins_today']),
        'people_today'   => intval($stats['people_today']),
        'expired_today'  => intval($stats['expired_today']),
        'precise_today'  => intval($stats['precise_today']),
    ]);
} catch (PDOException $e) {
    error_log('get_login_history_stats: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
