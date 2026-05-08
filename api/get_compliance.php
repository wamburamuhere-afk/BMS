<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $draw = $_GET['draw'] ?? 1;
    $start = $_GET['start'] ?? 0;
    $length = $_GET['length'] ?? 10;
    $search = $_GET['search']['value'] ?? '';
    
    // Filters
    $category = $_GET['category'] ?? '';
    $statusFilter = $_GET['status'] ?? '';

    $query = "SELECT * FROM compliance_records WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $query .= " AND (title LIKE ? OR ref_no LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($category)) {
        $query .= " AND category = ?";
        $params[] = $category;
    }

    // Status logic (Valid, Expired, Expiring Soon)
    $today = date('Y-m-d');
    $warningDate = date('Y-m-d', strtotime('+30 days'));

    if ($statusFilter === 'Expired') {
        $query .= " AND expiry_date < ? AND expiry_date IS NOT NULL";
        $params[] = $today;
    } elseif ($statusFilter === 'Expiring Soon') {
        $query .= " AND expiry_date BETWEEN ? AND ? AND expiry_date IS NOT NULL";
        $params[] = $today;
        $params[] = $warningDate;
    } elseif ($statusFilter === 'Valid') {
        $query .= " AND (expiry_date >= ? OR expiry_date IS NULL)";
        $params[] = $today;
    }

    // Total
    $total = $pdo->query("SELECT COUNT(*) FROM compliance_records")->fetchColumn();
    
    // Filtered
    $stmt = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $query));
    $stmt->execute($params);
    $filtered = $stmt->fetchColumn();

    // Data
    $query .= " ORDER BY updated_at DESC LIMIT $start, $length";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate Status for display
    foreach ($data as &$row) {
        if (!$row['expiry_date']) {
            $row['status'] = 'Valid';
        } elseif ($row['expiry_date'] < $today) {
            $row['status'] = 'Expired';
        } elseif ($row['expiry_date'] <= $warningDate) {
            $row['status'] = 'Expiring Soon';
        } else {
            $row['status'] = 'Valid';
        }
    }

    // Stats
    $totalCount = $total;
    $expiredCount = $pdo->query("SELECT COUNT(*) FROM compliance_records WHERE expiry_date < '$today' AND expiry_date IS NOT NULL")->fetchColumn();
    $expiringCount = $pdo->query("SELECT COUNT(*) FROM compliance_records WHERE expiry_date BETWEEN '$today' AND '$warningDate' AND expiry_date IS NOT NULL")->fetchColumn();
    $validCount = $pdo->query("SELECT COUNT(*) FROM compliance_records WHERE expiry_date >= '$today' OR expiry_date IS NULL")->fetchColumn();

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => $total,
        'recordsFiltered' => $filtered,
        'data' => $data,
        'stats' => [
            'total' => intval($totalCount),
            'expired' => intval($expiredCount),
            'expiring' => intval($expiringCount),
            'valid' => intval($validCount)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
