<?php
/**
 * api/account/get_user_sales_report.php
 *
 * AJAX data source for the Sales by User Report (Simple POS only) —
 * per-cashier totals, a chart, and (mode=items) a product-level drill-down
 * for one cashier. Source: pos_sales (sale_status='completed') joined
 * pos_sale_items — mirrors the POS half of get_sales_report.php.
 *
 * Project/warehouse-scoped per .claude/security.md §23.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    return;
}
if (!canView('pos') || !canView('pos_user_sales_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    return;
}
if (!posSimpleModeEnabled()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not available']);
    return;
}

$date_from    = $_GET['date_from'] ?? date('Y-01-01');
$date_to      = $_GET['date_to']   ?? date('Y-12-31');
$user_filter  = (isset($_GET['user_id']) && $_GET['user_id'] !== '') ? (int)$_GET['user_id'] : null;
$project_id   = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;
$mode         = ($_GET['mode'] ?? '') === 'items' ? 'items' : 'summary';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    return;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    return;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => isShopLabel() ? 'Access denied: this shop is not in your assigned scope.' : 'Access denied: this warehouse is not in your assigned scope.']);
    return;
}

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["DATE(ps.sale_date) BETWEEN ? AND ?", "ps.sale_status = 'completed'"];
    $scope  = '';
    if ($project_id !== null) { $where[] = "ps.project_id = ?"; $params[] = $project_id; }
    else                      { $scope  .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $where[] = "ps.warehouse_id = ?"; $params[] = $warehouse_id; }
    else                        { $scope  .= scopeFilterSqlNullable('warehouse', 'ps'); }
    if ($user_filter !== null) { $where[] = "ps.user_id = ?"; $params[] = $user_filter; }
    $where_sql = implode(' AND ', $where) . $scope;

    if ($mode === 'items') {
        if ($user_filter === null) {
            echo json_encode(['success' => false, 'message' => 'user_id is required']);
            return;
        }
        $stmt = $pdo->prepare("
            SELECT psi.product_name, psi.sku,
                   SUM(psi.quantity - COALESCE(psi.returned_quantity, 0)) AS qty_sold,
                   SUM(psi.line_total) AS total_value
              FROM pos_sale_items psi
              JOIN pos_sales ps ON psi.sale_id = ps.sale_id
             WHERE $where_sql
          GROUP BY psi.product_id, psi.product_name, psi.sku
          ORDER BY total_value DESC
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'items' => $items]);
        return;
    }

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ps.sale_id)     AS total_sales,
               COALESCE(SUM(ps.grand_total), 0) AS total_value,
               COUNT(DISTINCT ps.user_id)     AS active_cashiers
          FROM pos_sales ps
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(psi.quantity - COALESCE(psi.returned_quantity, 0)), 0) AS qty_sold
          FROM pos_sale_items psi
          JOIN pos_sales ps ON psi.sale_id = ps.sale_id
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $qtySold = (float)($stmt->fetchColumn() ?: 0);

    // ── Per-cashier rows (+ chart) ────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT ps.user_id,
               COALESCE(TRIM(CONCAT(u.first_name,' ',u.last_name)), '') AS user_name,
               MAX(ps.cashier_name)                 AS cashier_name,
               COUNT(DISTINCT ps.sale_id)            AS sales_count,
               COALESCE(SUM(ps.grand_total), 0)      AS total_value
          FROM pos_sales ps
          LEFT JOIN users u ON ps.user_id = u.user_id
         WHERE $where_sql
      GROUP BY ps.user_id
      ORDER BY total_value DESC
    ");
    $stmt->execute($params);
    $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Items-sold per cashier, merged into the same rows.
    $stmt = $pdo->prepare("
        SELECT ps.user_id,
               COALESCE(SUM(psi.quantity - COALESCE(psi.returned_quantity, 0)), 0) AS qty_sold
          FROM pos_sale_items psi
          JOIN pos_sales ps ON psi.sale_id = ps.sale_id
         WHERE $where_sql
      GROUP BY ps.user_id
    ");
    $stmt->execute($params);
    $qtyByUser = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qtyByUser[(int)$r['user_id']] = (float)$r['qty_sold'];
    }

    $rows = [];
    foreach ($byUser as $r) {
        $uid = (int)$r['user_id'];
        $name = trim($r['user_name']) !== '' ? trim($r['user_name']) : ($r['cashier_name'] ?: 'Unknown');
        $rows[] = [
            'user_id'      => $uid,
            'name'         => $name,
            'sales_count'  => (int)$r['sales_count'],
            'qty_sold'     => $qtyByUser[$uid] ?? 0,
            'total_value'  => (float)$r['total_value'],
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_sales'     => (int)($summary['total_sales'] ?? 0),
            'total_value'     => (float)($summary['total_value'] ?? 0),
            'active_cashiers' => (int)($summary['active_cashiers'] ?? 0),
            'qty_sold'        => $qtySold,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_user_sales_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
