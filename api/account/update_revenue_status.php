<?php
/**
 * api/account/update_revenue_status.php
 *
 * Drives the revenue workflow: pending → reviewed → approved → posted, plus
 * (any pre-post) → rejected (cancel) and posted → rejected (VOID).
 *
 * The money is received ONLY at the Posted step: postInflow() books Dr bank /
 * Cr income and raises the bank balance, and a register DEPOSIT row is appended.
 * Posting is idempotent (only if not already posted). posted → rejected reverses
 * the ledger + register (reverseInflow + reverseBankTransaction).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/payment_source.php';   // postInflow / reverseInflow
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction / reverse
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0 || $status === '') throw new Exception('Missing required parameters');

    if (!in_array($status, ['reviewed', 'approved', 'posted', 'rejected'], true)) throw new Exception('Invalid status');

    $gateOk = ($status === 'reviewed') ? canReview('revenue')
            : (($status === 'approved') ? canApprove('revenue')
            : canEdit('revenue'));   // posted / rejected
    if (!$gateOk) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to ' . $status . ' a revenue']);
        exit;
    }

    $snap = $pdo->prepare("SELECT revenue_number, revenue_date, income_account_id, bank_account_id, amount,
                                  reference_number, description, project_id, status AS old_status, transaction_id
                             FROM revenues WHERE revenue_id = ?");
    $snap->execute([$id]);
    $r = $snap->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Revenue not found');
    $old = $r['old_status'];

    $transitions = [
        'pending'  => ['reviewed', 'rejected'],
        'reviewed' => ['approved', 'rejected'],
        'approved' => ['posted', 'rejected'],
        'posted'   => ['rejected'],   // void
    ];
    if (!isset($transitions[$old]) || !in_array($status, $transitions[$old], true)) {
        throw new Exception("Cannot move a $old revenue to $status");
    }

    $amount  = (float)$r['amount'];
    $bank    = (int)$r['bank_account_id'];
    $income  = (int)$r['income_account_id'];
    $ref     = $r['revenue_number'];
    $proj    = $r['project_id'] !== null ? (int)$r['project_id'] : null;
    $desc    = 'Revenue ' . $ref . ($r['description'] ? ': ' . substr((string)$r['description'], 0, 100) : '');

    $actor = workflowActorSnapshot();
    $pdo->beginTransaction();

    $extra = '';
    if ($status === 'reviewed') $extra = ', reviewed_by = ' . (int)$_SESSION['user_id'] . ", reviewed_by_name = " . $pdo->quote($actor['name']) . ", reviewed_by_role = " . $pdo->quote($actor['role']) . ", reviewed_at = NOW()";
    elseif ($status === 'approved') $extra = ', approved_by = ' . (int)$_SESSION['user_id'] . ", approved_by_name = " . $pdo->quote($actor['name']) . ", approved_by_role = " . $pdo->quote($actor['role']) . ", approved_at = NOW()";
    elseif ($status === 'posted') $extra = ', posted_by = ' . (int)$_SESSION['user_id'] . ', posted_at = NOW()';

    $pdo->prepare("UPDATE revenues SET status = ?, updated_by = ?, updated_at = NOW() $extra WHERE revenue_id = ?")
        ->execute([$status, $_SESSION['user_id'], $id]);

    $sigResult = ['has_signature' => true];
    $sigAction = ['reviewed' => 'reviewed', 'approved' => 'approved', 'posted' => 'approved'][$status] ?? null;
    if ($sigAction !== null) {
        $sigResult = workflowCaptureSignature($pdo, 'revenue', $id, $sigAction,
            $_SESSION['user_id'], $actor['name'], $actor['role']);
    }

    if ($status === 'posted') {
        if (empty($r['transaction_id'])) {
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
            if ($bank <= 0 || $income <= 0) throw new Exception('Revenue is missing its received-into or income account.');

            $txnId = postInflow($pdo, 'revenue', $bank, $income, $amount, $r['revenue_date'], $ref, $desc, $proj);
            if (!$txnId) throw new Exception('Ledger posting failed — check the received-into and income accounts.');

            recordBankTransaction($pdo, $bank, $amount, 'deposit', $r['revenue_date'], $ref, $desc, (int)$_SESSION['user_id']);

            $pdo->prepare("UPDATE revenues SET transaction_id = ? WHERE revenue_id = ?")->execute([$txnId, $id]);
        }
    } elseif ($status === 'rejected' && $old === 'posted' && !empty($r['transaction_id'])) {
        // VOID a posted revenue: reverse the cash receipt + register deposit.
        reverseInflow($pdo, (int)$r['transaction_id']);
        reverseBankTransaction($pdo, $bank, $ref, 'deposit');
        $pdo->prepare("UPDATE revenues SET transaction_id = NULL WHERE revenue_id = ?")->execute([$id]);
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Revenue $ref: $old → $status");

    $response = ['success' => true, 'message' => "Revenue updated to $status."];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('update_revenue_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
