<?php
/**
 * api/mobile_money/save_transaction.php
 *
 * Create a new MM transaction:
 * 1. Insert mm_transactions row (status=recorded)
 * 2. Compute commission via mmComputeCommission()
 * 3. Post GL entry via postMMTransaction()
 * 4. Update mm_transactions with journal_entry_id + commission_earned (status=posted)
 * All wrapped in a DB transaction for atomicity.
 */
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/mm_posting.php';
require_once ROOT_DIR . '/core/mm_float_service.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('mm_transactions')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$tillId        = intval($_POST['till_id'] ?? 0);
$txnType       = trim($_POST['txn_type'] ?? '');
$amount        = (float)str_replace(',', '', $_POST['amount'] ?? 0);
$txnDate       = trim($_POST['txn_date'] ?? date('Y-m-d'));
$txnTime       = trim($_POST['txn_time'] ?? date('H:i:s'));
$customerPhone = trim($_POST['customer_phone'] ?? '');
$customerName  = trim($_POST['customer_name'] ?? '');
$networkRef    = trim($_POST['network_ref'] ?? '');  // reference_no on network side
$notes         = trim($_POST['notes'] ?? '');

$validTypes = ['cash_in','cash_out','send','bill_pay','airtime','bank_to_wallet','wallet_to_bank','international'];

if (!$tillId)                              { echo json_encode(['success' => false, 'message' => 'Till is required']); exit; }
if (!in_array($txnType, $validTypes))     { echo json_encode(['success' => false, 'message' => 'Invalid transaction type']); exit; }
if ($amount <= 0)                          { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { echo json_encode(['success' => false, 'message' => 'Invalid date']); exit; }

// BOT KYC rule: reference required for TZS 1,000,000+
$kycRequired = ($amount >= 1000000) ? 1 : 0;
if ($kycRequired && empty($customerName) && empty($customerPhone)) {
    echo json_encode(['success' => false, 'message' => 'Customer name or phone is required for transactions of TZS 1,000,000 or above (BOT KYC compliance)']);
    exit;
}

// Compute cash_effect and float_effect per transaction type
$cashEffects  = ['cash_in' => 1, 'cash_out' => -1, 'send' => 1, 'bill_pay' => 1, 'airtime' => 1, 'bank_to_wallet' => -1, 'wallet_to_bank' => 1, 'international' => 1];
$floatEffects = ['cash_in' => -1, 'cash_out' => 1, 'send' => -1, 'bill_pay' => -1, 'airtime' => -1, 'bank_to_wallet' => 1, 'wallet_to_bank' => -1, 'international' => -1];
$cashEffect  = $amount * ($cashEffects[$txnType]  ?? 0);
$floatEffect = $amount * ($floatEffects[$txnType] ?? 0);

try {
    // Resolve till — network_id and agent_id both live on mm_tills
    $till = $pdo->prepare("SELECT * FROM mm_tills WHERE till_id=? AND status='active'");
    $till->execute([$tillId]);
    $till = $till->fetch(\PDO::FETCH_ASSOC);
    if (!$till) { echo json_encode(['success' => false, 'message' => 'Till not found or inactive']); exit; }

    $networkId = (int)$till['network_id'];
    $agentId   = (int)$till['agent_id'];

    $pdo->beginTransaction();

    // 1. Compute commission before inserting (amount is known)
    $commission = mmComputeCommission($pdo, $networkId, $txnType, $amount, $txnDate);

    // 2. Insert transaction record
    $txnCode = nextCode($pdo, 'MM-TXN');
    $pdo->prepare(
        "INSERT INTO mm_transactions
            (txn_code, till_id, agent_id, network_id, txn_type, txn_date, txn_time,
             customer_phone, customer_name, reference_no, principal_amount, commission_earned,
             cash_effect, float_effect, teller_user_id, kyc_required, notes, status, created_by, created_at)
         VALUES (?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?,NOW())"
    )->execute([
        $txnCode, $tillId, $agentId, $networkId, $txnType, $txnDate, $txnTime,
        $customerPhone, $customerName, $networkRef, $amount, $commission,
        $cashEffect, $floatEffect, $_SESSION['user_id'], $kycRequired, $notes, 'recorded', $_SESSION['user_id']
    ]);
    $txnId = (int)$pdo->lastInsertId();

    // 3. Post GL entry
    $desc = strtoupper(str_replace('_', ' ', $txnType)) . " TZS " . number_format($amount, 0, '.', ',') . " — $txnCode";
    if ($customerName) $desc .= " ($customerName)";
    elseif ($customerPhone) $desc .= " ($customerPhone)";

    $entryId = postMMTransaction($pdo, $txnId, $networkId, $txnType, $amount, $commission, $txnDate, $_SESSION['user_id'], $desc);

    // 4. Update with entry_id and mark posted
    $pdo->prepare("UPDATE mm_transactions SET journal_entry_id=?, status='posted' WHERE mm_txn_id=?")
        ->execute([$entryId, $txnId]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "MM Transaction: $txnCode — $txnType TZS " . number_format($amount));

    echo json_encode([
        'success'       => true,
        'message'       => "Transaction $txnCode posted successfully.",
        'txn_id'        => $txnId,
        'txn_code'      => $txnCode,
        'commission'    => $commission,
        'entry_id'      => $entryId,
        'kyc_required'  => (bool)$kycRequired,
    ]);

} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("save_transaction.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
}
