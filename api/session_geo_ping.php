<?php
/**
 * api/session_geo_ping.php
 * One-shot, consent-based precise location for the current session's Login
 * History row. Called by header.php's geolocation snippet — fires only when
 * the browser's own permission prompt was accepted, and only once per session
 * (server-side enforced in recordPreciseLocation(), not just client-side).
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$rowId = (int) ($_SESSION['session_row_id'] ?? 0);
if ($rowId <= 0) {
    echo json_encode(['success' => false, 'message' => 'No active session to attach a location to']);
    exit;
}

$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : null;
$acc = isset($_POST['accuracy']) && is_numeric($_POST['accuracy']) ? (int) round((float) $_POST['accuracy']) : null;

if ($lat === null || $lng === null) {
    echo json_encode(['success' => false, 'message' => 'lat/lng required']);
    exit;
}

echo json_encode(['success' => recordPreciseLocation($pdo, $rowId, $lat, $lng, $acc)]);
