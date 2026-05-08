<?php
// ajax/get_collateral_documents.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $loan_id = (int)($_GET['loan_id'] ?? 0);
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 10);

    if (!$loan_id) {
        echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        exit();
    }

    // Select from collateral_attachments
    $query = "SELECT ca.*, lc.type as collateral_type, lc.description as collateral_desc
              FROM collateral_attachments ca
              LEFT JOIN loan_collateral lc ON ca.collateral_id = lc.id
              WHERE ca.loan_id = :loan_id";
              
    $countQuery = "SELECT COUNT(*) FROM collateral_attachments WHERE loan_id = :loan_id";

    $params = [':loan_id' => $loan_id];

    $totalRecords = $pdo->prepare($countQuery);
    $totalRecords->execute($params);
    $total = (int)$totalRecords->fetchColumn();

    $query .= " ORDER BY ca.uploaded_at DESC LIMIT :start, :length";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':loan_id', $loan_id, PDO::PARAM_INT);
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':length', $length, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $total,
        'recordsFiltered' => $total,
        'data' => $data
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'draw' => $draw ?? 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
}
?>
