<?php
/**
 * api/account/get_stock_adjustments.php
 *
 * Stock Adjustments report (View 4 of the Inventory Report) — manual stock
 * corrections from `stock_movements` (reference_type = 'manual', or any
 * adjustment/correction/damage/expiry/found/theft movement_type).
 * One detail row per product (when, product, in/out, qty, total value, reason).
 *
 * Filters: direction (in|out), reason, product_id, warehouse_id, date range.
 * Project scope (security.md §23) via the warehouse the adjustment belongs to.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('inventory_report')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$direction    = $_GET['direction'] ?? '';   // '' | 'in' | 'out'
$reason       = $_GET['reason']    ?? '';
$product_id   = (isset($_GET['product_id'])   && $_GET['product_id']   !== '') ? (int)$_GET['product_id']   : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;
$date_from    = $_GET['date_from'] ?? date('Y-01-01');
$date_to      = $_GET['date_to']   ?? date('Y-12-31');
$project_id   = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']); exit;
}

// Adjustments = manual reference OR an adjustment-family movement_type.
$adj_in_types  = "'adjustment_in','correction','found','return_in'";
$adj_out_types = "'adjustment_out','damaged','expired','theft','return_out'";
$is_adjustment = "(sm.reference_type = 'manual'
                   OR sm.movement_type IN ($adj_in_types, $adj_out_types))";

$direction_expr = "
    CASE
        WHEN sm.movement_type IN ($adj_out_types) THEN 'OUT'
        ELSE 'IN'
    END";

// Effective date: manual adjustments often leave movement_date NULL and carry
// the date in created_at. Fall back so they aren't lost.
$eff_date = "DATE(COALESCE(sm.movement_date, sm.created_at))";

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["$eff_date BETWEEN ? AND ?", $is_adjustment];

    if ($product_id !== null)   { $where[] = "sm.product_id = ?";   $params[] = $product_id; }
    if ($warehouse_id !== null) { $where[] = "sm.warehouse_id = ?"; $params[] = $warehouse_id; }
    if ($reason !== '')         { $where[] = "sm.reason = ?";       $params[] = $reason; }
    if ($direction === 'out')   { $where[] = "sm.movement_type IN ($adj_out_types)"; }
    elseif ($direction === 'in'){ $where[] = "(sm.movement_type IN ($adj_in_types) OR sm.movement_type NOT IN ($adj_out_types))"; }

    $scope_sql = '';
    if ($project_id !== null) { $where[] = "w.project_id = ?"; $params[] = $project_id; }
    else                      { $scope_sql = scopeFilterSqlNullable('project', 'w'); }
    $where_sql = implode(' AND ', $where) . $scope_sql;

    $base_from = "
          FROM stock_movements sm
          LEFT JOIN products   p ON sm.product_id   = p.product_id
          LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
          LEFT JOIN users      u ON sm.created_by   = u.user_id
    ";

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS adj_count,
               COALESCE(SUM(CASE WHEN sm.movement_type IN ($adj_out_types) THEN 0 ELSE sm.quantity END), 0) AS qty_added,
               COALESCE(SUM(CASE WHEN sm.movement_type IN ($adj_out_types) THEN sm.quantity ELSE 0 END), 0) AS qty_removed
        $base_from
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $sum = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $qty_added   = (float)($sum['qty_added'] ?? 0);
    $qty_removed = (float)($sum['qty_removed'] ?? 0);

    // ── Chart 1: by reason ────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(sm.reason, ''), 'Unspecified') AS name,
               COUNT(*) AS count, COALESCE(SUM(sm.quantity), 0) AS qty
        $base_from
         WHERE $where_sql
      GROUP BY name ORDER BY qty DESC LIMIT 10
    ");
    $stmt->execute($params);
    $by_reason = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: In vs Out counts ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT ($direction_expr) AS name, COUNT(*) AS count
        $base_from
         WHERE $where_sql
      GROUP BY name
    ");
    $stmt->execute($params);
    $by_direction = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT $eff_date        AS movement_date,
               COALESCE(NULLIF(sm.reference_number, ''), '—')        AS reference_number,
               COALESCE(p.product_name, '—')                          AS product_name,
               COALESCE(p.product_code, '')                           AS product_code,
               ($direction_expr)                                      AS direction,
               sm.quantity, COALESCE(sm.unit, '')                     AS unit,
               (sm.quantity * COALESCE(p.cost_price, 0))             AS value,
               COALESCE(w.warehouse_name, 'No Warehouse')             AS warehouse_name,
               COALESCE(NULLIF(sm.reason, ''), '—')                   AS reason,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), ''), 'System') AS recorded_by
        $base_from
         WHERE $where_sql
      ORDER BY $eff_date DESC, sm.movement_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Distinct reasons for the filter dropdown (within current scope/date).
    $stmt = $pdo->prepare("
        SELECT DISTINCT COALESCE(NULLIF(sm.reason, ''), '') AS reason
        $base_from
         WHERE $where_sql AND sm.reason IS NOT NULL AND sm.reason != ''
      ORDER BY reason ASC
    ");
    $stmt->execute($params);
    $reasons = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'reason');

    echo json_encode([
        'success' => true,
        'summary' => [
            'adj_count'   => (int)($sum['adj_count'] ?? 0),
            'qty_added'   => $qty_added,
            'qty_removed' => $qty_removed,
            'net'         => $qty_added - $qty_removed,
        ],
        'charts' => [
            'by_reason'    => $by_reason,
            'by_direction' => $by_direction,
        ],
        'reasons' => $reasons,
        'rows'    => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_stock_adjustments error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
