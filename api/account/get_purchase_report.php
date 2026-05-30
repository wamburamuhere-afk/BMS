<?php
/**
 * api/account/get_purchase_report.php
 *
 * AJAX data source for the Purchase Report — summary metrics, three chart
 * datasets, and the PO rows as JSON. Project-scoped per security.md §23:
 * the same $where_sql (incl. the scope clause) feeds the summary, every
 * chart, and the rows, so they always agree and never leak another project.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('purchase_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$date_from   = $_GET['date_from']   ?? date('Y-01-01');
$date_to     = $_GET['date_to']     ?? date('Y-12-31');
$status      = $_GET['status']      ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';
$project_id  = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

// Project-scope security (security.md §23).
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["po.order_date BETWEEN ? AND ?"];
    if ($status !== '') {
        $where[] = "po.status = ?";
        $params[] = $status;
    } else {
        $where[] = "po.status != 'cancelled'";
    }
    if ($supplier_id !== '') {
        $where[] = "po.supplier_id = ?";
        $params[] = (int)$supplier_id;
    }
    $scope_sql = '';
    if ($project_id !== null) {
        $where[]  = "po.project_id = ?";
        $params[] = $project_id;
    } else {
        $scope_sql = scopeFilterSqlNullable('project', 'po');
    }
    $where_sql = implode(' AND ', $where) . $scope_sql;

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                                          AS total_orders,
               COALESCE(SUM(po.grand_total), 0)                  AS total_spend,
               COALESCE(SUM(po.paid_amount), 0)                  AS total_paid,
               COALESCE(SUM(po.grand_total - po.paid_amount), 0) AS total_due
          FROM purchase_orders po
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: spend trend (monthly) ────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(po.order_date, '%Y-%m') AS label,
               COALESCE(SUM(po.grand_total), 0)    AS value
          FROM purchase_orders po
         WHERE $where_sql
      GROUP BY label ORDER BY label ASC LIMIT 24
    ");
    $stmt->execute($params);
    $spend_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: spend by supplier (top 8) ────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(s.supplier_name, 'Unknown') AS name,
               COALESCE(SUM(po.grand_total), 0)     AS total
          FROM purchase_orders po
          LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
         WHERE $where_sql
      GROUP BY po.supplier_id, s.supplier_name
      ORDER BY total DESC LIMIT 8
    ");
    $stmt->execute($params);
    $by_supplier = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 3: by status ────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT po.status AS status, COUNT(*) AS count,
               COALESCE(SUM(po.grand_total), 0) AS total
          FROM purchase_orders po
         WHERE $where_sql
      GROUP BY po.status ORDER BY total DESC
    ");
    $stmt->execute($params);
    $by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT po.order_number, po.order_date,
               COALESCE(s.supplier_name, 'Unknown') AS supplier_name,
               po.grand_total, po.paid_amount, po.status, po.payment_status
          FROM purchase_orders po
          LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
         WHERE $where_sql
      ORDER BY po.order_date DESC, po.purchase_order_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_orders' => (int)($summary['total_orders'] ?? 0),
            'total_spend'  => (float)($summary['total_spend'] ?? 0),
            'total_paid'   => (float)($summary['total_paid'] ?? 0),
            'total_due'    => (float)($summary['total_due'] ?? 0),
        ],
        'charts' => [
            'spend_trend' => $spend_trend,
            'by_supplier' => $by_supplier,
            'by_status'   => $by_status,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_purchase_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
