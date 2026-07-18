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

    // Two kinds of "pending" relevant to this user: documents THEY still need
    // to sign (signed_by = them), and external requests THEY sent that the
    // outside party hasn't signed yet (requested_by = them, signer_type =
    // external — signed_by stays NULL for those until the external party
    // actually signs, so they'd never match the first condition).
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        WHERE ds.status = 'pending'
          AND (ds.signed_by = ? OR (ds.requested_by = ? AND ds.signer_type = 'external'))
    ");
    $countStmt->execute([$userId, $userId]);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT ds.id, ds.document_id, ds.status, ds.due_date, ds.signer_type,
               d.document_name,
               d.file_type AS document_type,
               CONCAT(u.first_name, ' ', u.last_name) AS requested_by_name,
               ds.signer_name,
               ds.signer_email AS customer_name
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        JOIN users u ON u.user_id = ds.requested_by
        WHERE ds.status = 'pending'
          AND (ds.signed_by = ? OR (ds.requested_by = ? AND ds.signer_type = 'external'))
        ORDER BY ds.due_date ASC
        LIMIT ?, ?
    ");
    $dataStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(2, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(3, $start,  PDO::PARAM_INT);
    $dataStmt->bindValue(4, $length, PDO::PARAM_INT);
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
