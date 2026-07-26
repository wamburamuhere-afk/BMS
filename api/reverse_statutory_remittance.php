<?php
// File: api/reverse_statutory_remittance.php
// Reverses a remitted statutory obligation (PAYE/NSSF/SDL) — the counterpart to
// remit_statutory.php. Posts a contra of the exact legs originally posted
// (Style B: never delete/edit a posted entry) and restores the obligation to
// 'pending' so it can be re-remitted if needed. Reachable only from the
// Statutory Remittances page, never generically from the Journal Entries screen.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';   // reverseOutflowContra
require_once __DIR__ . '/../core/recon_period_lock.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
// Same gate as remit_statutory.php.
if (!canEdit('payroll')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied: you cannot reverse a statutory remittance']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
if (function_exists('csrf_check')) csrf_check();

global $pdo;
$id = (int)($_POST['remittance_id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'message' => 'Remittance ID required']); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM statutory_remittances WHERE remittance_id = ?");
    $stmt->execute([$id]);
    $rem = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rem) { echo json_encode(['success' => false, 'message' => 'Remittance not found']); exit; }
    if ($rem['status'] !== 'paid') { echo json_encode(['success' => false, 'message' => 'Only a remitted obligation can be reversed.']); exit; }
    if (empty($rem['transaction_id'])) { echo json_encode(['success' => false, 'message' => 'No payment transaction is linked to this remittance.']); exit; }

    $txnId = (int)$rem['transaction_id'];

    // Period lock: block reversal if the original entry falls in a finalized reconciliation period
    $mirrorEntry = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? AND status = 'posted' LIMIT 1");
    $mirrorEntry->execute([$txnId]);
    if ($eid = $mirrorEntry->fetchColumn()) {
        assertNotInFinalizedReconPeriod($pdo, (int)$eid);
    }

    $label = strtoupper($rem['tax_type']) . ' ' . $rem['period'];

    $pdo->beginTransaction();

    $rev = reverseOutflowContra($pdo, $txnId, (int)$_SESSION['user_id']);
    if (empty($rev['reversed'])) {
        throw new Exception('Could not reverse the remittance ledger entry (' . ($rev['reason'] ?? 'unknown') . ').');
    }

    $pdo->prepare("
        UPDATE statutory_remittances
           SET status = 'pending', paid_date = NULL, paid_from_account_id = NULL, transaction_id = NULL, updated_at = NOW()
         WHERE remittance_id = ?
    ")->execute([$id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Reversed remittance {$label}", "Amount: " . number_format((float)$rem['amount'], 2));
    logAudit($pdo, $_SESSION['user_id'], 'statutory_remittance_reversed', [
        'activity_type' => 'update',
        'entity_type'   => 'statutory_remittance',
        'entity_id'     => $id,
        'description'   => "Reversed remittance {$label} = " . number_format((float)$rem['amount'], 2),
    ]);

    echo json_encode(['success' => true, 'message' => "Remittance reversed. {$label} is pending again and can be re-remitted."]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
