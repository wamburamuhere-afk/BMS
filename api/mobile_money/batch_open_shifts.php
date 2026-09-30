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

$userId = (int)$_SESSION['user_id'];
$tills  = json_decode($_POST['tills'] ?? '[]', true);

if (empty($tills) || !is_array($tills)) {
    echo json_encode(['success' => false, 'message' => 'No tills selected']); exit;
}

$results = [];
$pdo->beginTransaction();
try {
    foreach ($tills as $row) {
        $tillId       = intval($row['till_id'] ?? 0);
        $openingCash  = max(0, (float)str_replace(',', '', $row['opening_cash']  ?? 0));
        $openingFloat = max(0, (float)str_replace(',', '', $row['opening_float'] ?? 0));

        if (!$tillId) {
            $results[] = ['till_id' => $tillId, 'success' => false, 'message' => 'Invalid till ID'];
            continue;
        }

        $tillStmt = $pdo->prepare("SELECT t.*, a.agent_name FROM mm_tills t JOIN mm_agents a ON a.agent_id = t.agent_id WHERE t.till_id = ? AND t.status = 'active'");
        $tillStmt->execute([$tillId]);
        $till = $tillStmt->fetch(PDO::FETCH_ASSOC);
        if (!$till) {
            $results[] = ['till_id' => $tillId, 'till_number' => '?', 'success' => false, 'message' => 'Till not found or inactive'];
            continue;
        }

        if (!mmUserCanOnTill($pdo, $userId, $tillId, 'can_open_shift')) {
            $results[] = ['till_id' => $tillId, 'till_number' => $till['till_number'], 'success' => false, 'message' => 'No grant to open shift on this till'];
            continue;
        }

        $existStmt = $pdo->prepare("SELECT shift_id FROM mm_shifts WHERE till_id = ? AND status = 'open' LIMIT 1");
        $existStmt->execute([$tillId]);
        if ($existStmt->fetch()) {
            $results[] = ['till_id' => $tillId, 'till_number' => $till['till_number'], 'success' => false, 'message' => 'This till already has an open shift'];
            continue;
        }

        $shiftCode = nextCode($pdo, 'MM-SFT');
        $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, opening_cash, opening_float, status, created_at) VALUES (?,?,?,NOW(),?,?,'open',NOW())")
            ->execute([$shiftCode, $tillId, $userId, $openingCash, $openingFloat]);
        $shiftId = (int)$pdo->lastInsertId();

        mmTakeFloatSnapshot($pdo, $tillId, $openingFloat, $openingCash);
        logActivity($pdo, $userId, "Opened MM shift: $shiftCode on till {$till['till_number']} ({$till['agent_name']})");

        $results[] = [
            'till_id'    => $tillId,
            'till_number'=> $till['till_number'],
            'shift_code' => $shiftCode,
            'shift_id'   => $shiftId,
            'success'    => true,
        ];
    }

    $pdo->commit();

    $opened = array_values(array_filter($results, fn($r) => $r['success']));
    $failed = array_values(array_filter($results, fn($r) => !$r['success']));

    echo json_encode([
        'success' => count($opened) > 0,
        'message' => count($opened) . ' shift(s) opened' . (count($failed) > 0 ? ', ' . count($failed) . ' skipped.' : '.'),
        'results' => array_values($results),
        'opened'  => count($opened),
        'failed'  => count($failed),
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("batch_open_shifts.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
