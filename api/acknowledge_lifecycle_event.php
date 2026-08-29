<?php
// API: Employee acknowledges their own warning/complaint (ESS, My HR "Record" tab).
// SECURITY LINCHPIN, same pattern as api/my_hr_data.php: the employee is resolved
// from the SESSION ONLY (users.employee_id) — an employee can only ever acknowledge
// an event on THEIR OWN record, never anyone else's, and HR/admin cannot set this
// on an employee's behalf (it must be the employee's own action to have any meaning).
// scope-audit: skip — same reasoning as my_hr_data.php: this is structurally
// own-record-only via the session link, a stronger control than project scope.
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canView('my_hr')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$eid = (int)($pdo->query("SELECT employee_id FROM users WHERE user_id = " . (int)$_SESSION['user_id'])->fetchColumn() ?: 0);
if (!$eid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'not_linked']);
    exit;
}

try {
    $event_id = (int)($_POST['event_id'] ?? 0);
    $note     = trim($_POST['acknowledgment_note'] ?? '');
    if ($event_id <= 0) throw new Exception('Missing event.');
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    $chk = $pdo->prepare("SELECT employee_id, event_type, status, acknowledged_at FROM employee_lifecycle_events WHERE event_id = ?");
    $chk->execute([$event_id]);
    $ev = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$ev) throw new Exception('Event not found.');
    if ((int)$ev['employee_id'] !== $eid) throw new Exception('Access denied: this is not your record.');
    if (!in_array($ev['event_type'], ['warning', 'complaint'], true)) throw new Exception('This event type does not require acknowledgment.');
    if ($ev['status'] !== 'approved') throw new Exception('This event is not yet finalised.');
    if (!empty($ev['acknowledged_at'])) throw new Exception('Already acknowledged.');

    $pdo->prepare("UPDATE employee_lifecycle_events SET acknowledged_at = NOW(), acknowledgment_note = ? WHERE event_id = ?")
        ->execute([($note !== '' ? $note : null), $event_id]);

    logActivity($pdo, $_SESSION['user_id'], 'Acknowledge HR notice', "Employee acknowledged {$ev['event_type']} (event #$event_id)");
    logAudit($pdo, $_SESSION['user_id'], 'update', [
        'activity_type' => 'update', 'entity_type' => 'employee_lifecycle_event', 'entity_id' => $event_id,
        'description' => "Employee #$eid acknowledged their own {$ev['event_type']}",
    ]);

    echo json_encode(['success' => true, 'message' => 'Acknowledged.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
