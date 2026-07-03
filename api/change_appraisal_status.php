<?php
// API: Change appraisal status (Tier 3, Phase 3.3 — §11.1, D17, D18).
// Transitions: draft → submitted (submit) | submitted → approved (approve) /
// rejected (reject). Approved/rejected are terminal. The appraiser cannot
// approve their own appraisal (segregation of duties; admins exempt). On
// approval, overall_rating = AVG(actual_rating) is computed + stored (D17).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$appraisal_id  = intval($_POST['appraisal_id'] ?? 0);
$action        = trim($_POST['action'] ?? '');
$reject_reason = trim($_POST['reject_reason'] ?? '');

if (!$appraisal_id) { echo json_encode(['success' => false, 'message' => 'Appraisal ID is required']); exit; }
if (!in_array($action, ['submit', 'approve', 'reject'], true)) { echo json_encode(['success' => false, 'message' => 'Invalid action']); exit; }
if ($action === 'reject' && $reject_reason === '') { echo json_encode(['success' => false, 'message' => 'A reason is required to reject']); exit; }

if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_appraisals', 'appraisal_id', $appraisal_id);
}

// Permission gate per verb (§11.1)
$verbPerm = ['submit' => 'canSubmit', 'approve' => 'canApprove', 'reject' => 'canReject'];
$fn = $verbPerm[$action];
if (!$fn('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to ' . $action . ' appraisals']);
    exit;
}

$transitions = [
    'draft'     => ['submit'  => 'submitted'],
    'submitted' => ['approve' => 'approved', 'reject' => 'rejected'],
];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM employee_appraisals WHERE appraisal_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$appraisal_id]);
    $ap = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ap) throw new Exception('Appraisal not found');

    $cur = $ap['status'];
    if (!isset($transitions[$cur][$action])) throw new Exception("Cannot $action an appraisal that is '$cur'");
    $new = $transitions[$cur][$action];

    // Segregation of duties — cannot approve your own appraisal (admins exempt)
    if ($action === 'approve' && (int)$ap['created_by'] === (int)$_SESSION['user_id'] && !isAdmin()) {
        throw new Exception('You cannot approve an appraisal you created yourself');
    }

    $emp = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
    $emp->execute([(int)$ap['employee_id']]);
    $er = $emp->fetch(PDO::FETCH_ASSOC);
    $emp_name = trim(($er['first_name'] ?? '') . ' ' . ($er['last_name'] ?? '')) ?: ('employee #' . $ap['employee_id']);

    if ($action === 'submit') {
        $pdo->prepare("UPDATE employee_appraisals SET status='submitted', updated_by=? WHERE appraisal_id=?")
            ->execute([$_SESSION['user_id'], $appraisal_id]);
        $message = 'Appraisal submitted';
        $newVals = ['status' => 'submitted'];

    } elseif ($action === 'approve') {
        // D17 — overall = AVG(actual), stored
        $overall = $pdo->prepare("SELECT ROUND(AVG(actual_rating), 2) FROM employee_appraisal_items WHERE appraisal_id = ?");
        $overall->execute([$appraisal_id]);
        $avg = $overall->fetchColumn();
        $avg = $avg !== null ? (float)$avg : null;
        $pdo->prepare("UPDATE employee_appraisals SET status='approved', overall_rating=?, approved_by=?, approved_at=NOW(), updated_by=? WHERE appraisal_id=?")
            ->execute([$avg, $_SESSION['user_id'], $_SESSION['user_id'], $appraisal_id]);
        $message = 'Appraisal approved (overall ' . ($avg !== null ? number_format($avg, 2) : 'n/a') . '/5)';
        $newVals = ['status' => 'approved', 'overall_rating' => $avg];

    } else { // reject
        $pdo->prepare("UPDATE employee_appraisals SET status='rejected', reject_reason=?, approved_by=?, approved_at=NOW(), updated_by=? WHERE appraisal_id=?")
            ->execute([$reject_reason, $_SESSION['user_id'], $_SESSION['user_id'], $appraisal_id]);
        $message = 'Appraisal rejected';
        $newVals = ['status' => 'rejected', 'reject_reason' => $reject_reason];
    }

    logActivity($pdo, $_SESSION['user_id'], ucfirst($action) . ' appraisal', "$action appraisal #$appraisal_id for \"$emp_name\"");
    logAudit($pdo, $_SESSION['user_id'], $action, [
        'activity_type' => 'status_change',
        'entity_type'   => 'employee_appraisal',
        'entity_id'     => $appraisal_id,
        'description'   => ucfirst($action) . " appraisal for $emp_name",
        'old_values'    => ['status' => $cur],
        'new_values'    => $newVals,
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
