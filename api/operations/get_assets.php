<?php
// api/operations/get_assets.php
//
// Server-side DataTables feed for the asset register (Asset Register & PPE
// Schedule, Phase 5). Adds book accumulated depreciation + net book value
// (from the latest posted depreciation_entries, falling back to a live
// DepreciationService calc), custodian name, condition, and a location filter.
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_depreciation_service.php';
require_once __DIR__ . '/../../core/asset_settings.php';

global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}
if (!canView('assets')) {
    http_response_code(403);
    echo json_encode(["error" => "Permission denied"]);
    exit;
}

try {
    $draw   = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start  = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;

    $category    = $_GET['category'] ?? '';
    $status      = $_GET['status'] ?? '';
    $location    = $_GET['location'] ?? '';
    $search_term = $_GET['search_term'] ?? '';

    $where  = ["a.status != 'deleted'"];
    $params = [];

    if ($category) { $where[] = "a.category = ?"; $params[] = $category; }
    if ($status)   { $where[] = "a.status = ?";   $params[] = $status; }
    if ($location) { $where[] = "a.location LIKE ?"; $params[] = "%$location%"; }
    if ($search_term) {
        $where[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR a.location LIKE ? OR a.serial_number LIKE ?)";
        array_push($params, "%$search_term%", "%$search_term%", "%$search_term%", "%$search_term%");
    }
    $where_clause = implode(" AND ", $where);

    $total_records = $pdo->query("SELECT COUNT(*) FROM assets WHERE status != 'deleted'")->fetchColumn();

    $filtered_stmt = $pdo->prepare("SELECT COUNT(*) FROM assets a WHERE $where_clause");
    $filtered_stmt->execute($params);
    $filtered_records = $filtered_stmt->fetchColumn();

    $data_stmt = $pdo->prepare("
        SELECT a.*,
               TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS custodian_name,
               u.username AS custodian_username
          FROM assets a
          LEFT JOIN users u ON u.user_id = a.custodian_id
         WHERE $where_clause
      ORDER BY a.created_at DESC
         LIMIT $start, $length
    ");
    $data_stmt->execute($params);
    $assets = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Book accumulated depreciation + NBV per asset on this page ───────────
    $ids = array_map(fn($a) => (int)$a['asset_id'], $assets);
    $accMap = [];
    if ($ids) {
        $in = implode(',', $ids);
        // Latest posted book entry per asset.
        $rows = $pdo->query("
            SELECT de.asset_id, de.accumulated, de.closing_nbv
              FROM depreciation_entries de
              JOIN (SELECT asset_id, MAX(period_end) mpe
                      FROM depreciation_entries
                     WHERE area='book' AND asset_id IN ($in)
                  GROUP BY asset_id) m
                ON m.asset_id = de.asset_id AND m.mpe = de.period_end
             WHERE de.area='book'
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $accMap[(int)$r['asset_id']] = ['accumulated' => (float)$r['accumulated'], 'nbv' => (float)$r['closing_nbv']];
        }

        // Fallback for assets with no posted entry yet: live calc from book area.
        $missing = array_values(array_diff($ids, array_keys($accMap)));
        if ($missing) {
            $timing = getAssetSettings($pdo)['depreciation_timing'];
            $today  = date('Y-m-d');
            $minq = $pdo->query("
                SELECT d.asset_id, a.cost, d.method, d.useful_life, d.rate,
                       d.salvage_value, d.start_date, d.opening_accum_bf
                  FROM asset_depreciation_areas d
                  JOIN assets a ON a.asset_id = d.asset_id
                 WHERE d.area='book' AND d.asset_id IN (" . implode(',', $missing) . ")
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($minq as $row) {
                $calc = calcAreaDepreciation($row, (float)$row['cost'], $today, $timing);
                $accMap[(int)$row['asset_id']] = ['accumulated' => $calc['accumulated'], 'nbv' => $calc['nbv']];
            }
        }
    }
    foreach ($assets as &$a) {
        $aid = (int)$a['asset_id'];
        if (isset($accMap[$aid])) {
            $a['accum_dep_book'] = $accMap[$aid]['accumulated'];
            $a['nbv_book']       = $accMap[$aid]['nbv'];
        } else {
            // No depreciation configured (e.g. Land): NBV = cost.
            $a['accum_dep_book'] = 0.0;
            $a['nbv_book']       = (float)$a['cost'];
        }
    }
    unset($a);

    $stats = $pdo->query("SELECT
        COUNT(*) as total_count,
        SUM(cost) as total_cost,
        COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_count,
        COUNT(DISTINCT category) as categories_count
        FROM assets WHERE status != 'deleted'")->fetch(PDO::FETCH_ASSOC);

    $categories = $pdo->query("SELECT DISTINCT category FROM assets WHERE category IS NOT NULL AND category != '' AND status != 'deleted' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $assets,
        "stats" => [
            "total_count" => $stats['total_count'] ?: 0,
            "total_cost" => $stats['total_cost'] ?: 0,
            "maintenance_count" => $stats['maintenance_count'] ?: 0,
            "categories_count" => $stats['categories_count'] ?: 0
        ],
        "categories" => $categories
    ]);

} catch (Exception $e) {
    error_log("get_assets error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
