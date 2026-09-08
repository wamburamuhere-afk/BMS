<?php
/**
 * API: Close/End Shift
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/pos_shift_reporting.php';
require_once __DIR__ . '/../../core/pos_denominations.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit();
}

if (!canEdit('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access Denied: you do not have permission to close POS shifts')]);
    exit();
}
csrf_check();

try {
    global $pdo;

    $user_id = $_SESSION['user_id'];
    $ending_cash = isset($_POST['ending_cash']) ? floatval($_POST['ending_cash']) : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    // Phase 20 (pos_upgrade_plan.md §8) — optional cash denomination
    // breakdown. Never required (backward compatible) — the single
    // ending_cash total stays authoritative either way.
    $denominations = [];
    if (!empty($_POST['denominations'])) {
        $decoded = json_decode($_POST['denominations'], true);
        if (is_array($decoded)) {
            $denomCheck = validateDenominationBreakdown($decoded, $ending_cash);
            if (!$denomCheck['valid']) {
                echo json_encode(['success' => false, 'message' => $denomCheck['error']]);
                exit();
            }
            $denominations = $decoded;
        }
    }

    // Force-close support: Shift History (canEdit('pos') — same gate that already
    // lets a supervisor/admin VIEW every cashier's shifts) can pass an explicit
    // shift_id to close a stuck shift left open by someone else (crashed browser,
    // forgot to log out). Ordinary self-close (the POS page's own "End Shift"
    // button) never sends shift_id and keeps using the session's own shift,
    // exactly as before.
    $requested_shift_id = isset($_POST['shift_id']) ? (int)$_POST['shift_id'] : 0;

    if ($requested_shift_id) {
        $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND status = 'active'");
        $stmt->execute([$requested_shift_id]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        $shift_id = $requested_shift_id;
        $is_force_close = $shift && (int)$shift['user_id'] !== (int)$user_id;
    } else {
        // Get active shift
        $shift_id = isset($_SESSION['shift_id']) ? $_SESSION['shift_id'] : null;

        if (!$shift_id) {
            echo json_encode([
                'success' => false,
                'message' => t('No active shift found')
            ]);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$shift_id, $user_id]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_force_close = false;
    }

    if (!$shift) {
        echo json_encode([
            'success' => false,
            'message' => t('Active shift not found')
        ]);
        exit();
    }
    
    // Calculate expected cash
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END), 0) as cash_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) as cash_out,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'sale' THEN amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'refund' THEN amount ELSE 0 END), 0) as cash_refunds
        FROM cash_register_transactions 
        WHERE shift_id = ?
    ");
    $stmt->execute([$shift_id]);
    $cash_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $expected_cash = $shift['starting_cash'] +
                    $cash_data['cash_in'] -
                    $cash_data['cash_out'] +
                    $cash_data['cash_sales'] -
                    $cash_data['cash_refunds'];

    $cash_difference = $ending_cash - $expected_cash;

    // Phase 8 (pos_upgrade_plan.md §7) — populate the per-tender totals that were
    // defined on this table from day one but never written by this endpoint (all
    // stayed at their 0.00 default forever). See core/pos_shift_reporting.php for
    // why this reads pos_sales directly rather than cash_register_transactions.
    $totals = posShiftTenderTotals($pdo, $shift_id);
    $total_sales   = $totals['total_sales'];
    $total_cash    = $totals['total_cash_sales'];
    $total_card    = $totals['total_card_sales'];
    $total_mobile  = $totals['total_mobile_sales'];
    $total_credit  = $totals['total_credit_sales'];
    $total_refunds = $totals['total_refunds'];

    // Close shift
    $stmt = $pdo->prepare("
        UPDATE cash_register_shifts
        SET end_time = NOW(),
            ending_cash = ?,
            expected_cash = ?,
            cash_difference = ?,
            cash_in = ?,
            cash_out = ?,
            total_sales = ?,
            total_cash_sales = ?,
            total_card_sales = ?,
            total_mobile_sales = ?,
            total_credit_sales = ?,
            total_refunds = ?,
            notes = ?,
            status = 'closed',
            closed_by = ?,
            updated_at = NOW()
        WHERE shift_id = ?
    ");

    $stmt->execute([
        $ending_cash, $expected_cash, $cash_difference,
        $cash_data['cash_in'], $cash_data['cash_out'],
        $total_sales, $total_cash, $total_card, $total_mobile, $total_credit, $total_refunds,
        $notes, $user_id, $shift_id
    ]);

    // Phase 20 — persist the validated breakdown, if one was submitted.
    if (!empty($denominations)) {
        saveDenominationBreakdown($pdo, (int)$shift_id, 'close', $denominations);
    }

    // Clear session shift — only the acting user's OWN session shift, never the
    // one being force-closed on someone else's behalf (that shift lives in a
    // different session entirely, and this admin may have their own separate
    // active shift open at the same time).
    if (!$is_force_close && ($_SESSION['shift_id'] ?? null) == $shift_id) {
        unset($_SESSION['shift_id']);
    }

    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    if ($is_force_close) {
        logActivity($pdo, $user_id, 'Force-Close POS Shift', "$username force-closed POS shift #{$shift['shift_code']}, opened by another cashier (Ending Cash: " . number_format($ending_cash, 2) . ")");
        logAudit($pdo, $user_id, 'pos_shift_force_close', [
            'entity_type' => 'cash_register_shift',
            'entity_id'   => $shift_id,
            'old_values'  => ['status' => 'active', 'user_id' => $shift['user_id']],
            'new_values'  => ['status' => 'closed', 'closed_by' => $user_id, 'ending_cash' => $ending_cash],
        ]);
    } else {
        logActivity($pdo, $user_id, 'Close POS Shift', "$username closed POS shift #{$shift['shift_code']} (Ending Cash: " . number_format($ending_cash, 2) . ")");
    }

    echo json_encode([
        'success' => true,
        'message' => t('Shift closed successfully'),
        'shift_id' => $shift_id,
        'starting_cash' => $shift['starting_cash'],
        'ending_cash' => $ending_cash,
        'expected_cash' => $expected_cash,
        'cash_difference' => $cash_difference,
        'total_sales' => $total_sales,
        'total_cash_sales' => $total_cash,
        'total_card_sales' => $total_card,
        'total_mobile_sales' => $total_mobile,
        'total_credit_sales' => $total_credit,
        'total_refunds' => $total_refunds
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
