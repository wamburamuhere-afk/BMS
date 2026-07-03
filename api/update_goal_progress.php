<?php
// API: Update goal progress + status (Tier 3, Phase 3.4 — D23).
// Every update requires a progress note; the note goes into the
// logActivity/logAudit entry — the audit trail IS the progress history
// (no separate history table). Status transitions:
// not_started → in_progress → completed / cancelled.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to update goals']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $goal_id  = intval($_POST['goal_id'] ?? 0);
    $progress = intval($_POST['progress'] ?? -1);
    $note     = trim($_POST['note'] ?? '');
    $new_status = trim($_POST['status'] ?? '');   // optional explicit transition

    if (!$goal_id) throw new Exception('Goal ID is required');
    if ($progress < 0 || $progress > 100) throw new Exception('Progress must be between 0 and 100');
    if ($note === '') throw new Exception('A progress note is required');

    if (function_exists('assertScopeForEmployeeRecord')) {
        assertScopeForEmployeeRecord('employee_goals', 'goal_id', $goal_id);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM employee_goals WHERE goal_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$goal_id]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$goal) throw new Exception('Goal not found');

    $cur = $goal['status'];
    if (in_array($cur, ['completed', 'cancelled'], true)) throw new Exception("This goal is $cur and can no longer be updated");

    // Resolve the resulting status
    $status = $cur;
    if ($new_status !== '') {
        $allowed = [
            'not_started' => ['in_progress', 'cancelled', 'completed'],
            'in_progress' => ['completed', 'cancelled'],
        ];
        if ($new_status !== $cur && !in_array($new_status, $allowed[$cur] ?? [], true)) {
            throw new Exception("Cannot move a goal from $cur to $new_status");
        }
        $status = $new_status;
    } else {
        // Auto-advance from not_started once there's progress
        if ($cur === 'not_started' && $progress > 0) $status = 'in_progress';
    }
    if ($status === 'completed') $progress = 100;

    $pdo->prepare("UPDATE employee_goals SET progress = ?, status = ?, updated_by = ? WHERE goal_id = ?")
        ->execute([$progress, $status, $_SESSION['user_id'], $goal_id]);

    $emp = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
    $emp->execute([(int)$goal['employee_id']]);
    $er = $emp->fetch(PDO::FETCH_ASSOC);
    $emp_name = trim(($er['first_name'] ?? '') . ' ' . ($er['last_name'] ?? '')) ?: ('employee #' . $goal['employee_id']);

    // The note IS the history (D23)
    logActivity($pdo, $_SESSION['user_id'], 'Update goal progress',
        "goal '{$goal['subject']}' ($emp_name) → {$progress}% [{$status}]: $note");
    logAudit($pdo, $_SESSION['user_id'], 'update', [
        'activity_type' => 'update',
        'entity_type'   => 'employee_goal',
        'entity_id'     => $goal_id,
        'description'   => "Goal progress note: $note",
        'old_values'    => ['progress' => (int)$goal['progress'], 'status' => $cur],
        'new_values'    => ['progress' => $progress, 'status' => $status, 'note' => $note],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Progress updated to {$progress}%" . ($status !== $cur ? " ($status)" : '')]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
