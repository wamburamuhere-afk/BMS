<?php
/**
 * api/account/get_inventory_report.php
 *
 * Inventory Snapshot Report — one row per product per warehouse, sourced from
 * the authoritative `product_stocks` table (product_id × warehouse_id × qty).
 * Returns summary cards, three charts, and detail rows.
 *
 * Filters: product_id, warehouse_id, category_id, stock_status, project_id.
 *
 * Project scope (security.md §23): stock physically lives in a warehouse, so
 * scoping is by warehouses.project_id — non-admins see warehouses in their
 * assigned projects plus company-wide (NULL-project) warehouses.
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

$product_id   = (isset($_GET['product_id'])   && $_GET['product_id']   !== '') ? (int)$_GET['product_id']   : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;
$category_id  = (isset($_GET['category_id'])  && $_GET['category_id']  !== '') ? (int)$_GET['category_id']  : null;
$stock_status = $_GET['stock_status'] ?? '';   // '' | 'in' | 'low' | 'out'
$project_id   = (isset($_GET['project_id'])   && $_GET['project_id']   !== '') ? (int)$_GET['project_id']   : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
    exit;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your assigned scope.']);
    exit;
}

// Threshold used to classify low/out/in. product_stocks.min_stock_level is the
// per-warehouse reorder point; when unset (0) fall back to the product's level.
$threshold = "COALESCE(NULLIF(ps.min_stock_level, 0), p.reorder_level, 0)";

try {
    global $pdo;

    // ── Shared WHERE ──────────────────────────────────────────────────────
    $params = [];
    $where  = ["p.status != 'discontinued'"];

    if ($product_id !== null) {
        $where[]  = "ps.product_id = ?";
        $params[] = $product_id;
    }
    if ($warehouse_id !== null) {
        $where[]  = "ps.warehouse_id = ?";
        $params[] = $warehouse_id;
    }
    if ($category_id !== null) {
        $where[]  = "p.category_id = ?";
        $params[] = $category_id;
    }
    if ($stock_status === 'out') {
        $where[] = "ps.stock_quantity <= 0";
    } elseif ($stock_status === 'low') {
        $where[] = "ps.stock_quantity > 0 AND ps.stock_quantity <= $threshold";
    } elseif ($stock_status === 'in') {
        $where[] = "ps.stock_quantity > $threshold";
    }

    // Phase 6 (pos_upgrade_plan.md): stock physically lives in a warehouse, so
    // scope directly by warehouse access rather than by the warehouse's project.
    // project_id, when given, is kept as an optional narrowing filter.
    if ($project_id !== null) {
        $where[]  = "w.project_id = ?";
        $params[] = $project_id;
    }
    $scope_sql = ($warehouse_id === null) ? scopeFilterSqlNullable('warehouse', 'w') : '';
    $where_sql = implode(' AND ', $where) . $scope_sql;

    $base_from = "
          FROM product_stocks ps
          JOIN products   p ON ps.product_id   = p.product_id
          LEFT JOIN warehouses w ON ps.warehouse_id = w.warehouse_id
          LEFT JOIN categories c ON p.category_id  = c.category_id
    ";

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ps.product_id)                                AS total_skus,
               COUNT(DISTINCT ps.warehouse_id)                              AS warehouse_count,
               COALESCE(SUM(ps.stock_quantity * p.cost_price),    0)        AS total_cost_value,
               COALESCE(SUM(ps.stock_quantity * p.selling_price), 0)        AS total_selling_value
        $base_from
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: Cost Value by Warehouse ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(w.warehouse_name, 'No Warehouse')              AS name,
               COALESCE(SUM(ps.stock_quantity * p.cost_price), 0)      AS total
        $base_from
         WHERE $where_sql
      GROUP BY ps.warehouse_id, w.warehouse_name
      ORDER BY total DESC
    ");
    $stmt->execute($params);
    $by_warehouse = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: Stock Status counts ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN ps.stock_quantity <= 0 THEN 1 ELSE 0 END), 0)                                          AS out_stock,
            COALESCE(SUM(CASE WHEN ps.stock_quantity > 0 AND ps.stock_quantity <= $threshold THEN 1 ELSE 0 END), 0)        AS low_stock,
            COALESCE(SUM(CASE WHEN ps.stock_quantity > $threshold THEN 1 ELSE 0 END), 0)                                   AS in_stock
        $base_from
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $ss = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['in_stock' => 0, 'low_stock' => 0, 'out_stock' => 0];
    $stock_status_data = [
        ['label' => 'In Stock',     'value' => (int)$ss['in_stock']],
        ['label' => 'Low Stock',    'value' => (int)$ss['low_stock']],
        ['label' => 'Out of Stock', 'value' => (int)$ss['out_stock']],
    ];

    // ── Chart 3: Top 8 stock lines by cost value ─────────────────────────
    $stmt = $pdo->prepare("
        SELECT p.product_name                                  AS name,
               (ps.stock_quantity * p.cost_price)             AS total
        $base_from
         WHERE $where_sql
      ORDER BY total DESC LIMIT 8
    ");
    $stmt->execute($params);
    $top_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT p.product_id,
               p.product_code,
               p.product_name,
               COALESCE(c.category_name, 'Uncategorised')          AS category,
               COALESCE(w.warehouse_name, 'No Warehouse')           AS warehouse_name,
               ps.stock_quantity                                    AS current_stock,
               (ps.stock_quantity * COALESCE(p.cost_price, 0))     AS cost_value,
               (ps.stock_quantity * COALESCE(p.selling_price, 0))   AS selling_value
        $base_from
         WHERE $where_sql
      ORDER BY cost_value DESC, p.product_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_skus'          => (int)($summary['total_skus'] ?? 0),
            'warehouse_count'     => (int)($summary['warehouse_count'] ?? 0),
            'total_cost_value'    => (float)($summary['total_cost_value'] ?? 0),
            'total_selling_value' => (float)($summary['total_selling_value'] ?? 0),
        ],
        'charts' => [
            'by_warehouse' => $by_warehouse,
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
