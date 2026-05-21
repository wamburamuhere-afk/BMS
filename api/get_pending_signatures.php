<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        exit;
    }

    $draw   = intval($_GET['draw']   ?? 1);
    $start  = intval($_GET['start']  ?? 0);
    $length = intval($_GET['length'] ?? 10);
    $userId = $_SESSION['user_id'];

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        WHERE ds.signed_by = ? AND ds.status = 'pending'
    ");
    $countStmt->execute([$userId]);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT ds.id, ds.document_id, ds.status, ds.due_date,
               d.document_name,
               d.file_type AS document_type,
               CONCAT(u.first_name, ' ', u.last_name) AS requested_by_name,
               NULL AS customer_name
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        JOIN users u ON u.user_id = ds.requested_by
        WHERE ds.signed_by = ? AND ds.status = 'pending'
        ORDER BY ds.due_date ASC
        LIMIT ?, ?
    ");
    $dataStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(2, $start,  PDO::PARAM_INT);
    $dataStmt->bindValue(3, $length, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $total,
        'data'            => $rows,
    ]);

} catch (Exception $e) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $e->getMessage()]);
}
