<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

// DataTables request
$request = $_REQUEST;

$col = array(
    0 => 'br.reconciliation_id',
    1 => 'a.account_name',
    2 => 'br.statement_date',
    3 => 'br.statement_balance',
    4 => 'br.difference',
    5 => 'br.status',
    6 => 'br.reconciliation_id'
);

$sql = "SELECT br.*, a.account_name, a.account_code, u.username as preparer_name 
        FROM bank_reconciliations br 
        LEFT JOIN accounts a ON br.bank_account_id = a.account_id
        LEFT JOIN users u ON br.prepared_by = u.user_id
        WHERE 1=1";

// Search
if (!empty($request['search']['value'])) {
    $search = $request['search']['value'];
    $sql .= " AND (a.account_name LIKE '%$search%' OR br.notes LIKE '%$search%' OR br.status LIKE '%$search%')";
}

// Order
$sql .= " ORDER BY " . $col[$request['order'][0]['column']] . " " . $request['order'][0]['dir'];

// Pagination
$limit = "";
if ($request['length'] != -1) {
    $limit = " LIMIT " . $request['start'] . " ," . $request['length'];
}

$query = $pdo->prepare($sql . $limit);
$query->execute();
$data = $query->fetchAll(PDO::FETCH_ASSOC);

// Count total
$query = $pdo->prepare("SELECT COUNT(*) FROM bank_reconciliations");
$query->execute();
$totalData = $query->fetchColumn();

// Count filtered
$sql = "SELECT COUNT(*) 
        FROM bank_reconciliations br 
        LEFT JOIN accounts a ON br.bank_account_id = a.account_id
        WHERE 1=1";
if (!empty($request['search']['value'])) {
    $sql .= " AND (a.account_name LIKE '%$search%' OR br.notes LIKE '%$search%' OR br.status LIKE '%$search%')";
}
$query = $pdo->prepare($sql);
$query->execute();
$totalFiltered = $query->fetchColumn();

$json_data = array(
    "draw" => intval($request['draw']),
    "recordsTotal" => intval($totalData),
    "recordsFiltered" => intval($totalFiltered),
    "data" => $data
);

echo json_encode($json_data);
?>
