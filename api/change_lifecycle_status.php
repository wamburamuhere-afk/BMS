<?php
// API: Change Lifecycle Event Status (HR Actions — Tier 1, Phase 1.3)
// Transitions: pending → approved (approve) | rejected (reject) | cancelled (cancel).
// Approved/rejected are terminal — a wrong approval is corrected by a new
// counter-event, never by editing history. On approval the D4 effect is applied
// to the employees row in the SAME transaction (except resignations with a
// future last working day — D5 catch-up handles those when the date arrives).
// api/update_employee_status.php is untouched — both paths stay valid (D6).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/lifecycle_effects.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Method check (permission gate is per-action below)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 3. CSRF + input validation
csrf_check();

$event_id      = intval($_POST['event_id'] ?? 0);
$action        = trim($_POST['action'] ?? '');
$reject_reason = trim($_POST['reject_reason'] ?? '');

if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit;
}
if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}
if ($action === 'reject' && $reject_reason === '') {
    echo json_encode(['success' => false, 'message' => 'A reason is required to reject']);
    exit;
}

// 4. Project-scope gate — follows the event's employee to their project
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_lifecycle_events', 'event_id', $event_id);
}

// 5. Permission gate per action (reject is an approver's decision — house
//    convention, same as api/reject_leave.php using the approve verb)
if (in_array($action, ['approve', 'reject'], true) && !canApprove('employee_lifecycle')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to ' . $action . ' HR actions']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Re-read under lock — a parallel approval must not double-apply
    $stmt = $pdo->prepare("SELECT * FROM employee_lifecycle_events WHERE event_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$event_id]);
    $ev = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ev) throw new Exception('Event not found');
    if ($ev['status'] !== 'pending') throw new Exception('Only pending events can be ' . $action . 'd — this one is ' . $ev['status']);

    // cancel = creator or canEdit
    if ($action === 'cancel'
        && (int)$ev['created_by'] !== (int)$_SESSION['user_id']
        && !canEdit('employee_lifecycle')) {
        throw new Exception('Only the creator (or an editor) can cancel this event');
    }

    // Approve gate is the 'approve' permission alone (canApprove('employee_lifecycle'),
    // checked above) — same model as GRN/DN approval (api/approve_grn.php,
    // api/approve_dn.php), neither of which restricts approving your own submission.
    // No self-approval block here by design, per that decision.

    $emp = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
    $emp->execute([(int)$ev['employee_id']]);
    $emp_row  = $emp->fetch(PDO::FETCH_ASSOC);
    $emp_name = trim(($emp_row['first_name'] ?? '') . ' ' . ($emp_row['last_name'] ?? '')) ?: ('employee #' . $ev['employee_id']);

    // ── Transition ───────────────────────────────────────────────────────────
    if ($action === 'approve') {
        $pdo->prepare("UPDATE employee_lifecycle_events
                       SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_by = ?
                       WHERE event_id = ?")
            ->execute([$_SESSION['user_id'], $_SESSION['user_id'], $event_id]);

        // D5 — an approved resignation with a future last working day waits
        $defer = ($ev['event_type'] === 'resignation'
               && !empty($ev['end_date'])
               && strtotime($ev['end_date']) > strtotime(date('Y-m-d')));

        $effect = ['changed' => false, 'old' => [], 'new' => []];
        if (!$defer) {
            $effect = applyLifecycleEffectRow($pdo, $ev, (int)$_SESSION['user_id']);
        }

        // Audit 1 — the approval itself
        logAudit($pdo, $_SESSION['user_id'], 'approve', [
            'activity_type' => 'status_change',
            'entity_type'   => 'employee_lifecycle',
            'entity_id'     => $event_id,
            'description'   => "Approved {$ev['event_type']} \"{$ev['title']}\" for $emp_name" . ($defer ? " (effect deferred to {$ev['end_date']})" : ''),
            'old_values'    => ['status' => 'pending'],
            'new_values'    => ['status' => 'approved'],
        ]);
        // Audit 2 — the employee-row change, in the exact shape the legacy
        // endpoint writes, so downstream audit consumers see no difference
        if ($effect['changed']) {
            logAudit($pdo, $_SESSION['user_id'], 'update_status', [
                'activity_type' => 'status_change',
                'entity_type'   => 'employee',
                'entity_id'     => (int)$ev['employee_id'],
                'description'   => "Applied {$ev['event_type']} to $emp_name (event #$event_id)",
                'old_values'    => $effect['old'],
                'new_values'    => $effect['new'],
            ]);
        }
        logActivity($pdo, $_SESSION['user_id'], 'Approve HR action',
            "approved {$ev['event_type']} \"{$ev['title']}\" for \"$emp_name\"" . ($defer ? ' (takes effect ' . $ev['end_date'] . ')' : ''));

        // Tier 4 D28(c) — an approved resignation/termination auto-spawns an
        // offboarding checklist if a default template is configured. Guarded +
        // non-fatal (a checklist problem must never fail the approval).
        if (in_array($ev['event_type'], ['resignation', 'termination'], true) && @is_file(__DIR__ . '/../core/checklists.php')) {
            try {
                require_once __DIR__ . '/../core/checklists.php';
                if (function_exists('spawnChecklistIfConfigured')) {
                    spawnChecklistIfConfigured($pdo, (int)$ev['employee_id'], 'offboarding', (int)$_SESSION['user_id']);
                }
            } catch (Throwable $e) { error_log('offboarding auto-spawn: ' . $e->getMessage()); }
        }

        $message = ucfirst($ev['event_type']) . ' approved' . ($defer ? ' — takes effect on ' . $ev['end_date'] : ' and applied');

    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE employee_lifecycle_events
                       SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ?, updated_by = ?
                       WHERE event_id = ?")
            ->execute([$_SESSION['user_id'], $reject_reason, $_SESSION['user_id'], $event_id]);

        logAudit($pdo, $_SESSION['user_id'], 'reject', [
            'activity_type' => 'status_change',
            'entity_type'   => 'employee_lifecycle',
            'entity_id'     => $event_id,
            'description'   => "Rejected {$ev['event_type']} \"{$ev['title']}\" for $emp_name: $reject_reason",
            'old_values'    => ['status' => 'pending'],
            'new_values'    => ['status' => 'rejected', 'reject_reason' => $reject_reason],
        ]);
        logActivity($pdo, $_SESSION['user_id'], 'Reject HR action',
            "rejected {$ev['event_type']} \"{$ev['title']}\" for \"$emp_name\": $reject_reason");

        $message = ucfirst($ev['event_type']) . ' rejected';

    } else { // cancel
        $pdo->prepare("UPDATE employee_lifecycle_events
                       SET status = 'cancelled', updated_by = ?
                       WHERE event_id = ?")
            ->execute([$_SESSION['user_id'], $event_id]);

        logAudit($pdo, $_SESSION['user_id'], 'cancel', [
            'activity_type' => 'status_change',
            'entity_type'   => 'employee_lifecycle',
            'entity_id'     => $event_id,
            'description'   => "Cancelled {$ev['event_type']} \"{$ev['title']}\" for $emp_name",
            'old_values'    => ['status' => 'pending'],
            'new_values'    => ['status' => 'cancelled'],
        ]);
        logActivity($pdo, $_SESSION['user_id'], 'Cancel HR action',
            "cancelled {$ev['event_type']} \"{$ev['title']}\" for \"$emp_name\"");

        $message = ucfirst($ev['event_type']) . ' cancelled';
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
