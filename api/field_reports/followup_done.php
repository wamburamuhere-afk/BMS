<?php
// POST visit_id, done (1|0) — mark a visit's follow-up as done (or not). Owner or admin
// only, like every other change to a visit (customer_visits_ux_plan 3.1).
require_once __DIR__ . '/_common.php';
frRequirePost();

if (!canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$id = (int)($_POST['visit_id'] ?? 0);
$visit = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$visit) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($visit)) frJson(['success' => false, 'message' => t('Permission denied')], 403);
if (empty($visit['follow_up_date'])) frJson(['success' => false, 'message' => t('This visit has no follow-up date.')], 422);

$done = (string)($_POST['done'] ?? '1') === '1';
$me = (int)$_SESSION['user_id'];
try {
    $pdo->prepare("UPDATE field_visits SET follow_up_done_at = ?, follow_up_done_by = ?, updated_by = ? WHERE visit_id = ?")
        ->execute([$done ? date('Y-m-d H:i:s') : null, $done ? $me : null, $me, $id]);
    logActivity($pdo, $me, 'Follow up field visit', ($done ? 'Followed up' : 'Re-opened follow-up for') . " field visit #$id ({$visit['client_name']})");
    frJson(['success' => true, 'message' => $done ? t('Marked as followed up.') : t('Follow-up re-opened.')]);
} catch (PDOException $e) {
    error_log('field_reports/followup_done: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
