<?php
// File: api/get_delivery_notes_list.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    // Filters
    $supplier_filter  = isset($_GET['supplier'])  ? intval($_GET['supplier'])  : 0;
    $status_filter    = $_GET['status']    ?? '';
    $warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
    $date_from        = $_GET['date_from'] ?? '';
    $date_to          = $_GET['date_to']   ?? '';

    // DataTables params
    $draw   = isset($_GET['draw'])   ? intval($_GET['draw'])   : 1;
    $start  = isset($_GET['start'])  ? intval($_GET['start'])  : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 25;
    $search = isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '';

    $where = ['1=1'];
    $params = [];

    if ($supplier_filter > 0) {
        $where[] = 'd.supplier_id = ?';
        $params[] = $supplier_filter;
    }
    if (!empty($status_filter)) {
        $where[] = 'd.status = ?';
        $params[] = $status_filter;
    }
    if ($warehouse_filter > 0) {
        $where[] = 'd.warehouse_id = ?';
        $params[] = $warehouse_filter;
    }
    if (!empty($date_from)) {
        $where[] = 'd.delivery_date >= ?';
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $where[] = 'd.delivery_date <= ?';
        $params[] = $date_to;
    }
    if (!empty($search)) {
        $where[] = '(d.delivery_number LIKE ? OR s.supplier_name LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $where_sql = implode(' AND ', $where);

    // Stats
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_grns,
            SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN d.status IN ('draft','pending') THEN 1 ELSE 0 END) as draft_count
        FROM deliveries d
        LEFT JOIN suppliers s ON d.supplier_id = s.supplier_id
        WHERE $where_sql
    ");
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $recordsTotal    = (int)$pdo->query("SELECT COUNT(*) FROM deliveries")->fetchColumn();
    $recordsFiltered = (int)($stats['total_grns'] ?? 0);

    // Data
    $data_params = array_merge($params, [(int)$length, (int)$start]);
    $stmt = $pdo->prepare("
        SELECT
            d.delivery_id,
            d.delivery_number,
            d.delivery_date,
            d.status,
            d.notes,
            d.contact_person,
            s.supplier_name,
            s.company_name,
            w.warehouse_name,
            p.project_name,
            (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = d.delivery_id) AS total_items
        FROM deliveries d
        LEFT JOIN suppliers s  ON d.supplier_id  = s.supplier_id
        LEFT JOIN warehouses w ON d.warehouse_id = w.warehouse_id
        LEFT JOIN projects   p ON d.project_id   = p.project_id
        WHERE $where_sql
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $idx = 1;
    foreach ($data_params as $val) {
        $stmt->bindValue($idx++, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'          => true,
        'draw'             => $draw,
        'recordsTotal'     => $recordsTotal,
        'recordsFiltered'  => $recordsFiltered,
        'data'             => $rows,
        'stats'            => $stats,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
