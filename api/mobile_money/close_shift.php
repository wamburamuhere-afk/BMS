<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/mm_float_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('mm_shifts')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$shiftId      = intval($_POST['shift_id'] ?? 0);
$closingCash  = (float)str_replace(',', '', $_POST['closing_cash'] ?? 0);
$closingFloat = (float)str_replace(',', '', $_POST['closing_float'] ?? 0);
$closeNotes   = trim($_POST['close_notes'] ?? '');
$userId       = (int)$_SESSION['user_id'];

if (!$shiftId)       { echo json_encode(['success' => false, 'message' => 'Shift ID required']); exit; }
if ($closingCash < 0)  { echo json_encode(['success' => false, 'message' => 'Closing cash cannot be negative']); exit; }
if ($closingFloat < 0) { echo json_encode(['success' => false, 'message' => 'Closing float cannot be negative']); exit; }

try {
    // Load shift + till
    $shift = $pdo->prepare("SELECT s.*, t.till_number, a.agent_name FROM mm_shifts s JOIN mm_tills t ON t.till_id=s.till_id JOIN mm_agents a ON a.agent_id=t.agent_id WHERE s.shift_id=?");
    $shift->execute([$shiftId]);
    $shift = $shift->fetch(PDO::FETCH_ASSOC);

    if (!$shift) { echo json_encode(['success' => false, 'message' => 'Shift not found']); exit; }
    if ($shift['status'] !== 'open') { echo json_encode(['success' => false, 'message' => 'Shift is not open']); exit; }

    // Check grant
    if (!mmUserCanOnTill($pdo, $userId, $shift['till_id'], 'can_close_shift')) {
        echo json_encode(['success' => false, 'message' => 'You are not granted to close a shift on this till']);
        exit;
    }

    // Compute expected from opening + transaction effects
    $row = $pdo->prepare("SELECT COALESCE(SUM(cash_effect),0) AS net_cash, COALESCE(SUM(float_effect),0) AS net_float
                          FROM mm_transactions WHERE shift_id=? AND status='posted'");
    $row->execute([$shiftId]);
    $totals = $row->fetch(PDO::FETCH_ASSOC);

    $expectedCash  = (float)$shift['opening_cash']  + (float)$totals['net_cash'];
    $expectedFloat = (float)$shift['opening_float'] + (float)$totals['net_float'];
    $cashVariance  = $closingCash  - $expectedCash;
    $floatVariance = $closingFloat - $expectedFloat;

    $pdo->prepare("UPDATE mm_shifts SET
        closed_at=NOW(), closing_cash=?, closing_float=?,
        expected_cash=?, expected_float=?,
        cash_variance=?, float_variance=?,
        close_notes=?, status='closed', closed_by=?
        WHERE shift_id=?")
        ->execute([$closingCash, $closingFloat, $expectedCash, $expectedFloat, $cashVariance, $floatVariance, $closeNotes, $userId, $shiftId]);

    // Take closing float snapshot
    mmTakeFloatSnapshot($pdo, $shift['till_id'], $closingFloat, $closingCash);

    logActivity($pdo, $userId, "Closed MM shift: {$shift['shift_code']} till {$shift['till_number']} cash_var=$cashVariance float_var=$floatVariance");

    $varMsg = '';
    if ($cashVariance != 0) $varMsg .= ' Cash variance: TZS ' . number_format($cashVariance);
    if ($floatVariance != 0) $varMsg .= ' Float variance: TZS ' . number_format($floatVariance);

    echo json_encode([
        'success'        => true,
        'message'        => "Shift {$shift['shift_code']} closed." . ($varMsg ? " Variances:$varMsg" : ' No variances.'),
        'cash_variance'  => $cashVariance,
        'float_variance' => $floatVariance,
        'expected_cash'  => $expectedCash,
        'expected_float' => $expectedFloat,
    ]);

} catch (PDOException $e) {
    error_log("close_shift.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
