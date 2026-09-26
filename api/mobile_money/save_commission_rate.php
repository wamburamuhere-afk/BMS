<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$method    = strtoupper($_POST['_method'] ?? 'SAVE');
$id        = intval($_POST['rate_id'] ?? 0);

if ($method === 'DELETE') {
    if (!canDelete('mm_commission_rates')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
    $pdo->prepare("UPDATE mm_commission_rates SET status='superseded' WHERE rate_id=?")->execute([$id]);
    logActivity($pdo, $_SESSION['user_id'], "Deleted MM commission rate id=$id");
    echo json_encode(['success' => true, 'message' => 'Rate band removed.']);
    exit;
}

$networkId   = intval($_POST['network_id'] ?? 0) ?: null;
$txnType     = $_POST['txn_type'] ?? '';
$amountFrom  = (float)str_replace(',', '', $_POST['amount_from'] ?? 0);
$amountTo    = strlen(trim($_POST['amount_to'] ?? '')) ? (float)str_replace(',', '', $_POST['amount_to']) : null;
$rateType    = in_array($_POST['rate_type'] ?? '', ['flat', 'percent']) ? $_POST['rate_type'] : 'flat';
$rateValue   = (float)str_replace(',', '', $_POST['rate_value'] ?? 0);
$minComm     = (float)str_replace(',', '', $_POST['min_commission'] ?? 0);
$maxComm     = strlen(trim($_POST['max_commission'] ?? '')) ? (float)str_replace(',', '', $_POST['max_commission']) : null;
$effFrom     = trim($_POST['effective_from'] ?? '') ?: date('Y-m-d');
$effTo       = trim($_POST['effective_to'] ?? '') ?: null;

$validTypes = ['cash_in','cash_out','send','bill_pay','airtime','bank_to_wallet','wallet_to_bank','international'];
if (!$networkId) { echo json_encode(['success' => false, 'message' => 'Network is required']); exit; }
if (!in_array($txnType, $validTypes)) { echo json_encode(['success' => false, 'message' => 'Invalid transaction type']); exit; }
if ($amountFrom < 0) { echo json_encode(['success' => false, 'message' => 'Amount From must be ≥ 0']); exit; }

try {
    if ($id) {
        if (!canEdit('mm_commission_rates')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("UPDATE mm_commission_rates SET network_id=?, txn_type=?, amount_from=?, amount_to=?,
                        rate_type=?, rate_value=?, min_commission=?, max_commission=?, effective_from=?, effective_to=?
                        WHERE rate_id=?")
            ->execute([$networkId, $txnType, $amountFrom, $amountTo, $rateType, $rateValue, $minComm, $maxComm, $effFrom, $effTo, $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated MM commission rate id=$id");
        echo json_encode(['success' => true, 'message' => 'Rate band updated.']);
    } else {
        if (!canCreate('mm_commission_rates')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("INSERT INTO mm_commission_rates
                        (network_id, txn_type, amount_from, amount_to, rate_type, rate_value, min_commission, max_commission,
                         effective_from, effective_to, status, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,'active',?,NOW())")
            ->execute([$networkId, $txnType, $amountFrom, $amountTo, $rateType, $rateValue, $minComm, $maxComm, $effFrom, $effTo, $_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created MM commission rate id=$newId ($txnType)");
        echo json_encode(['success' => true, 'message' => 'Rate band created.', 'id' => $newId]);
    }
} catch (PDOException $e) {
    error_log("save_commission_rate.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
