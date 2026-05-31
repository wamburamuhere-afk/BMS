<?php
/**
 * api/account/get_stock_movements.php
 *
 * Stock Movements ledger (View 2 of the Inventory Report) — every IN/OUT from
 * the `stock_movements` table. Empty movement_type rows (legacy POS sales) are
 * normalised via reference_type so they classify correctly as OUT.
 *
 * Filters: direction (in|out), movement_type, product_id, warehouse_id, date range.
 * Project scope (security.md §23) via the warehouse the movement belongs to.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('inventory_report')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$direction     = $_GET['direction']     ?? '';   // '' | 'in' | 'out'
$movement_type = $_GET['movement_type'] ?? '';
$product_id    = (isset($_GET['product_id'])   && $_GET['product_id']   !== '') ? (int)$_GET['product_id']   : null;
$warehouse_id  = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;
$date_from     = $_GET['date_from'] ?? date('Y-01-01');
$date_to       = $_GET['date_to']   ?? date('Y-12-31');
$project_id    = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']); exit;
}

// Canonical IN / OUT type lists. `norm_type` resolves legacy empty types.
$in_types  = "'purchase_in','adjustment_in','transfer_in','return_in','production_in','found','correction'";
$out_types = "'sale_out','adjustment_out','transfer_out','return_out','production_out','damaged','expired','theft','issue_out'";

$norm_type = "
    CASE
        WHEN sm.movement_type IS NOT NULL AND sm.movement_type != '' THEN sm.movement_type
        WHEN sm.reference_type = 'pos_sale'       THEN 'sale_out'
        WHEN sm.reference_type = 'purchase_order' THEN 'purchase_in'
        WHEN sm.reference_type = 'stock_transfer' THEN 'transfer_in'
        ELSE 'other'
    END";

$direction_expr = "
    CASE
        WHEN ($norm_type) IN ($in_types)  THEN 'IN'
        WHEN ($norm_type) IN ($out_types) THEN 'OUT'
        ELSE 'OTHER'
    END";

// Effective date: many legacy rows (manual adjustments) leave movement_date
// NULL and carry the date in created_at. Fall back so they aren't lost.
$eff_date = "DATE(COALESCE(NULLIF(sm.movement_date, '0000-00-00'), sm.created_at))";

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["$eff_date BETWEEN ? AND ?"];

    if ($product_id !== null)   { $where[] = "sm.product_id = ?";   $params[] = $product_id; }
    if ($warehouse_id !== null) { $where[] = "sm.warehouse_id = ?"; $params[] = $warehouse_id; }
    if ($movement_type !== '')  { $where[] = "($norm_type) = ?";    $params[] = $movement_type; }
    if ($direction === 'in')    { $where[] = "($norm_type) IN ($in_types)"; }
    elseif ($direction === 'out') { $where[] = "($norm_type) IN ($out_types)"; }

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
        SELECT
            COUNT(*) AS movement_count,
            COALESCE(SUM(CASE WHEN ($norm_type) IN ($in_types)  THEN sm.quantity ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN ($norm_type) IN ($out_types) THEN sm.quantity ELSE 0 END), 0) AS total_out
        $base_from
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $sum = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total_in  = (float)($sum['total_in'] ?? 0);
    $total_out = (float)($sum['total_out'] ?? 0);

    // ── Chart 1: timeline (monthly IN vs OUT) ─────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT($eff_date, '%Y-%m') AS label,
               COALESCE(SUM(CASE WHEN ($norm_type) IN ($in_types)  THEN sm.quantity ELSE 0 END), 0) AS in_qty,
               COALESCE(SUM(CASE WHEN ($norm_type) IN ($out_types) THEN sm.quantity ELSE 0 END), 0) AS out_qty
        $base_from
         WHERE $where_sql
      GROUP BY label ORDER BY label ASC LIMIT 24
    ");
    $stmt->execute($params);
    $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: by movement type ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT ($norm_type) AS name, COUNT(*) AS count, COALESCE(SUM(sm.quantity), 0) AS qty
        $base_from
         WHERE $where_sql
      GROUP BY name ORDER BY qty DESC
    ");
    $stmt->execute($params);
    $by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT $eff_date        AS movement_date,
               ($norm_type)      AS movement_type,
               ($direction_expr) AS direction,
               COALESCE(p.product_name, '—')                       AS product_name,
               COALESCE(p.product_code, '')                         AS product_code,
               COALESCE(w.warehouse_name, 'No Warehouse')           AS warehouse_name,
               sm.quantity, COALESCE(sm.unit, '')                   AS unit,
               COALESCE(sm.total_cost, 0)                           AS value,
               sm.stock_after,
               COALESCE(NULLIF(sm.reference_number, ''), '—')       AS reference_number,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), ''), 'System') AS recorded_by
        $base_from
         WHERE $where_sql
      ORDER BY $eff_date DESC, sm.movement_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_in'       => $total_in,
            'total_out'      => $total_out,
            'net_change'     => $total_in - $total_out,
            'movement_count' => (int)($sum['movement_count'] ?? 0),
        ],
        'charts' => [
            'timeline' => $timeline,
            'by_type'  => $by_type,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_stock_movements error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
