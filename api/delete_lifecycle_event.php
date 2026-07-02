<?php
// API: Soft-delete a Lifecycle Event (HR Actions — Tier 1, Phase 1.3)
// Approved events are immutable history — only pending/rejected/cancelled
// events can be deleted, and only softly (§12).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('employee_lifecycle')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete HR actions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$event_id = intval($_POST['event_id'] ?? $_POST['id'] ?? 0);
if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit;
}

if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_lifecycle_events', 'event_id', $event_id);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM employee_lifecycle_events WHERE event_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$event_id]);
    $ev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ev) throw new Exception('Event not found');
    if (!in_array($ev['status'], ['pending', 'rejected', 'cancelled'], true)) {
        throw new Exception('Approved events are permanent history and cannot be deleted');
    }

    $pdo->prepare("UPDATE employee_lifecycle_events SET status = 'deleted', updated_by = ? WHERE event_id = ?")
        ->execute([$_SESSION['user_id'], $event_id]);

    logAudit($pdo, $_SESSION['user_id'], 'delete', [
        'activity_type' => 'delete',
        'entity_type'   => 'employee_lifecycle',
        'entity_id'     => $event_id,
        'description'   => "Deleted {$ev['event_type']} \"{$ev['title']}\" (was {$ev['status']})",
        'old_values'    => ['status' => $ev['status']],
        'new_values'    => ['status' => 'deleted'],
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Delete HR action',
        "deleted {$ev['event_type']} \"{$ev['title']}\" (event #$event_id)");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'HR action deleted']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
