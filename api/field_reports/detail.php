<?php
// GET visit_id — everything the View details page shows (customer_visit_followups_plan.md D6):
// the visit (every field of its row + who recorded / changed it), the follow-up timeline
// of this customer and the customer's other visits — both limited to what the caller
// may see. Owner, or an admin once that day's report was submitted (frCanSee()).
require_once __DIR__ . '/_common.php';

$id = (int)($_GET['visit_id'] ?? 0);
$v = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$v) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanSee($pdo, $v)) frJson(['success' => false, 'message' => t('Permission denied')], 403);

try {
    $today = date('Y-m-d');
    $canEdit = canEdit('field_visits');
    $people = [];
    $ids = array_values(array_unique(array_filter(array_map('intval',
        [$v['user_id'], $v['created_by'], $v['updated_by'], $v['joined_marked_by']]))));
    if ($ids) {
        $s = $pdo->prepare("SELECT user_id, first_name, last_name, username FROM users WHERE user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $u) $people[(int)$u['user_id']] = frStaffName($u);
    }
    $who = fn($uid) => $uid ? ($people[(int)$uid] ?? '') : '';

    $rows = [$v + ['staff_name' => $who($v['user_id'])]];
    frAttachServices($pdo, $rows);
    $visit = frRowOut($rows[0]) + [
        'created_by_name'       => $who($v['created_by']),
        'created_at'            => $v['created_at'],
        'updated_by_name'       => $who($v['updated_by']),
        'updated_at'            => $v['updated_at'],
        'joined_marked_by_name' => $who($v['joined_marked_by']),
        'day_submitted'         => (bool)frDayStatus($pdo, (int)$v['user_id'], $v['visit_date']),
        'is_mine'               => (int)$v['user_id'] === (int)$_SESSION['user_id'],
    ];

    // The customer = every visit to the same phone the caller may see (this one always).
    $custVisits = frCustomerVisits($pdo, $v);
    $byId = [];
    foreach ($custVisits as $cv) $byId[(int)$cv['visit_id']] = $cv;
    $followUps = array_map(fn($f) => frFollowUpOut($f, $today, $canEdit && isset($byId[(int)$f['visit_id']]) && frCanTouch($byId[(int)$f['visit_id']])),
        frFollowUpsForVisits($pdo, array_keys($byId)));
    // Re-open is offered only where followup_done.php allows it: the visit's last finished
    // follow-up, and only while that visit has none open.
    $lastDone = []; $hasPending = [];
    foreach ($followUps as $f) {
        if ($f['status'] === 'pending') $hasPending[$f['visit_id']] = true;
        if ($f['status'] === 'done') {
            $cur = $lastDone[$f['visit_id']] ?? null;
            if (!$cur || [$f['done_at'], $f['follow_up_id']] > [$cur['done_at'], $cur['follow_up_id']]) $lastDone[$f['visit_id']] = $f;
        }
    }
    foreach ($followUps as &$f) {
        $f['can_reopen'] = $f['can_reopen'] && empty($hasPending[$f['visit_id']])
            && ($lastDone[$f['visit_id']]['follow_up_id'] ?? 0) === $f['follow_up_id'];
    }
    unset($f);

    $pending = frFuPending($pdo, $id);
    $interest = frInterestLabels();
    echo json_encode([
        'success'    => true,
        'visit'      => $visit,
        'pending'    => $pending ? frFollowUpOut($pending + ['visit_date' => $v['visit_date']], $today, $visit['can_edit']) : null,
        'follow_ups' => $followUps,
        'visits'     => array_map(fn($cv) => [
            'visit_id'       => (int)$cv['visit_id'],
            'visit_date'     => $cv['visit_date'],
            'visit_time'     => $cv['visit_time'] ? substr($cv['visit_time'], 0, 5) : '',
            'staff_name'     => $cv['staff_name'],
            'location'       => $cv['location'],
            'interest'       => $cv['interest'] ?? '',
            'interest_label' => $cv['interest'] ? t($interest[$cv['interest']] ?? '') : '',
            'joined'         => (int)$cv['joined'],
            'notes'          => $cv['notes'] ?? '',
            'follow_up_date' => $cv['follow_up_date'],
            'follow_up_done' => !empty($cv['follow_up_done_at']) ? 1 : 0,
            'is_current'     => (int)$cv['visit_id'] === $id,
        ], $custVisits),
    ]);
} catch (PDOException $e) {
    error_log('field_reports/detail: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
