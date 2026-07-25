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
        // Covers both attendee identity types (migration 2026_07_25_meeting_attendees_user_id):
        // in-person attendees carry employee_id (attendance-markable), Zoom attendees
        // carry user_id. linked_user_id resolves either identity to a BMS user_id so
        // both attendance display and the join-info visibility check below can work
        // uniformly regardless of which identity a given meeting's attendees use.
        $at = $pdo->prepare("
            SELECT ma.employee_id, ma.user_id, ma.attended, ma.joined_at,
                   COALESCE(e.first_name, u.first_name) AS first_name,
                   COALESCE(e.last_name, u.last_name) AS last_name,
                   COALESCE(ma.user_id, ue.user_id) AS linked_user_id
            FROM meeting_attendees ma
            LEFT JOIN employees e ON e.employee_id = ma.employee_id
            LEFT JOIN users u ON u.user_id = ma.user_id
            LEFT JOIN users ue ON ue.employee_id = ma.employee_id
            WHERE ma.meeting_id = ?
            ORDER BY first_name, last_name
        ");
        $at->execute([$meeting_id]);
        $attendees = $at->fetchAll(PDO::FETCH_ASSOC);

        // Join info (link/password/start URL) is only ever meaningful to the host, an
        // invited attendee, or someone who can already fully edit this meeting (this
        // same endpoint feeds the Edit form's password pre-fill at meetings.php -- an
        // editor who can already reschedule/cancel/regenerate this meeting gains
        // nothing from having the current password hidden, it just breaks their Edit
        // form). Everyone else with plain Meetings view access gets it nulled out
        // server-side (not just hidden in the UI, which a network tab would still
        // leak). Mirrors the isHost gating already applied client-side to the Start
        // URL, but enforced where it actually matters. Admins bypass this too, same
        // as the canView() check just above.
        $viewerUid = (int)($_SESSION['user_id'] ?? 0);
        $isHost = ((int)$m['host_user_id'] === $viewerUid) || ((int)$m['created_by'] === $viewerUid);
        $isAttendee = false;
        foreach ($attendees as $a) { if ((int)($a['linked_user_id'] ?? 0) === $viewerUid) { $isAttendee = true; break; } }
        if (!$isHost && !$isAttendee && !isAdmin() && !canEdit('meetings')) {
            $m['zoom_join_url'] = null;
            $m['zoom_password'] = null;
            $m['zoom_start_url'] = null;
        }

        echo json_encode(['success' => true, 'data' => $m, 'attendees' => $attendees]);
        exit;
    }

    $status = trim($_GET['status'] ?? '');
    $where = ["m.status != 'deleted'"]; $params = [];
    if ($status !== '') { $where[] = "m.status = ?"; $params[] = $status; }

    $stmt = $pdo->prepare("
        SELECT m.meeting_id, m.title, m.meeting_date, m.start_time, m.end_time, m.venue, m.status,
               m.meeting_type, m.zoom_sync_status,
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
