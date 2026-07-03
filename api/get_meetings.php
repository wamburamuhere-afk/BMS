<?php
// API: List meetings (+ stats) or a single meeting with attendees (Tier 4, Phase 4.3).
// scope-audit: skip — meetings are company-wide by design (D29; no project_id);
// the employees join only resolves attendee display names, which are not
// project-confidential. Access is gated by canView('meetings').
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('meetings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $meeting_id = intval($_GET['meeting_id'] ?? 0);
    if ($meeting_id) {
        $stmt = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id=? AND status!='deleted'");
        $stmt->execute([$meeting_id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) { echo json_encode(['success' => false, 'message' => 'Meeting not found']); exit; }
        $at = $pdo->prepare("SELECT ma.employee_id, ma.attended, e.first_name, e.last_name FROM meeting_attendees ma JOIN employees e ON e.employee_id = ma.employee_id WHERE ma.meeting_id = ? ORDER BY e.first_name, e.last_name");
        $at->execute([$meeting_id]);
        echo json_encode(['success' => true, 'data' => $m, 'attendees' => $at->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $status = trim($_GET['status'] ?? '');
    $where = ["m.status != 'deleted'"]; $params = [];
    if ($status !== '') { $where[] = "m.status = ?"; $params[] = $status; }

    $stmt = $pdo->prepare("
        SELECT m.meeting_id, m.title, m.meeting_date, m.start_time, m.end_time, m.venue, m.status,
               (SELECT COUNT(*) FROM meeting_attendees ma WHERE ma.meeting_id = m.meeting_id) AS attendee_count
        FROM meetings m
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.meeting_date DESC, m.meeting_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = date('Y-m-d');
    $weekEnd = date('Y-m-d', strtotime('+7 days'));
    $monthStart = date('Y-m-01');
    $stats = ['upcoming' => 0, 'this_week' => 0, 'completed_month' => 0];
    foreach ($rows as $r) {
        if ($r['status'] === 'scheduled' && $r['meeting_date'] >= $today) {
            $stats['upcoming']++;
            if ($r['meeting_date'] <= $weekEnd) $stats['this_week']++;
        }
        if ($r['status'] === 'completed' && $r['meeting_date'] >= $monthStart) $stats['completed_month']++;
    }
    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);

} catch (Exception $e) {
    error_log("get_meetings error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
