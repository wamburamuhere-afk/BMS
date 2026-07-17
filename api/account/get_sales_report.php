<?php
/**
 * api/account/get_sales_report.php
 *
 * AJAX data source for the Sales Report. Now sources from BOTH invoices and
 * pos_sales. Source filter: '' = all, 'invoice' = invoices only, 'pos' = POS only.
 *
 * Response:
 *   { success, summary{...}, charts{ revenue_trend, by_status, top_customers },
 *     rows: [ {...} ] }
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
$source         = $_GET['source']         ?? '';   // '' | 'invoice' | 'pos'
$project_id     = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;
$warehouse_id   = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;

// Sanitise source to known values only
if (!in_array($source, ['', 'invoice', 'pos'], true)) $source = '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your assigned scope.']);
    exit;
}

$include_inv = ($source !== 'pos');
$include_pos = ($source !== 'invoice');

try {
    global $pdo;

    // ── Invoice WHERE ─────────────────────────────────────────────────────
    $inv_params = [$date_from, $date_to];
    $inv_where  = ["i.invoice_date BETWEEN ? AND ?"];
    if ($customer_id    !== '') { $inv_where[] = "i.customer_id = ?";     $inv_params[] = (int)$customer_id; }
    if ($salesperson_id !== '') { $inv_where[] = "so.salesperson_id = ?"; $inv_params[] = (int)$salesperson_id; }
    if ($status !== '') { $inv_where[] = "i.status = ?"; $inv_params[] = $status; }
    else                { $inv_where[] = "i.status != 'cancelled'"; }
    $inv_scope = '';
    if ($project_id !== null) { $inv_where[] = "i.project_id = ?"; $inv_params[] = $project_id; }
    else                      { $inv_scope  .= scopeFilterSqlNullable('project', 'i'); }
    if ($warehouse_id !== null) { $inv_where[] = "i.warehouse_id = ?"; $inv_params[] = $warehouse_id; }
    else                        { $inv_scope  .= scopeFilterSqlNullable('warehouse', 'i'); }
    $inv_where_sql = implode(' AND ', $inv_where) . $inv_scope;

    // ── POS WHERE ─────────────────────────────────────────────────────────
    // salesperson_id maps to pos_sales.user_id (same user table).
    // Status filter is invoice-only; POS always uses sale_status = 'completed'.
    $pos_params = [$date_from, $date_to];
    $pos_where  = ["DATE(ps.sale_date) BETWEEN ? AND ?", "ps.sale_status = 'completed'"];
    if ($customer_id    !== '') { $pos_where[] = "ps.customer_id = ?"; $pos_params[] = (int)$customer_id; }
    if ($salesperson_id !== '') { $pos_where[] = "ps.user_id = ?";     $pos_params[] = (int)$salesperson_id; }
    $pos_scope = '';
    if ($project_id !== null) { $pos_where[] = "ps.project_id = ?"; $pos_params[] = $project_id; }
    else                      { $pos_scope  .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $pos_where[] = "ps.warehouse_id = ?"; $pos_params[] = $warehouse_id; }
    else                        { $pos_scope  .= scopeFilterSqlNullable('warehouse', 'ps'); }
    $pos_where_sql = implode(' AND ', $pos_where) . $pos_scope;

    // When a source is excluded use WHERE 1=0 so its subquery contributes nothing,
    // allowing a single UNION query for every data shape below.
    $eff_inv_where  = $include_inv ? $inv_where_sql : '1=0';
    $eff_inv_params = $include_inv ? $inv_params     : [];
    $eff_pos_where  = $include_pos ? $pos_where_sql : '1=0';
    $eff_pos_params = $include_pos ? $pos_params     : [];
    $merged         = array_merge($eff_inv_params, $eff_pos_params);

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                          AS total_count,
               COALESCE(SUM(grand_total),  0)    AS total_sales,
               COALESCE(SUM(paid_amount),  0)    AS total_paid,
               COALESCE(SUM(balance_due),  0)    AS total_due,
               COUNT(DISTINCT cust_id)           AS unique_customers
          FROM (
            SELECT i.grand_total, i.paid_amount, i.balance_due, i.customer_id AS cust_id
              FROM invoices i
              LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
             WHERE $eff_inv_where
            UNION ALL
            SELECT ps.grand_total,
                   ps.grand_total AS paid_amount,
                   0              AS balance_due,
                   ps.customer_id AS cust_id
              FROM pos_sales ps
             WHERE $eff_pos_where
          ) AS combined
    ");
    $stmt->execute($merged);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: revenue trend (monthly, both sources merged) ─────────────
    $stmt = $pdo->prepare("
        SELECT label, SUM(value) AS value
          FROM (
            SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS label, i.grand_total AS value
              FROM invoices i
              LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
             WHERE $eff_inv_where
            UNION ALL
            SELECT DATE_FORMAT(ps.sale_date, '%Y-%m') AS label, ps.grand_total AS value
              FROM pos_sales ps
             WHERE $eff_pos_where
          ) AS combined
      GROUP BY label ORDER BY label ASC LIMIT 24
    ");
    $stmt->execute($merged);
    $revenue_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: by status ────────────────────────────────────────────────
    // POS-only view groups by payment_method; mixed/invoice view shows invoice
    // statuses and POS as a single 'POS Sale' bucket.
    $pos_status_expr = ($source === 'pos') ? 'ps.payment_method' : "'POS Sale'";
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS count, SUM(total) AS total
          FROM (
            SELECT i.status, i.grand_total AS total
              FROM invoices i
              LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
             WHERE $eff_inv_where
            UNION ALL
            SELECT $pos_status_expr AS status, ps.grand_total AS total
              FROM pos_sales ps
             WHERE $eff_pos_where
          ) AS combined
      GROUP BY status ORDER BY total DESC
    ");
    $stmt->execute($merged);
    $by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 3: top customers (both sources) ─────────────────────────────
    $stmt = $pdo->prepare("
        SELECT name, SUM(total) AS total
          FROM (
            SELECT COALESCE(c.customer_name, 'Walk-in') AS name, i.grand_total AS total
              FROM invoices i
              LEFT JOIN customers c ON i.customer_id = c.customer_id
              LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
             WHERE $eff_inv_where
            UNION ALL
            SELECT COALESCE(ps.customer_name, c2.customer_name, 'Walk-in') AS name, ps.grand_total AS total
              FROM pos_sales ps
              LEFT JOIN customers c2 ON ps.customer_id = c2.customer_id
             WHERE $eff_pos_where
          ) AS combined
      GROUP BY name ORDER BY total DESC LIMIT 8
    ");
    $stmt->execute($merged);
    $top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows (both sources, unified columns) ───────────────────────
    $stmt = $pdo->prepare("
        SELECT ref_number, sale_date, due_date, customer_name,
               grand_total, paid_amount, balance_due,
               discount_amount, tax_amount,
               status, payment_method, salesperson, source
          FROM (
            SELECT i.invoice_number                                                        AS ref_number,
                   i.invoice_date                                                           AS sale_date,
                   i.due_date,
                   COALESCE(c.customer_name, 'Walk-in')                                    AS customer_name,
                   i.grand_total, i.paid_amount, i.balance_due,
                   i.discount_amount, i.tax_amount,
                   i.status,
                   ''                                                                       AS payment_method,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS salesperson,
                   'Invoice'                                                                AS source
              FROM invoices i
              LEFT JOIN customers c     ON i.customer_id     = c.customer_id
              LEFT JOIN sales_orders so ON i.order_id        = so.sales_order_id
              LEFT JOIN users u         ON so.salesperson_id = u.user_id
             WHERE $eff_inv_where
            UNION ALL
            SELECT ps.receipt_number                                                        AS ref_number,
                   DATE(ps.sale_date)                                                       AS sale_date,
                   NULL                                                                     AS due_date,
                   COALESCE(ps.customer_name, c2.customer_name, 'Walk-in')                 AS customer_name,
                   ps.grand_total,
                   ps.grand_total                                                           AS paid_amount,
                   0                                                                        AS balance_due,
                   ps.discount_amount, ps.tax_amount,
                   ps.payment_status                                                        AS status,
                   ps.payment_method,
                   ps.cashier_name                                                          AS salesperson,
                   'POS'                                                                    AS source
              FROM pos_sales ps
              LEFT JOIN customers c2 ON ps.customer_id = c2.customer_id
             WHERE $eff_pos_where
          ) AS combined
      ORDER BY sale_date DESC, ref_number DESC
    ");
    $stmt->execute($merged);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_count'      => (int)($summary['total_count'] ?? 0),
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
