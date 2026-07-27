<?php
/**
 * api/join_meeting.php — click-through redirect to a Zoom meeting's join_url.
 * A plain GET link (not AJAX): only an invited attendee or the host may use
 * it. An invited attendee gets meeting_attendees.attended=1/joined_at=NOW()
 * stamped on first use (approximate "who joined" signal, separate from the
 * manual in-person attendance checkbox) before being redirected to Zoom.
 * The host is redirected straight through — nothing to stamp, they're not an
 * invited attendee row.
 *
 * Test seam: if $GLOBALS['JOIN_REDIRECT_MOCK'] is a callable, _joinRedirect()
 * calls it instead of header()+exit. Needed because PHP's CLI SAPI never
 * records header() calls (headers_list() stays empty there), so a CLI test
 * has no other way to observe which URL this script chose. Used only by
 * tests/test_join_meeting_cli.php; mirrors zoom_service.php's ZOOM_HTTP_MOCK seam.
 */
require_once __DIR__ . '/../roots.php';

if (!function_exists('_joinRedirect')) {
    function _joinRedirect(string $url): void {
        if (isset($GLOBALS['JOIN_REDIRECT_MOCK']) && is_callable($GLOBALS['JOIN_REDIRECT_MOCK'])) {
            ($GLOBALS['JOIN_REDIRECT_MOCK'])($url);
            exit;
        }
        header('Location: ' . $url);
        exit;
    }
}

if (!isAuthenticated()) { _joinRedirect(getUrl('login')); }
if (!canView('meetings')) { _joinRedirect(getUrl('unauthorized')); }

$meetingId = intval($_GET['meeting_id'] ?? 0);
$uid = (int)($_SESSION['user_id'] ?? 0);

if (!$meetingId) { _joinRedirect(getUrl('meetings')); }

$stmt = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id = ? AND status != 'deleted'");
$stmt->execute([$meetingId]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$m || $m['meeting_type'] !== 'zoom' || empty($m['zoom_join_url']) || $m['status'] === 'cancelled') {
    _joinRedirect(getUrl('meetings') . '?meeting_id=' . $meetingId);
}

// isAdmin()/canEdit() bypasses mirror get_meetings.php's identical bypasses on the
// same join info -- without them, someone who CAN see a Join button there (admin,
// or anyone with edit rights on Meetings) would get refused here, an inconsistent
// dead end.
$isHost = ((int)$m['host_user_id'] === $uid) || ((int)$m['created_by'] === $uid) || isAdmin() || canEdit('meetings');

$attStmt = $pdo->prepare("
    SELECT ma.employee_id, ma.user_id FROM meeting_attendees ma
    LEFT JOIN users ue ON ue.employee_id = ma.employee_id
    WHERE ma.meeting_id = ? AND (ma.user_id = ? OR ue.user_id = ?)
    LIMIT 1
");
$attStmt->execute([$meetingId, $uid, $uid]);
$attRow = $attStmt->fetch(PDO::FETCH_ASSOC);

if (!$isHost && !$attRow) {
    _joinRedirect(getUrl('unauthorized'));
}

if ($attRow) {
    if ($attRow['user_id'] !== null) {
        $pdo->prepare("UPDATE meeting_attendees SET attended = 1, joined_at = NOW() WHERE meeting_id = ? AND user_id = ?")
            ->execute([$meetingId, (int)$attRow['user_id']]);
    } else {
        $pdo->prepare("UPDATE meeting_attendees SET attended = 1, joined_at = NOW() WHERE meeting_id = ? AND employee_id = ?")
            ->execute([$meetingId, (int)$attRow['employee_id']]);
    }
    logActivity($pdo, $uid, 'Joined Zoom meeting', "meeting #$meetingId");
}

_joinRedirect($m['zoom_join_url']);
