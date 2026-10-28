<?php
// api/account/get_bank_reconciliations.php — DataTables source for
// app/constant/accounts/bank_reconciliation.php (server-side).
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Unauthorized']);
    exit;
}
if (!canView('bank_reconciliation')) {
    http_response_code(403);
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Permission denied']);
    exit;
}

$req    = $_POST + $_GET;   // the page posts its DataTables request
$draw   = (int)($req['draw'] ?? 0);
$start  = max(0, (int)($req['start'] ?? 0));
$length = (int)($req['length'] ?? 10);
$length = $length < 0 ? 100000 : max(1, min(1000, $length));
$search = trim((string)($req['search']['value'] ?? ''));

// Sorting by the column's DATA name (DataTables sends columns[i][data]); S/No
// (no data name) is the normal newest-first order, reversed when descending.
$sortable = [
    'reconciliation_id'   => 'br.reconciliation_id',
    'account_name'        => 'a.account_name',
    'reconciliation_date' => 'br.reconciliation_date',
    'statement_balance'   => 'br.statement_balance',
    'difference'          => 'br.difference',
    'status'              => 'br.status',
];
$ocol  = (int)($req['order'][0]['column'] ?? 0);
$odir  = strtolower((string)($req['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$odata = (string)($req['columns'][$ocol]['data'] ?? '');
if ($ocol !== 0 && isset($sortable[$odata])) {
    $orderSql = $sortable[$odata] . " $odir, br.reconciliation_id $odir";
} else {
    $normal   = $odir === 'DESC' ? 'ASC' : 'DESC';            // S/No asc = newest first
    $orderSql = "br.reconciliation_id $normal";
}

$from = "FROM bank_reconciliations br
         LEFT JOIN accounts a ON br.bank_account_id = a.account_id
         LEFT JOIN users u ON br.prepared_by = u.user_id";
$where = " WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (a.account_name LIKE ? OR br.notes LIKE ? OR br.status LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

try {
    $totalData = (int)$pdo->query("SELECT COUNT(*) FROM bank_reconciliations")->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) $from $where");
    $st->execute($params);
    $totalFiltered = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT br.*, a.account_name, a.account_code, u.username AS preparer_name
                         $from $where ORDER BY $orderSql LIMIT $length OFFSET $start");
    $st->execute($params);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $totalData,
        'recordsFiltered' => $totalFiltered,
        'data'            => $data,
    ]);
} catch (PDOException $e) {
    error_log('get_bank_reconciliations: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Database error.']);
}
