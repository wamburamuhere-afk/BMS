<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('mm_shifts')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$tillId = intval($_GET['till_id'] ?? 0);
if (!$tillId) { echo json_encode(['success' => false, 'message' => 'Invalid till']); exit; }
mmRequireTill($tillId);   // agent grant: read only tills the user can see (admins bypass)

// Last snapshot for this till
$snapStmt = $pdo->prepare("
    SELECT snapshot_at, float_balance
    FROM mm_float_snapshots
    WHERE till_id = ?
    ORDER BY snapshot_at DESC
    LIMIT 1
");
$snapStmt->execute([$tillId]);
$lastSnap = $snapStmt->fetch(PDO::FETCH_ASSOC);

if (!$lastSnap) {
    // New till — no snapshot history
    echo json_encode(['success' => true, 'expected_float' => 0, 'last_snapshot_at' => null, 'is_new_till' => true]);
    exit;
}

// Net float_effect from posted transactions recorded after the last snapshot
$effStmt = $pdo->prepare("
    SELECT COALESCE(SUM(float_effect), 0) AS net_effect
    FROM mm_transactions
    WHERE till_id = ? AND status = 'posted' AND created_at > ?
");
$effStmt->execute([$tillId, $lastSnap['snapshot_at']]);
$netEffect = (float)$effStmt->fetchColumn();

echo json_encode([
    'success'          => true,
    'expected_float'   => round((float)$lastSnap['float_balance'] + $netEffect, 2),
    'last_snapshot_at' => $lastSnap['snapshot_at'],
    'is_new_till'      => false,
]);
