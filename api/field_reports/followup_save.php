<?php
// POST — a visit's follow-up from the View details page (customer_visit_followups_plan.md).
// Owner or admin only, like every other change to a visit.
//   action=add         visit_id, due_date, purpose (optional) — only when none is open (D2)
//   action=reschedule  follow_up_id, due_date, purpose (optional) — an open one
//   action=cancel      follow_up_id — an open one
require_once __DIR__ . '/_common.php';
frRequirePost();

if (!canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['add', 'reschedule', 'cancel'], true)) frJson(['success' => false, 'message' => t('Invalid request')], 400);

$fu = null;
if ($action === 'add') {
    $id = (int)($_POST['visit_id'] ?? 0);
} else {
    $fu = frFuGet($pdo, (int)($_POST['follow_up_id'] ?? 0));
    if (!$fu) frJson(['success' => false, 'message' => t('Follow-up not found')], 404);
    $id = (int)$fu['visit_id'];
}
$visit = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$visit) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($visit)) frJson(['success' => false, 'message' => t('Permission denied')], 403);

$me = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$due = trim((string)($_POST['due_date'] ?? ''));
$purpose = frFuCleanPurpose($_POST['purpose'] ?? '');
if ($action !== 'cancel') {
    $err = frFuDueError($due, max($today, $visit['visit_date']), t('The follow-up date cannot be in the past.'), $today);
    if ($err) frJson(['success' => false, 'message' => $err, 'errors' => ['due_date' => $err]], 422);
}

try {
    $pdo->beginTransaction();
    frFuLockVisit($pdo, $id);
    if ($action === 'add') {
        if (frFuPending($pdo, $id)) {
            $pdo->rollBack();
            frJson(['success' => false, 'message' => t('This visit already has an open follow-up. Change its date instead.')], 422);
        }
        $newId = frFuAdd($pdo, $id, $due, $purpose, $me);
        $msg = t('Follow-up set.'); $log = "Set follow-up #$newId on $due";
    } else {
        $cur = frFuGet($pdo, (int)$fu['follow_up_id']);   // re-read under the lock
        if (!$cur || $cur['status'] !== 'pending') {
            $pdo->rollBack();
            frJson(['success' => false, 'message' => t('This follow-up is no longer open.')], 422);
        }
        if ($action === 'reschedule') {
            frFuReschedule($pdo, (int)$cur['follow_up_id'], $due, $purpose, true, $me);
            $msg = t('Follow-up date changed.'); $log = "Moved follow-up #{$cur['follow_up_id']} from {$cur['due_date']} to $due";
        } else {
            frFuCancel($pdo, (int)$cur['follow_up_id'], $me);
            $msg = t('Follow-up cancelled.'); $log = "Cancelled follow-up #{$cur['follow_up_id']} (due {$cur['due_date']})";
        }
    }
    frSyncVisitFollowUp($pdo, $id);
    $pdo->commit();
    logActivity($pdo, $me, 'Follow up field visit', "$log — field visit #$id ({$visit['client_name']})");
    frJson(['success' => true, 'message' => $msg]);
} catch (PDOException | RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('field_reports/followup_save: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
