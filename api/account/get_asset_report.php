<?php
/**
 * api/account/get_asset_report.php
 *
 * AJAX data source for the Fixed Assets Register — cost / accumulated
 * depreciation / net book value summary, three chart datasets, and per-asset
 * rows. NBV = cost - accumulated_depreciation.
 *
 * Assets are company-wide (the assets table has no project_id), so no project
 * scope applies here — matching the existing asset report.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('asset_report')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$category = $_GET['category'] ?? '';
$status   = $_GET['status'] ?? '';

try {
    global $pdo;

    $params = [];
    $where  = ["1=1"];
    if ($category !== '') { $where[] = "a.category = ?"; $params[] = $category; }
    if ($status !== '')   { $where[] = "a.status = ?";   $params[] = $status; }
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT a.asset_code, a.asset_name,
               COALESCE(a.category, 'Uncategorised')      AS category,
               a.purchase_date, a.location, a.status,
               COALESCE(a.cost, 0)                        AS cost,
               COALESCE(a.accumulated_depreciation, 0)    AS accumulated_depreciation,
               (COALESCE(a.cost,0) - COALESCE(a.accumulated_depreciation,0)) AS nbv
          FROM assets a
         WHERE $where_sql
      ORDER BY a.category ASC, a.asset_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_cost  = array_sum(array_map(fn($r) => (float)$r['cost'], $rows));
    $total_dep   = array_sum(array_map(fn($r) => (float)$r['accumulated_depreciation'], $rows));
    $total_nbv   = $total_cost - $total_dep;

    // Charts: cost by category, NBV by category, count by status
    $costByCat = []; $nbvByCat = []; $byStatus = [];
    foreach ($rows as $r) {
        $c = $r['category'];
        $costByCat[$c] = ($costByCat[$c] ?? 0) + (float)$r['cost'];
        $nbvByCat[$c]  = ($nbvByCat[$c]  ?? 0) + (float)$r['nbv'];
        $s = $r['status'] ?: 'unknown';
        $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
    }
    arsort($costByCat);

    echo json_encode([
        'success' => true,
        'summary' => [
            'asset_count'  => count($rows),
            'total_cost'   => round($total_cost, 2),
            'total_dep'    => round($total_dep, 2),
            'total_nbv'    => round($total_nbv, 2),
        ],
        'charts' => [
            'cost_by_category' => array_map(fn($k,$v) => ['label'=>$k,'value'=>round($v,2)], array_keys($costByCat), array_values($costByCat)),
            'nbv_by_category'  => array_map(fn($k) => ['label'=>$k,'value'=>round($nbvByCat[$k],2)], array_keys($costByCat)),
            'by_status'        => array_map(fn($k,$v) => ['label'=>ucfirst($k),'value'=>$v], array_keys($byStatus), array_values($byStatus)),
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_asset_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
