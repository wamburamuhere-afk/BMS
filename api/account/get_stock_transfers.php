<?php
/**
 * api/account/get_stock_transfers.php
 *
 * Stock Transfers report (View 3 of the Inventory Report) — warehouse → warehouse
 * moves from `stock_transfers` + `stock_transfer_items`. One detail row per
 * product line (when, which product, qty, received qty, total value, route).
 *
 * Filters: from_warehouse_id, to_warehouse_id, product_id, status, date range.
 * Project scope (security.md §23): a transfer is visible if EITHER endpoint
 * warehouse is in the user's scope.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('inventory_report')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$from_wh    = (isset($_GET['from_warehouse_id']) && $_GET['from_warehouse_id'] !== '') ? (int)$_GET['from_warehouse_id'] : null;
$to_wh      = (isset($_GET['to_warehouse_id'])   && $_GET['to_warehouse_id']   !== '') ? (int)$_GET['to_warehouse_id']   : null;
$product_id = (isset($_GET['product_id'])        && $_GET['product_id']        !== '') ? (int)$_GET['product_id']        : null;
$status     = $_GET['status']    ?? '';
$date_from  = $_GET['date_from'] ?? date('Y-01-01');
$date_to    = $_GET['date_to']   ?? date('Y-12-31');
$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']); exit;
}
if ($from_wh !== null && !userCan('warehouse', $from_wh)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied: the source warehouse is not in your assigned scope.']); exit;
}
if ($to_wh !== null && !userCan('warehouse', $to_wh)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied: the destination warehouse is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["st.transfer_date BETWEEN ? AND ?"];

    if ($from_wh    !== null) { $where[] = "st.from_warehouse_id = ?"; $params[] = $from_wh; }
    if ($to_wh      !== null) { $where[] = "st.to_warehouse_id = ?";   $params[] = $to_wh; }
    if ($product_id !== null) { $where[] = "sti.product_id = ?";       $params[] = $product_id; }
    if ($status     !== '')   { $where[] = "st.status = ?";            $params[] = $status; }

    // Scope: visible if EITHER endpoint warehouse is in scope. An explicit
    // project filter narrows both endpoints to that project; an explicit
    // from/to warehouse is already verified above and needs no extra scoping
    // (that endpoint being in-scope already satisfies the "either" rule).
    if ($project_id !== null) {
        $where[]  = "(fw.project_id = ? OR tw.project_id = ?)";
        $params[] = $project_id;
        $params[] = $project_id;
    }
    $scope_sql = '';
    if ($from_wh === null && $to_wh === null) {
        $f = scopeFilterSqlNullable('warehouse', 'fw');
        $t = scopeFilterSqlNullable('warehouse', 'tw');
        // Either endpoint in scope (admins → both helpers return '' → no clause).
        if ($f !== '' || $t !== '') {
            $scope_sql = " AND ((1=1$f) OR (1=1$t))";
        }
    }
    $where_sql = implode(' AND ', $where) . $scope_sql;

    $base_from = "
          FROM stock_transfers st
          JOIN stock_transfer_items sti ON st.transfer_id = sti.transfer_id
          LEFT JOIN products   p  ON sti.product_id        = p.product_id
          LEFT JOIN warehouses fw ON st.from_warehouse_id  = fw.warehouse_id
          LEFT JOIN warehouses tw ON st.to_warehouse_id    = tw.warehouse_id
    ";

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT st.transfer_id)                              AS transfer_count,
               COALESCE(SUM(sti.quantity), 0)                              AS total_qty,
               COALESCE(SUM(sti.quantity * COALESCE(p.cost_price, 0)), 0)  AS total_value,
               COUNT(DISTINCT CASE WHEN st.status = 'completed' THEN st.transfer_id END) AS completed_count
        $base_from
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $sum = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: value by route ───────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT CONCAT(COALESCE(fw.warehouse_name,'?'), ' → ', COALESCE(tw.warehouse_name,'?')) AS name,
               COALESCE(SUM(sti.quantity * COALESCE(p.cost_price, 0)), 0)                       AS total
        $base_from
         WHERE $where_sql
      GROUP BY st.from_warehouse_id, st.to_warehouse_id
      ORDER BY total DESC LIMIT 8
    ");
    $stmt->execute($params);
    $by_route = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: by status ────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT st.status AS name, COUNT(DISTINCT st.transfer_id) AS count
        $base_from
         WHERE $where_sql
      GROUP BY st.status ORDER BY count DESC
    ");
    $stmt->execute($params);
    $by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows (one per product line) ────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT st.transfer_date,
               st.transfer_number,
               COALESCE(fw.warehouse_name, '?')                          AS from_warehouse,
               COALESCE(tw.warehouse_name, '?')                          AS to_warehouse,
               COALESCE(p.product_name, '—')                             AS product_name,
               COALESCE(p.product_code, '')                              AS product_code,
               sti.quantity,
               sti.received_quantity,
               COALESCE(sti.unit, '')                                    AS unit,
               (sti.quantity * COALESCE(p.cost_price, 0))                AS value,
               st.status
        $base_from
         WHERE $where_sql
      ORDER BY st.transfer_date DESC, st.transfer_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'transfer_count'  => (int)($sum['transfer_count'] ?? 0),
            'total_qty'       => (float)($sum['total_qty'] ?? 0),
            'total_value'     => (float)($sum['total_value'] ?? 0),
            'completed_count' => (int)($sum['completed_count'] ?? 0),
        ],
        'charts' => [
            'by_route'  => $by_route,
            'by_status' => $by_status,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_stock_transfers error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
