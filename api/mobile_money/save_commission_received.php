<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mm_posting.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('mm_commissions')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

csrf_check();

$networkId    = intval($_POST['network_id'] ?? 0);
$periodFrom   = trim($_POST['period_from'] ?? '');
$periodTo     = trim($_POST['period_to'] ?? '');
$amount       = (float)($_POST['amount_received'] ?? 0);
$bankAcctId   = intval($_POST['bank_account_id'] ?? 0);
$receiptDate  = trim($_POST['receipt_date'] ?? '');
$referenceNo  = trim($_POST['reference_no'] ?? '');
$notes        = trim($_POST['notes'] ?? '');

if (!$networkId || !$periodFrom || !$periodTo || $amount <= 0 || !$bankAcctId || !$receiptDate) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']); exit;
}

// Validate network exists
$net = $pdo->prepare("SELECT network_id FROM mm_networks WHERE network_id=? AND status='active'");
$net->execute([$networkId]);
if (!$net->fetch()) { echo json_encode(['success' => false, 'message' => 'Invalid network.']); exit; }

// Validate bank account exists
$acct = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id=?");
$acct->execute([$bankAcctId]);
if (!$acct->fetch()) { echo json_encode(['success' => false, 'message' => 'Invalid bank account.']); exit; }

try {
    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO mm_commissions_received (network_id, period_from, period_to, amount_received, bank_account_id, reference_no, notes, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,'draft',?,NOW())")
        ->execute([$networkId, $periodFrom, $periodTo, $amount, $bankAcctId, $referenceNo ?: null, $notes ?: null, $_SESSION['user_id']]);
    $creditId = (int)$pdo->lastInsertId();

    $description = "Commission receipt: " . ($referenceNo ?: "CR-$creditId");
    $entryId = postMMCommissionReceived($pdo, $creditId, $networkId, $amount, $bankAcctId, $receiptDate, $_SESSION['user_id'], $description);

    $pdo->prepare("UPDATE mm_commissions_received SET journal_entry_id=?, status='posted' WHERE credit_id=?")
        ->execute([$entryId, $creditId]);

    logActivity($pdo, $_SESSION['user_id'], "Posted MM commission receipt CR-$creditId TZS " . number_format($amount));

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Commission receipt posted successfully.', 'credit_id' => $creditId]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("save_commission_received error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
