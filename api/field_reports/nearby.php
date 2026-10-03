<?php
// GET lat, lng — the place name of the caller's nearest earlier visit within 300 m, so
// the GPS button can suggest "Kariakoo" instead of only coordinates. Staff: only their
// own visits; admins: anyone's (frScopeUserId). No external map service is called —
// no location leaves the system (customer_visits_ux_plan 2.4).
require_once __DIR__ . '/_common.php';

$lat = $_GET['lat'] ?? ''; $lng = $_GET['lng'] ?? '';
if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    frJson(['success' => false, 'message' => t('Choose a valid option.')], 422);
}
$lat = (float)$lat; $lng = (float)$lng;
$radius = 300.0;                                   // metres
$dLat = $radius / 111320.0;                        // bounding box first (index-friendly)
$dLng = $radius / (111320.0 * max(0.01, cos(deg2rad($lat))));

$where = "status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL
          AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?";
$params = [$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng];
$scope = frScopeUserId(null);
if ($scope !== null) { $where .= " AND user_id = ?"; $params[] = $scope; }

try {
    $s = $pdo->prepare("SELECT location, latitude, longitude FROM field_visits WHERE $where ORDER BY visit_id DESC LIMIT 200");
    $s->execute($params);
    $best = null;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Haversine distance in metres.
        $p1 = deg2rad($lat); $p2 = deg2rad((float)$r['latitude']);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad((float)$r['longitude'] - $lng) / 2) ** 2;
        $d = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
        if ($d <= $radius && ($best === null || $d < $best['distance_m'])) $best = ['location' => $r['location'], 'distance_m' => (int)round($d)];
    }
    frJson(['success' => true, 'place' => $best]);
} catch (PDOException $e) {
    error_log('field_reports/nearby: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
