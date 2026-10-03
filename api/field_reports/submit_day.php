<?php
// POST date — "Submit today's report": stamps the caller's OWN day (re-submitting refreshes it).
require_once __DIR__ . '/_common.php';
frRequirePost();

if (!canCreate('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$date = trim((string)($_POST['date'] ?? date('Y-m-d')));
if (!frValidDate($date) || $date > date('Y-m-d')) frJson(['success' => false, 'message' => t('Choose a valid date.')], 422);

$me = (int)$_SESSION['user_id'];
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM field_visits WHERE user_id = ? AND visit_date = ? AND status = 'active'");
    $c->execute([$me, $date]);
    $count = (int)$c->fetchColumn();
    if ($count === 0) frJson(['success' => false, 'message' => t('Add at least one visit before submitting the report.')], 422);

    $pdo->prepare("INSERT INTO field_report_days (user_id, report_date, submitted_at, visit_count, changed_after_submit, last_changed_at)
                   VALUES (?, ?, NOW(), ?, 0, NULL)
                   ON DUPLICATE KEY UPDATE submitted_at = NOW(), visit_count = VALUES(visit_count), changed_after_submit = 0, last_changed_at = NULL")
        ->execute([$me, $date, $count]);
    logActivity($pdo, $me, 'Submit field report', "Submitted field report for $date ($count visits)");
    $d = frDayStatus($pdo, $me, $date);
    frJson(['success' => true, 'message' => t('Report submitted.'), 'visit_count' => $count,
            'submitted_time' => date('H:i', strtotime($d['submitted_at']))]);
} catch (PDOException $e) {
    error_log('field_reports/submit_day: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
