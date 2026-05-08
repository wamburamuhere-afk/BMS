<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

$query = "SELECT * FROM compliance_documents WHERE 1=1";
$params = [];

if ($type) {
    $query .= " AND document_type = :type";
    $params[':type'] = $type;
}
if ($status) {
    $query .= " AND status = :status";
    $params[':status'] = $status;
}

$countQuery = str_replace('SELECT *', 'SELECT COUNT(*)', $query);
$totalRecords = $pdo->prepare($countQuery);
$totalRecords->execute($params);
$total = $totalRecords->fetchColumn();

$query .= " ORDER BY uploaded_at DESC LIMIT :start, :length";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
$stmt->bindValue(':start', (int)$start, PDO::PARAM_INT);
$stmt->bindValue(':length', (int)$length, PDO::PARAM_INT);
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'draw' => (int)$draw,
    'recordsTotal' => (int)$total,
    'recordsFiltered' => (int)$total,
    'data' => $data
]);
