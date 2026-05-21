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
        WHERE ds.signed_by = ? AND ds.status = 'signed'
    ");
    $countStmt->execute([$userId]);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT ds.id, ds.document_id, ds.signature_position, ds.ip_address, ds.signed_at,
               d.document_name,
               d.file_type AS document_type,
               NULL        AS customer_name
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        WHERE ds.signed_by = ? AND ds.status = 'signed'
        ORDER BY ds.signed_at DESC
        LIMIT ?, ?
    ");
    $dataStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(2, $start,  PDO::PARAM_INT);
    $dataStmt->bindValue(3, $length, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $signedCount = $total;

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $total,
        'stats'           => ['signedDocuments' => $signedCount],
        'data'            => $rows,
    ]);

} catch (Exception $e) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $e->getMessage()]);
}
