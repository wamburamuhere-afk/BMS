<?php
// File: api/account/get_bank_statement.php
// Returns the bank-statement register (bank_transactions) for one cash/bank
// account, with the running balance per row. Feeds the Bank Statement view.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('bank_reconciliation')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

global $pdo;
$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to'] ?? '';

if ($account_id <= 0) { echo json_encode(['success' => true, 'data' => [], 'summary' => null]); exit; }

try {
    $where  = ["bt.bank_account_id = ?"];
    $params = [$account_id];
    if ($date_from !== '') { $where[] = "bt.transaction_date >= ?"; $params[] = $date_from; }
    if ($date_to !== '')   { $where[] = "bt.transaction_date <= ?"; $params[] = $date_to; }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT bt.transaction_date, bt.value_date, bt.description, bt.reference_number,
               bt.transaction_type, bt.amount, bt.balance_after, bt.status, bt.matching_status
          FROM bank_transactions bt
         WHERE {$whereSql}
      ORDER BY bt.transaction_date ASC, bt.transaction_id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalIn = 0.0; $totalOut = 0.0;
    foreach ($rows as $r) {
        if ($r['transaction_type'] === 'deposit') $totalIn  += (float)$r['amount'];
        else                                       $totalOut += (float)$r['amount'];
    }
    $closing = count($rows) ? (float)$rows[count($rows) - 1]['balance_after'] : null;

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'summary' => [
            'count'         => count($rows),
            'total_in'      => $totalIn,
            'total_out'     => $totalOut,
            'closing_balance' => $closing,
        ],
    ]);
} catch (PDOException $e) {
    error_log('get_bank_statement error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
