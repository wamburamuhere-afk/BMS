<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/mm_float_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('mm_float')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$tillId      = intval($_POST['till_id'] ?? 0);
$movType     = trim($_POST['movement_type'] ?? '');
$amount      = (float)str_replace(',', '', $_POST['amount'] ?? 0);
$date        = trim($_POST['movement_date'] ?? date('Y-m-d'));
$bankAcctId  = intval($_POST['bank_account_id'] ?? 0);
$referenceNo = trim($_POST['reference_no'] ?? '');
$notes       = trim($_POST['notes'] ?? '');
$userId      = (int)$_SESSION['user_id'];

$validTypes = ['float_topup', 'float_withdrawal', 'opening_balance', 'adjustment'];

if (!$tillId)                              { echo json_encode(['success' => false, 'message' => 'Till is required']); exit; }
if (!in_array($movType, $validTypes))      { echo json_encode(['success' => false, 'message' => 'Invalid movement type']); exit; }
if ($amount <= 0)                          { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['success' => false, 'message' => 'Invalid date']); exit; }
if (in_array($movType, ['float_topup', 'float_withdrawal']) && !$bankAcctId) {
    echo json_encode(['success' => false, 'message' => 'Bank account is required for top-ups and withdrawals']); exit;
}

try {
    // Verify till exists
    $till = $pdo->prepare("SELECT t.*, n.network_id FROM mm_tills t JOIN mm_networks n ON n.network_id=t.network_id WHERE t.till_id=? AND t.status='active'");
    $till->execute([$tillId]);
    $till = $till->fetch(PDO::FETCH_ASSOC);
    if (!$till) { echo json_encode(['success' => false, 'message' => 'Till not found or inactive']); exit; }

    $networkId = (int)$till['network_id'];
    $ref = $referenceNo ?: ($notes ?: strtoupper(str_replace('_', ' ', $movType)));

    $movId = mmRecordFloatMovement($pdo, $tillId, $networkId, $movType, $amount, $date, $userId, $ref, $bankAcctId);

    $typeName = ['float_topup'=>'Top-up','float_withdrawal'=>'Withdrawal','opening_balance'=>'Opening Balance','adjustment'=>'Adjustment'][$movType] ?? $movType;

    logActivity($pdo, $userId, "MM Float $typeName: TZS " . number_format($amount) . " till_id=$tillId");

    echo json_encode([
        'success'     => true,
        'message'     => "$typeName of TZS " . number_format($amount) . " posted successfully.",
        'movement_id' => $movId,
    ]);

} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("save_float_movement.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
