<?php
// POST — mark a follow-up done, optionally setting the next one in the same step
// (customer_visit_followups_plan.md D2), or re-open it (done=0). Owner or admin only,
// like every other change to a visit.
//   follow_up_id  the follow-up (or visit_id: that visit's open one — the older call)
//   done          1 (default) = done, 0 = re-open the last done one
//   method        visit|call|whatsapp|sms|other (optional) · outcome  what happened (optional)
//   next_date     YYYY-MM-DD (optional) · next_purpose (optional)
require_once __DIR__ . '/_common.php';
frRequirePost();

if (!canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$fuId = (int)($_POST['follow_up_id'] ?? 0);
$fu = $fuId > 0 ? frFuGet($pdo, $fuId) : null;
if ($fuId > 0 && !$fu) frJson(['success' => false, 'message' => t('Follow-up not found')], 404);
$id = $fu ? (int)$fu['visit_id'] : (int)($_POST['visit_id'] ?? 0);
$visit = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$visit) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($visit)) frJson(['success' => false, 'message' => t('Permission denied')], 403);

$done = (string)($_POST['done'] ?? '1') === '1';
$me = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

$errors = [];
$res = frFuValidateOutcome($_POST['method'] ?? '', $_POST['outcome'] ?? '');
if (is_string($res)) $errors['outcome'] = $res; else [$method, $outcome] = $res;
$next = trim((string)($_POST['next_date'] ?? ''));
if ($done && $next !== '' && ($e = frFuDueError($next, $today, t('The next follow-up cannot be in the past.'), $today))) $errors['next_date'] = $e;
if ($errors) frJson(['success' => false, 'message' => reset($errors), 'errors' => $errors], 422);

try {
    $pdo->beginTransaction();
    frFuLockVisit($pdo, $id);
    if ($done) {
        $target = $fu ? frFuGet($pdo, (int)$fu['follow_up_id']) : frFuPending($pdo, $id);   // re-read under the lock
        if (!$target) {
            $pdo->rollBack();
            $any = $pdo->prepare("SELECT 1 FROM field_visit_follow_ups WHERE visit_id = ? LIMIT 1");
            $any->execute([$id]);
            frJson(['success' => false, 'message' => $any->fetchColumn() ? t('This follow-up is already done.') : t('This visit has no follow-up date.')], 422);
        }
        if ($target['status'] !== 'pending') {
            $pdo->rollBack();
            frJson(['success' => false, 'message' => t('This follow-up is already done.')], 422);
        }
        frFuComplete($pdo, (int)$target['follow_up_id'], $method, $outcome, $me);
        $nextId = $next !== '' ? frFuAdd($pdo, $id, $next, frFuCleanPurpose($_POST['next_purpose'] ?? ''), $me) : null;
        frSyncVisitFollowUp($pdo, $id);
        $pdo->commit();
        logActivity($pdo, $me, 'Follow up field visit', "Followed up field visit #$id ({$visit['client_name']}), follow-up #{$target['follow_up_id']}"
            . ($method ? " by $method" : '') . ($nextId ? " — next on $next" : ''));
        frJson(['success' => true, 'follow_up_id' => (int)$target['follow_up_id'], 'next_follow_up_id' => $nextId,
                'message' => $nextId ? t('Follow-up done. The next one is set.') : t('Marked as followed up.')]);
    }

    // Re-open: the given done follow-up, or the visit's last done one — never while one is open.
    if (frFuPending($pdo, $id)) {
        $pdo->rollBack();
        frJson(['success' => false, 'message' => t('This visit already has an open follow-up.')], 422);
    }
    // Only the visit's last finished follow-up — re-opening an older one would put it
    // "open" ahead of the history that came after it.
    $target = frFuLatestDone($pdo, $id);
    if (!$target) {
        $pdo->rollBack();
        frJson(['success' => false, 'message' => t('There is no finished follow-up to re-open.')], 422);
    }
    if ($fu && (int)$fu['follow_up_id'] !== (int)$target['follow_up_id']) {
        $pdo->rollBack();
        frJson(['success' => false, 'message' => t('Only the last finished follow-up can be re-opened.')], 422);
    }
    frFuReopen($pdo, (int)$target['follow_up_id'], $me);
    frSyncVisitFollowUp($pdo, $id);
    $pdo->commit();
    logActivity($pdo, $me, 'Follow up field visit', "Re-opened follow-up #{$target['follow_up_id']} for field visit #$id ({$visit['client_name']})");
    frJson(['success' => true, 'message' => t('Follow-up re-opened.')]);
} catch (PDOException | RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('field_reports/followup_done: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
