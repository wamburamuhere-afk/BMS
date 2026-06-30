<?php
/**
 * api/account/get_reconciliation_adjustments.php
 * ------------------------------------------------
 * Returns the bank_reconciliation_adjustments rows for a given reconciliation.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canView('bank_reconciliation')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}

$recId = (int)($_GET['reconciliation_id'] ?? 0);
if ($recId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reconciliation']); exit;
}

$rows = $pdo->prepare(
    "SELECT a.adjustment_id, a.type, a.amount, a.memo, a.adjustment_date, a.journal_entry_id,
            ac.account_code, ac.account_name
       FROM bank_reconciliation_adjustments a
       JOIN accounts ac ON ac.account_id = a.gl_account_id
      WHERE a.reconciliation_id = ?
      ORDER BY a.adjustment_date, a.adjustment_id"
);
$rows->execute([$recId]);

echo json_encode([
    'success'     => true,
    'adjustments' => $rows->fetchAll(PDO::FETCH_ASSOC),
]);
