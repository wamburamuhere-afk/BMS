<?php
/**
 * ONE endpoint for every location dropdown (Select2-compatible).
 *
 *   GET api/location/options.php?level=countries
 *   GET api/location/options.php?level=regions&parent_id=<country_id>
 *   GET api/location/options.php?level=districts&parent_id=<region_id>
 *   GET api/location/options.php?level=wards&parent_id=<district_id>
 *   GET api/location/options.php?level=villages&parent_id=<ward_id>
 *   (+ optional &q=search)
 *
 * Replaces the scattered get_regions/get_districts/get_councils/get_wards
 * endpoints for all new work. Read-only reference data — auth required,
 * no further permission needed.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/Location/bootstrap.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$level     = $_GET['level'] ?? '';
$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
$q         = isset($_GET['q']) ? trim((string)$_GET['q']) : null;

$repo = new LocationRepository($pdo);

try {
    switch ($level) {
        case 'countries':
            $rows = $repo->countries($q);
            break;
        case 'regions':
            if (!$parent_id) throw new InvalidArgumentException('parent_id (country) is required');
            $rows = $repo->regionsOf($parent_id, $q);
            break;
        case 'districts':
            if (!$parent_id) throw new InvalidArgumentException('parent_id (region) is required');
            $rows = $repo->districtsOf($parent_id, $q);
            break;
        case 'wards':
            if (!$parent_id) throw new InvalidArgumentException('parent_id (district) is required');
            $rows = $repo->wardsOf($parent_id, $q);
            break;
        case 'villages':
            if (!$parent_id) throw new InvalidArgumentException('parent_id (ward) is required');
            $rows = $repo->villagesOf($parent_id, $q);
            break;
        default:
            throw new InvalidArgumentException('Invalid level. Use countries|regions|districts|wards|villages');
    }

    // Select2 shape: id + text (+ extras the cascade component uses).
    $results = array_map(static function ($r) {
        $item = ['id' => (int)$r['id'], 'text' => $r['name']];
        if (isset($r['code'])) $item['code'] = $r['code'];
        if (isset($r['has_regions'])) $item['has_regions'] = (int)$r['has_regions'];
        return $item;
    }, $rows);

    echo json_encode(['success' => true, 'results' => $results]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('location options error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
