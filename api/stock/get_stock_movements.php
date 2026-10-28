<?php
// File: api/stock/get_stock_movements.php
// DataTables server-side source for app/bms/stock/stock_movements.php: the same
// filters and project/warehouse scope the page always applied, plus whitelisted
// sorting and a text search. Cells are rendered here with the page's helpers.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Unauthorized']);
    exit;
}
if (!canView('products')) {
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Permission denied']);
    exit;
}

$draw          = (int)($_GET['draw'] ?? 0);
$start         = max(0, (int)($_GET['start'] ?? 0));
$length        = (int)($_GET['length'] ?? 50);
$length        = $length < 0 ? 100000 : max(1, min(1000, $length));
$search        = trim((string)($_GET['search']['value'] ?? ''));
$product_id    = (int)($_GET['product_id'] ?? 0);
$warehouse_id  = (int)($_GET['warehouse_id'] ?? 0);
$movement_type = (string)($_GET['movement_type'] ?? '');
$date_from     = (string)($_GET['date_from'] ?? '');
$date_to       = (string)($_GET['date_to'] ?? '');

if ($warehouse_id > 0 && !userCan('warehouse', $warehouse_id)) {
    $warehouse_id = 0; // ignore an out-of-scope filter rather than error on a list page
}

$from = "
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id = p.product_id
    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sm.created_by = u.user_id
";
$where = " WHERE 1=1";
$params = [];
if ($product_id > 0)        { $where .= " AND sm.product_id = ?";          $params[] = $product_id; }
if ($warehouse_id > 0)      { $where .= " AND sm.warehouse_id = ?";        $params[] = $warehouse_id; }
if ($movement_type !== '')  { $where .= " AND sm.movement_type = ?";       $params[] = $movement_type; }
if ($date_from !== '')      { $where .= " AND DATE(sm.created_at) >= ?";   $params[] = $date_from; }
if ($date_to !== '')        { $where .= " AND DATE(sm.created_at) <= ?";   $params[] = $date_to; }
$where .= scopeFilterSqlNullable('project', 'sm');
$where .= scopeFilterSqlNullable('warehouse', 'sm');

$filteredWhere = $where;
$filteredParams = $params;
if ($search !== '') {
    $filteredWhere .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR w.warehouse_name LIKE ? OR sm.reference_number LIKE ? OR sm.reason LIKE ? OR u.username LIKE ?)";
    $like = '%' . $search . '%';
    array_push($filteredParams, $like, $like, $like, $like, $like, $like);
}

// Column index → sort expression. With a product filter the Product column is not shown.
$sortable = $product_id > 0
    ? ['sm.created_at', 'sm.movement_type', 'w.warehouse_name', 'sm.reference_number', 'sm.quantity', 'sm.stock_before', 'sm.stock_after', 'u.username']
    : ['sm.created_at', 'sm.movement_type', 'p.product_name', 'w.warehouse_name', 'sm.reference_number', 'sm.quantity', 'sm.stock_before', 'sm.stock_after', 'u.username'];
$col = (int)($_GET['order'][0]['column'] ?? 0);
$dir = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderSql = (isset($sortable[$col]) ? $sortable[$col] : 'sm.created_at') . " $dir, sm.movement_id $dir";

function stockMovementBadge($type) {
    $badges = [
        'purchase_in' => ['color' => 'success', 'label' => 'Purchase In'],
        'sale_out' => ['color' => 'primary', 'label' => 'Sale Out'],
        'adjustment_in' => ['color' => 'info', 'label' => 'Adjustment In'],
        'adjustment_out' => ['color' => 'warning', 'label' => 'Adjustment Out'],
        'stock_transfer' => ['color' => 'secondary', 'label' => 'Transfer'],
        'return_in' => ['color' => 'info', 'label' => 'Return In'],
        'return_out' => ['color' => 'danger', 'label' => 'Return Out']
    ];
    $config = $badges[$type] ?? ['color' => 'dark', 'label' => ucfirst(str_replace('_', ' ', (string)$type))];
    return '<span class="badge bg-' . $config['color'] . '">' . htmlspecialchars($config['label']) . '</span>';
}

try {
    $st = $pdo->prepare("SELECT COUNT(*) $from $where");
    $st->execute($params);
    $recordsTotal = (int)$st->fetchColumn();

    if ($search !== '') {
        $st = $pdo->prepare("SELECT COUNT(*) $from $filteredWhere");
        $st->execute($filteredParams);
        $recordsFiltered = (int)$st->fetchColumn();
    } else {
        $recordsFiltered = $recordsTotal;
    }

    $st = $pdo->prepare("SELECT sm.*, p.product_name, p.sku, w.warehouse_name, u.username AS created_by_name
                         $from $filteredWhere ORDER BY $orderSql LIMIT $length OFFSET $start");
    $st->execute($filteredParams);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $data = [];
    foreach ($rows as $m) {
        $cells = [];
        $cells[] = '<div class="fw-bold">' . $e(format_date($m['created_at'])) . '</div><small class="text-muted">' . $e(date('h:i A', strtotime($m['created_at']))) . '</small>';
        $cells[] = stockMovementBadge($m['movement_type']);
        if ($product_id <= 0) {
            $cells[] = '<div class="fw-bold">' . caseFormat($m['product_name']) . '</div><code class="small">' . caseFormat($m['sku']) . '</code>';
        }
        $cells[] = caseFormat($m['warehouse_name']);
        $cells[] = '<div class="small fw-bold text-dark">' . caseFormat($m['reference_number']) . '</div>'
                 . (!empty($m['reason']) ? '<small class="text-muted d-block">' . caseFormat($m['reason']) . '</small>' : '');
        $cells[] = '<span class="fw-bold ' . ($m['quantity'] > 0 ? 'text-success' : 'text-danger') . '">'
                 . ($m['quantity'] > 0 ? '+' : '') . $e(format_number($m['quantity'], 3)) . '</span>';
        $cells[] = '<span class="text-muted">' . $e(format_number($m['stock_before'], 3)) . '</span>';
        $cells[] = '<span class="fw-bold">' . $e(format_number($m['stock_after'], 3)) . '</span>';
        $cells[] = '<small class="text-muted">' . caseFormat($m['created_by_name']) . '</small>';
        $data[] = $cells;
    }

    echo json_encode(['draw' => $draw, 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);

} catch (PDOException $ex) {
    error_log('get_stock_movements: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Database error.']);
}
