<?php
// GET date_from, date_to (or date), user_id (admins only — ignored for everyone else)
require_once __DIR__ . '/_common.php';

[$from, $to] = frRequestRange($_GET);
$userId = frScopeUserId($_GET['user_id'] ?? null);   // non-admin => always self
$me = (int)$_SESSION['user_id'];

try {
    $rows = frFetchVisits($pdo, $from, $to, $userId);
    $out = [
        'success' => true,
        'date_from' => $from,
        'date_to' => $to,
        'scope_user_id' => $userId,
        'stats' => frStats($rows),
        'rows' => array_map('frRowOut', $rows),
        'day_status' => null,
        'staff_summary' => [],
    ];
    // "Submitted" banner: one day, own report.
    if ($from === $to && ($userId === null ? false : $userId === $me)) {
        $d = frDayStatus($pdo, $me, $from);
        $out['day_status'] = $d ? [
            'submitted_at' => $d['submitted_at'],
            'submitted_time' => date('H:i', strtotime($d['submitted_at'])),
            'visit_count' => (int)$d['visit_count'],
            'changed_after_submit' => (int)$d['changed_after_submit'],
        ] : null;
    }
    if (isAdmin() && $userId === null) $out['staff_summary'] = frStaffSummary($rows);
    echo json_encode($out);
} catch (PDOException $e) {
    error_log('field_reports/list: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
