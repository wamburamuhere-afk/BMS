<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/code_generator.php';
require_once ROOT_DIR . '/core/mm_float_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('mm_shifts')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$tillId       = intval($_POST['till_id'] ?? 0);
$openingCash  = (float)str_replace(',', '', $_POST['opening_cash'] ?? 0);
$openingFloat = (float)str_replace(',', '', $_POST['opening_float'] ?? 0);
$userId       = (int)$_SESSION['user_id'];

if (!$tillId)        { echo json_encode(['success' => false, 'message' => 'Till is required']); exit; }
if ($openingCash < 0)  { echo json_encode(['success' => false, 'message' => 'Opening cash cannot be negative']); exit; }
if ($openingFloat < 0) { echo json_encode(['success' => false, 'message' => 'Opening float cannot be negative']); exit; }

try {
    // Verify till exists and is active
    $till = $pdo->prepare("SELECT t.*, a.agent_name FROM mm_tills t JOIN mm_agents a ON a.agent_id=t.agent_id WHERE t.till_id=? AND t.status='active'");
    $till->execute([$tillId]);
    $till = $till->fetch(PDO::FETCH_ASSOC);
    if (!$till) { echo json_encode(['success' => false, 'message' => 'Till not found or inactive']); exit; }

    // Check grant (admins bypass)
    if (!mmUserCanOnTill($pdo, $userId, $tillId, 'can_open_shift')) {
        echo json_encode(['success' => false, 'message' => 'You are not granted to open a shift on this till']);
        exit;
    }

    // Enforce one open shift per till
    $existing = $pdo->prepare("SELECT shift_id FROM mm_shifts WHERE till_id=? AND status='open' LIMIT 1");
    $existing->execute([$tillId]);
    if ($existing->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This till already has an open shift. Close it before opening a new one.']);
        exit;
    }

    $shiftCode = nextCode($pdo, 'MM-SFT');
    $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, opening_cash, opening_float, status, created_at)
                   VALUES (?,?,?,NOW(),?,?,'open',NOW())")
        ->execute([$shiftCode, $tillId, $userId, $openingCash, $openingFloat]);
    $shiftId = (int)$pdo->lastInsertId();

    // Take initial float snapshot
    mmTakeFloatSnapshot($pdo, $tillId, $openingFloat, $openingCash);

    logActivity($pdo, $userId, "Opened MM shift: $shiftCode on till {$till['till_number']} ({$till['agent_name']})");

    echo json_encode([
        'success'    => true,
        'message'    => "Shift $shiftCode opened. Opening cash: TZS " . number_format($openingCash) . ", Float: TZS " . number_format($openingFloat),
        'shift_id'   => $shiftId,
        'shift_code' => $shiftCode,
    ]);

} catch (PDOException $e) {
    error_log("open_shift.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
