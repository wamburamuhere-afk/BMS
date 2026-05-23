<?php
// File: api/get_delivery_notes_list.php
// Server-side DataTables feed for the Delivery Notes list (inbound + outbound).
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
    $type_filter      = $_GET['dn_type']   ?? '';
    $date_from        = $_GET['date_from'] ?? '';
    $date_to          = $_GET['date_to']   ?? '';

    // DataTables params
    $draw   = isset($_GET['draw'])   ? intval($_GET['draw'])   : 1;
    $start  = isset($_GET['start'])  ? intval($_GET['start'])  : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 25;
    $search = isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '';

    // Common filters (everything EXCEPT dn_type) — used for the tab counts
    $where  = ['1=1'];
    $params = [];

    if ($supplier_filter > 0) {
        $where[] = '(d.supplier_id = ? OR d.subcontractor_id = ?)';
        $params[] = $supplier_filter;
        $params[] = $supplier_filter;
    }
    if (!empty($status_filter)) {
        $where[] = 'd.status = ?';
        $params[] = $status_filter;
    }
    if (!empty($warehouse_filter)) {
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
        $where[] = '(d.delivery_number LIKE ? OR d.dn_number LIKE ? OR s.supplier_name LIKE ? OR sc.supplier_name LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Full filters = common + dn_type (the active tab)
    $where_full  = $where;
    $params_full = $params;
    if ($type_filter === 'inbound' || $type_filter === 'outbound') {
        $where_full[] = 'd.dn_type = ?';
        $params_full[] = $type_filter;
    }

    $common_sql = implode(' AND ', $where);
    $full_sql   = implode(' AND ', $where_full);

    $join_sql = "
        FROM deliveries d
        LEFT JOIN suppliers s        ON d.supplier_id     = s.supplier_id
        LEFT JOIN sub_contractors sc ON d.subcontractor_id = sc.supplier_id
        LEFT JOIN warehouses w       ON d.warehouse_id    = w.warehouse_id
        LEFT JOIN projects   p       ON d.project_id      = p.project_id
    ";

    // Stat cards — reflect the active tab (full filter)
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_grns,
            SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN d.status IN ('draft','pending') THEN 1 ELSE 0 END) AS draft_count
        $join_sql
        WHERE $full_sql
    ");
    $stats_stmt->execute($params_full);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Tab counts — per direction, ignoring the dn_type filter (common filter only)
    $tc_stmt = $pdo->prepare("SELECT d.dn_type, COUNT(*) AS c $join_sql WHERE $common_sql GROUP BY d.dn_type");
    $tc_stmt->execute($params);
    $type_counts = ['inbound' => 0, 'outbound' => 0];
    foreach ($tc_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $type_counts[$r['dn_type']] = (int)$r['c'];
    }

    $recordsTotal    = (int)$pdo->query("SELECT COUNT(*) FROM deliveries")->fetchColumn();
    $recordsFiltered = (int)($stats['total_grns'] ?? 0);

    // Data
    $data_params = array_merge($params_full, [(int)$length, (int)$start]);
    $stmt = $pdo->prepare("
        SELECT
            d.delivery_id,
            d.delivery_number,
            d.dn_number,
            d.dn_type,
            d.party_type,
            d.delivery_date,
            d.status,
            d.notes,
            d.contact_person,
            COALESCE(s.supplier_name, sc.supplier_name) AS supplier_name,
            COALESCE(s.supplier_name, sc.supplier_name) AS party_name,
            COALESCE(s.company_name, sc.company_name)   AS company_name,
            w.warehouse_name,
            p.project_name,
            (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = d.delivery_id) AS total_items
        $join_sql
        WHERE $full_sql
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
        'success'         => true,
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $rows,
        'stats'           => $stats,
        'type_counts'     => $type_counts,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
