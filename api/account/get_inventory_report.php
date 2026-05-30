<?php
/**
 * api/account/get_inventory_report.php
 *
 * AJAX data source for the Inventory Valuation Report — summary, three chart
 * datasets, and product rows as JSON. Stock value = current_stock * cost_price.
 *
 * Project-scoped per security.md §23 (products.project_id): the same
 * $where_sql feeds the summary, every chart, and the rows.
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
if (!canView('inventory_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$category_id  = $_GET['category_id']  ?? '';
$stock_status = $_GET['stock_status'] ?? '';   // in | low | out
$project_id   = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

try {
    global $pdo;

    $params = [];
    $where  = ["p.status != 'discontinued'"];
    if ($category_id !== '') {
        $where[]  = "p.category_id = ?";
        $params[] = (int)$category_id;
    }
    if ($stock_status === 'out') {
        $where[] = "p.current_stock <= 0";
    } elseif ($stock_status === 'low') {
        $where[] = "p.current_stock > 0 AND p.current_stock <= p.reorder_level";
    } elseif ($stock_status === 'in') {
        $where[] = "p.current_stock > p.reorder_level";
    }
    $scope_sql = '';
    if ($project_id !== null) {
        $where[]  = "p.project_id = ?";
        $params[] = $project_id;
    } else {
        $scope_sql = scopeFilterSqlNullable('project', 'p');
    }
    $where_sql = implode(' AND ', $where) . $scope_sql;

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                                              AS total_skus,
               COALESCE(SUM(p.current_stock * p.cost_price), 0)      AS total_value,
               COALESCE(SUM(p.current_stock), 0)                     AS total_units,
               COALESCE(SUM(CASE WHEN p.current_stock <= p.reorder_level THEN 1 ELSE 0 END), 0) AS low_count
          FROM products p
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: stock value by category ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.category_name, 'Uncategorised')       AS name,
               COALESCE(SUM(p.current_stock * p.cost_price), 0) AS total
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.category_id
         WHERE $where_sql
      GROUP BY p.category_id, c.category_name
      ORDER BY total DESC
    ");
    $stmt->execute($params);
    $by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: stock status counts ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN p.current_stock <= 0 THEN 1 ELSE 0 END), 0) AS out_stock,
            COALESCE(SUM(CASE WHEN p.current_stock > 0 AND p.current_stock <= p.reorder_level THEN 1 ELSE 0 END), 0) AS low_stock,
            COALESCE(SUM(CASE WHEN p.current_stock > p.reorder_level THEN 1 ELSE 0 END), 0) AS in_stock
          FROM products p
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $ss = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['in_stock' => 0, 'low_stock' => 0, 'out_stock' => 0];
    $stock_status_data = [
        ['label' => 'In Stock',     'value' => (int)$ss['in_stock']],
        ['label' => 'Low Stock',    'value' => (int)$ss['low_stock']],
        ['label' => 'Out of Stock', 'value' => (int)$ss['out_stock']],
    ];

    // ── Chart 3: top items by value (top 8) ───────────────────────────────
    $stmt = $pdo->prepare("
        SELECT p.product_name AS name,
               (p.current_stock * p.cost_price) AS total
          FROM products p
         WHERE $where_sql
      ORDER BY total DESC
         LIMIT 8
    ");
    $stmt->execute($params);
    $top_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT p.product_code, p.product_name,
               COALESCE(c.category_name, 'Uncategorised') AS category,
               p.current_stock, p.cost_price, p.reorder_level, p.status,
               (p.current_stock * p.cost_price) AS stock_value,
               CASE WHEN p.current_stock <= 0 THEN 'out'
                    WHEN p.current_stock <= p.reorder_level THEN 'low'
                    ELSE 'in' END AS stock_status
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.category_id
         WHERE $where_sql
      ORDER BY stock_value DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_skus'  => (int)($summary['total_skus'] ?? 0),
            'total_value' => (float)($summary['total_value'] ?? 0),
            'total_units' => (float)($summary['total_units'] ?? 0),
            'low_count'   => (int)($summary['low_count'] ?? 0),
        ],
        'charts' => [
            'by_category'  => $by_category,
            'stock_status' => $stock_status_data,
            'top_items'    => $top_items,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_inventory_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
