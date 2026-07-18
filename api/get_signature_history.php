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

    // Same two-kind logic as get_pending_signatures.php: documents THIS user
    // signed themselves (signed_by = them), plus external requests THEY sent
    // that the outside party has now completed (signed_by stays NULL for an
    // external signature — there's no user account to attribute it to — so
    // those rows can only be found via requested_by + signer_type).
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        WHERE ds.status = 'signed'
          AND (ds.signed_by = ? OR (ds.requested_by = ? AND ds.signer_type = 'external'))
    ");
    $countStmt->execute([$userId, $userId]);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT ds.id, ds.document_id, ds.signature_position, ds.ip_address, ds.signed_at,
               ds.signer_type, ds.signer_name,
               d.document_name,
               d.file_type AS document_type,
               ds.signer_email AS customer_name
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        WHERE ds.status = 'signed'
          AND (ds.signed_by = ? OR (ds.requested_by = ? AND ds.signer_type = 'external'))
        ORDER BY ds.signed_at DESC
        LIMIT ?, ?
    ");
    $dataStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(2, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(3, $start,  PDO::PARAM_INT);
    $dataStmt->bindValue(4, $length, PDO::PARAM_INT);
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
