<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    global $pdo;
    
    $loan_id = (int)($_GET['loan_id'] ?? 0);
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 10);

    if (!$loan_id) {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
        exit();
    }

    $where = "WHERE d.loan_id = :loan_id";
    $params = [':loan_id' => $loan_id];

    // Total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM loan_documents WHERE loan_id = :loan_id");
    $countStmt->execute([':loan_id' => $loan_id]);
    $total = (int)$countStmt->fetchColumn();

    // Data query
    $query = "SELECT 
                d.*,
                CONCAT(u.first_name, ' ', u.last_name) as uploader_name
              FROM loan_documents d
              LEFT JOIN users u ON d.uploaded_by = u.user_id
              $where
              ORDER BY d.uploaded_at DESC
              LIMIT :offset, :limit";

    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $length, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $total,
        'recordsFiltered' => $total,
        'data' => $data
    ]);

} catch (Exception $e) {
    error_log("Error in get_loan_documents.php: " . $e->getMessage());
    echo json_encode([
        'draw' => (int)($_GET['draw'] ?? 1),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Server error'
    ]);
}
