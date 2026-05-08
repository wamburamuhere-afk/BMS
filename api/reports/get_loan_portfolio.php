<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../helpers.php';

// Header formatting for JSON
header('Content-Type: application/json');

try {
    // 1. Parameters from DataTables & Filters
    $draw = $_POST['draw'] ?? 1;
    $start = $_POST['start'] ?? 0;
    $length = $_POST['length'] ?? 10;
    $search_value = $_POST['search']['value'] ?? '';
    
    // Custom Filters
    $as_of_date = $_POST['as_of_date'] ?? date('Y-m-d');
    $product_filter = $_POST['product_id'] ?? '';

    // 2. Base Query Construction
    $base_query = "
        FROM loans l
        LEFT JOIN customers c ON l.customer_id = c.customer_id
        LEFT JOIN loan_products lp ON l.product_id = lp.product_id
        WHERE 1=1
    ";
    
    $params = [];

    // 3. Apply Filters
    if (!empty($product_filter)) {
        $base_query .= " AND l.product_id = :product_id";
        $params[':product_id'] = $product_filter;
    }

    // Search Logic
    if (!empty($search_value)) {
        $base_query .= " AND (
            l.reference_number LIKE :search 
            OR c.customer_name LIKE :search 
            OR lp.product_name LIKE :search
        )";
        $params[':search'] = "%$search_value%";
    }

    // 4. Total Records (Before filtering) - Approximate for speed or separate query
    $total_sql = "SELECT COUNT(*) FROM loans l"; 
    $total_records = $pdo->query($total_sql)->fetchColumn();

    // 5. Total Filtered Records
    $filtered_sql = "SELECT COUNT(*) " . $base_query;
    $stmt = $pdo->prepare($filtered_sql);
    $stmt->execute($params);
    $total_filtered = $stmt->fetchColumn();

    // 6. Fetch Data
    $sql = "
        SELECT 
            l.reference_number,
            COALESCE(c.customer_name, 'Unknown Client') as customer_name,
            COALESCE(lp.product_name, 'Unknown Product') as product_name,
            l.amount,
            l.balance as outstanding,
            l.loan_date,
            l.overdue_days,
            l.status as loan_status
        " . $base_query . "
        ORDER BY l.overdue_days DESC, l.loan_date ASC
        LIMIT :start, :length
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':start', (int)$start, PDO::PARAM_INT);
    $stmt->bindValue(':length', (int)$length, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Format Data for Display
    $formatted_data = [];
    foreach ($data as $row) {
        // Health Class Logic
        $health_class = 'success';
        if ($row['overdue_days'] > 0) $health_class = 'warning';
        if ($row['overdue_days'] > 30) $health_class = 'danger';
        
        // Status Badge Logic
        $status_badge = '<span class="badge bg-opacity-10 bg-info text-info py-1 px-2">' . htmlspecialchars($row['loan_status']) . '</span>';
        
        // Overdue Text
        $overdue_text = $row['overdue_days'] > 0 
            ? '<span class="text-danger fw-bold">' . $row['overdue_days'] . '</span>' 
            : '<span class="text-success">None</span>';

        // Date Format
        $started_date = $row['loan_date'] ? date('d M Y', strtotime($row['loan_date'])) : 'N/A';

        $formatted_data[] = [
            'reference' => '<span class="fw-bold text-primary">' . htmlspecialchars($row['reference_number']) . '</span>',
            'client' => '<span class="fw-bold text-dark d-block">' . htmlspecialchars($row['customer_name']) . '</span><span class="text-muted x-small">Started: ' . $started_date . '</span>',
            'product' => '<span class="badge bg-light text-dark border fw-normal">' . htmlspecialchars($row['product_name']) . '</span>',
            'amount' => '<span class="fw-bold">' . number_format($row['amount'], 2) . '</span>',
            'outstanding' => '<span class="fw-bold text-primary">' . number_format($row['outstanding'], 2) . '</span>',
            'overdue' => '<div class="text-center">' . $overdue_text . '</div>',
            'status' => '<div class="text-center">' . $status_badge . '</div>',
            'health' => '<div class="text-center"><div class="health-orb bg-' . $health_class . ' mx-auto" title="Days: ' . $row['overdue_days'] . '"></div></div>'
        ];
    }

    echo json_encode([
        "draw" => intval($draw),
        "recordsTotal" => intval($total_records),
        "recordsFiltered" => intval($total_filtered),
        "data" => $formatted_data
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
