<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/mm_float_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('mm_shifts')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$userId = (int)$_SESSION['user_id'];
$shifts = json_decode($_POST['shifts'] ?? '[]', true);

if (empty($shifts) || !is_array($shifts)) {
    echo json_encode(['success' => false, 'message' => 'No shifts provided']); exit;
}

$results = [];
$pdo->beginTransaction();
try {
    foreach ($shifts as $row) {
        $shiftId      = intval($row['shift_id'] ?? 0);
        $closingCash  = max(0, (float)str_replace(',', '', $row['closing_cash']  ?? 0));
        $closingFloat = max(0, (float)str_replace(',', '', $row['closing_float'] ?? 0));
        $closeNotes   = trim($row['close_notes'] ?? '');

        if (!$shiftId) {
            $results[] = ['shift_id' => $shiftId, 'success' => false, 'message' => 'Invalid shift ID'];
            continue;
        }

        $shiftStmt = $pdo->prepare("SELECT s.*, t.till_number, a.agent_name FROM mm_shifts s JOIN mm_tills t ON t.till_id = s.till_id JOIN mm_agents a ON a.agent_id = t.agent_id WHERE s.shift_id = ?");
        $shiftStmt->execute([$shiftId]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            $results[] = ['shift_id' => $shiftId, 'success' => false, 'message' => 'Shift not found'];
            continue;
        }
        if ($shift['status'] !== 'open') {
            $results[] = ['shift_id' => $shiftId, 'shift_code' => $shift['shift_code'], 'success' => false, 'message' => 'Shift is not open'];
            continue;
        }
        if (!mmUserCanCloseShift($pdo, $userId, $shift)) {
            $results[] = ['shift_id' => $shiftId, 'shift_code' => $shift['shift_code'], 'success' => false, 'message' => 'No grant to close shift on this till'];
            continue;
        }

        $totalsStmt = $pdo->prepare("SELECT COALESCE(SUM(cash_effect),0) AS net_cash, COALESCE(SUM(float_effect),0) AS net_float FROM mm_transactions WHERE shift_id = ? AND status = 'posted'");
        $totalsStmt->execute([$shiftId]);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

        $expectedCash  = (float)$shift['opening_cash']  + (float)$totals['net_cash'];
        $expectedFloat = (float)$shift['opening_float'] + (float)$totals['net_float'];
        $cashVariance  = $closingCash  - $expectedCash;
        $floatVariance = $closingFloat - $expectedFloat;

        $pdo->prepare("UPDATE mm_shifts SET closed_at = NOW(), closing_cash = ?, closing_float = ?, expected_cash = ?, expected_float = ?, cash_variance = ?, float_variance = ?, close_notes = ?, status = 'closed', closed_by = ? WHERE shift_id = ?")
            ->execute([$closingCash, $closingFloat, $expectedCash, $expectedFloat, $cashVariance, $floatVariance, $closeNotes, $userId, $shiftId]);

        mmTakeFloatSnapshot($pdo, $shift['till_id'], $closingFloat, $closingCash);
        logActivity($pdo, $userId, "Closed MM shift: {$shift['shift_code']} till {$shift['till_number']} cash_var=$cashVariance float_var=$floatVariance");

        $results[] = [
            'shift_id'      => $shiftId,
            'shift_code'    => $shift['shift_code'],
            'till_number'   => $shift['till_number'],
            'success'       => true,
            'cash_variance' => $cashVariance,
            'float_variance'=> $floatVariance,
            'expected_cash' => $expectedCash,
            'expected_float'=> $expectedFloat,
        ];
    }

    $pdo->commit();

    $closed = array_values(array_filter($results, fn($r) => $r['success']));
    $failed = array_values(array_filter($results, fn($r) => !$r['success']));

    echo json_encode([
        'success' => count($closed) > 0,
        'message' => count($closed) . ' shift(s) closed' . (count($failed) > 0 ? ', ' . count($failed) . ' skipped.' : '.'),
        'results' => array_values($results),
        'closed'  => count($closed),
        'failed'  => count($failed),
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("batch_close_shifts.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
