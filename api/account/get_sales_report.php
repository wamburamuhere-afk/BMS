<?php
/**
 * api/account/get_sales_report.php
 *
 * AJAX data source for the Sales Report. Returns the summary metrics, three
 * chart datasets, and the transaction rows as JSON for a given filter set.
 * The page renders the shell and loads everything from here (no full-page
 * reloads on filter), per the project's AJAX rule.
 *
 * Response:
 *   { success, summary{...}, charts{ revenue_trend, by_status, top_customers },
 *     rows: [ {...invoice...} ] }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

// Guard: keep the JSON clean even if ever consumed as a partial.
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('sales_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$date_from      = $_GET['date_from']      ?? date('Y-01-01');
$date_to        = $_GET['date_to']        ?? date('Y-12-31');
$customer_id    = $_GET['customer_id']    ?? '';
$salesperson_id = $_GET['salesperson_id'] ?? '';
$status         = $_GET['status']         ?? '';
$project_id     = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

// Project-scope security (see security.md §23): a specific project may only be
// requested if the user has access to it; otherwise non-admins are restricted
// to their assigned projects. This scopes the summary, charts AND rows alike.
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

try {
    global $pdo;

    // Shared WHERE for every query.
    $params = [$date_from, $date_to];
    $where  = ["i.invoice_date BETWEEN ? AND ?"];
    if ($customer_id !== '') {
        $where[] = "i.customer_id = ?";
        $params[] = (int)$customer_id;
    }
    if ($salesperson_id !== '') {
        $where[] = "so.salesperson_id = ?";
        $params[] = (int)$salesperson_id;
    }
    if ($status !== '') {
        $where[] = "i.status = ?";
        $params[] = $status;
    } else {
        $where[] = "i.status != 'cancelled'";
    }

    // Project scope: a chosen project becomes a hard filter (access already
    // verified above); otherwise restrict non-admins to their projects
    // (+ untagged company-wide invoices). Admins get an empty clause.
    $scope_sql = '';
    if ($project_id !== null) {
        $where[]  = "i.project_id = ?";
        $params[] = $project_id;
    } else {
        $scope_sql = scopeFilterSqlNullable('project', 'i');
    }
    $where_sql = implode(' AND ', $where) . $scope_sql;

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT i.invoice_id) AS total_invoices,
               COALESCE(SUM(i.grand_total), 0)  AS total_sales,
               COALESCE(SUM(i.paid_amount), 0)  AS total_paid,
               COALESCE(SUM(i.balance_due), 0)  AS total_due,
               COUNT(DISTINCT i.customer_id)    AS unique_customers
          FROM invoices i
          LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: revenue trend (monthly) ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS label,
               COALESCE(SUM(i.grand_total), 0)      AS value
          FROM invoices i
          LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
         WHERE $where_sql
      GROUP BY label
      ORDER BY label ASC
         LIMIT 24
    ");
    $stmt->execute($params);
    $revenue_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: sales by status ──────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT i.status                          AS status,
               COUNT(*)                          AS count,
               COALESCE(SUM(i.grand_total), 0)   AS total
          FROM invoices i
          LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
         WHERE $where_sql
      GROUP BY i.status
      ORDER BY total DESC
    ");
    $stmt->execute($params);
    $by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 3: top customers by revenue ─────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.customer_name, 'Walk-in') AS name,
               COALESCE(SUM(i.grand_total), 0)      AS total
          FROM invoices i
          LEFT JOIN customers c ON i.customer_id = c.customer_id
          LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
         WHERE $where_sql
      GROUP BY i.customer_id, c.customer_name
      ORDER BY total DESC
         LIMIT 8
    ");
    $stmt->execute($params);
    $top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT i.invoice_number, i.invoice_date,
               COALESCE(c.customer_name, 'Walk-in') AS customer_name,
               i.grand_total, i.paid_amount, i.balance_due, i.status,
               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS salesperson
          FROM invoices i
          LEFT JOIN customers c ON i.customer_id = c.customer_id
          LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
          LEFT JOIN users u ON so.salesperson_id = u.user_id
         WHERE $where_sql
      ORDER BY i.invoice_date DESC, i.invoice_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_invoices'   => (int)($summary['total_invoices'] ?? 0),
            'total_sales'      => (float)($summary['total_sales'] ?? 0),
            'total_paid'       => (float)($summary['total_paid'] ?? 0),
            'total_due'        => (float)($summary['total_due'] ?? 0),
            'unique_customers' => (int)($summary['unique_customers'] ?? 0),
        ],
        'charts' => [
            'revenue_trend' => $revenue_trend,
            'by_status'     => $by_status,
            'top_customers' => $top_customers,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_sales_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
