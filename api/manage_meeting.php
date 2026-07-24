<?php
// API: Manage meetings (Tier 4, Phase 4.3 — D29).
// Minimal: schedule + attendees + minutes + status. add / update / attendees /
// mark_attendance / complete / cancel / delete. Notifies attendees' linked
// users on schedule/cancel via the notification engine (deduped).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/notify.php';
require_once __DIR__ . '/../core/zoom_service.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
$need = ($action === 'add') ? 'create' : (($action === 'delete') ? 'delete' : 'edit');
$ok = $need === 'create' ? canCreate('meetings') : ($need === 'delete' ? canDelete('meetings') : canEdit('meetings'));
if (!$ok) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

// Notify an attendee-set's linked users of a meeting event. $joinUrl (Zoom
// meetings only) is included in the message on schedule/reschedule, per plan
// Phase 6. Deliberately targets the meeting's actual attendees directly
// (not dispatchEvent()'s permission-based broadcast, which would resolve to
// "everyone who can view meetings" and leak the join link to non-attendees)
// while still respecting the same per-user mute preferences dispatchEvent()
// would have applied, via the now-registered 'hr_meeting' notification_events row.
function notifyMeetingAttendees(PDO $pdo, int $meeting_id, string $title, string $verb, ?string $joinUrl = null): void {
    $rows = $pdo->prepare("SELECT u.user_id, u.notification_preferences FROM meeting_attendees ma JOIN users u ON u.employee_id = ma.employee_id WHERE ma.meeting_id = ? AND u.is_active = 1");
    $rows->execute([$meeting_id]);
    $message = $joinUrl ? ('You are listed as an attendee. Join: ' . $joinUrl) : 'You are listed as an attendee.';
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id'];
        if (function_exists('notifUserMuted') && notifUserMuted($r['notification_preferences'] ?? null, 'hr_meeting', 'Human Resources')) continue;
        if (!function_exists('notifClaimDedupe') || !notifClaimDedupe($pdo, "meeting_$verb|$meeting_id|u$uid")) continue;
        if (function_exists('createNotification')) createNotification($pdo, $uid, [
            'title' => 'Meeting ' . $verb . ': ' . $title, 'message' => $message,
            'type' => 'system', 'event_key' => 'hr_meeting', 'category' => 'Human Resources', 'priority' => 'medium',
            'action_url' => function_exists('getUrl') ? getUrl('meetings') : '/meetings',
        ]);
    }
}

// Convert a Tanzania-local meeting_date/start_time/end_time into the ISO8601 UTC
// start_time + duration (minutes) Zoom's API expects. Africa/Dar_es_Salaam has no
// DST (fixed UTC+3), so this conversion never shifts across the year.
function zoomComputeSchedule(string $date, ?string $start, ?string $end): array {
    $tz = new DateTimeZone('Africa/Dar_es_Salaam');
    $startDt = new DateTime($date . ' ' . ($start ?: '00:00:00'), $tz);
    $duration = 60;
    if ($end) {
        $endDt = new DateTime($date . ' ' . $end, $tz);
        $diff = ($endDt->getTimestamp() - $startDt->getTimestamp()) / 60;
        if ($diff > 0) $duration = (int)round($diff);
    }
    $startDt->setTimezone(new DateTimeZone('UTC'));
    return ['start_time' => $startDt->format('Y-m-d\TH:i:s\Z'), 'duration' => $duration];
}

// Look up the Zoom host's email from the BMS user selected as host (plan 0.2 —
// Zoom "host" = that user's email; must be a real user in the connected Zoom account).
function zoomResolveHostEmail(PDO $pdo, int $hostUserId): ?string {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$hostUserId]);
    $email = $stmt->fetchColumn();
    return $email !== false && $email !== '' ? $email : null;
}

// Create/update the Zoom-side meeting for a row already saved locally, then persist
// the result (join/start URL, password, sync status) back onto that row. A Zoom
// failure never re-throws — it's recorded as zoom_sync_status='failed' so the local
// save always stands (plan 4.3: graceful degradation, never silent).
function zoomSyncMeetingRow(PDO $pdo, int $meetingId, array $row, array $formData): array {
    $hostEmail = zoomResolveHostEmail($pdo, (int)$formData['host_user_id']);
    if ($hostEmail === null) {
        $pdo->prepare("UPDATE meetings SET zoom_sync_status='failed' WHERE meeting_id=?")->execute([$meetingId]);
        return ['ok' => false, 'message' => 'Zoom host must be an active BMS user with an email address.'];
    }

    $sched = zoomComputeSchedule($formData['meeting_date'], $formData['start_time'] ?: null, $formData['end_time'] ?: null);
    $data = [
        'topic' => $formData['title'], 'agenda' => $formData['agenda'], 'host_email' => $hostEmail,
        'start_time' => $sched['start_time'], 'duration' => $sched['duration'],
        'host_video' => !empty($formData['zoom_host_video']), 'participant_video' => !empty($formData['zoom_participant_video']),
        'waiting_room' => !empty($formData['zoom_waiting_room']), 'auto_recording' => !empty($formData['zoom_auto_recording']),
    ];

    $existingZoomId = $row['zoom_meeting_id'] ?? null;
    $res = $existingZoomId ? zoomUpdateMeeting($existingZoomId, $data) : zoomCreateMeeting($data);

    if (!$res['success']) {
        $pdo->prepare("UPDATE meetings SET zoom_sync_status='failed' WHERE meeting_id=?")->execute([$meetingId]);
        return ['ok' => false, 'message' => $res['message']];
    }

    if ($existingZoomId) {
        // Zoom's Update Meeting response has no body — keep the existing join/start/password.
        $pdo->prepare("UPDATE meetings SET zoom_sync_status='synced' WHERE meeting_id=?")->execute([$meetingId]);
    } else {
        $pdo->prepare("UPDATE meetings SET zoom_meeting_id=?, zoom_join_url=?, zoom_start_url=?, zoom_password=?, zoom_sync_status='synced' WHERE meeting_id=?")
            ->execute([$res['data']['zoom_meeting_id'], $res['data']['join_url'], $res['data']['start_url'], $res['data']['password'], $meetingId]);
    }
    return ['ok' => true, 'message' => 'Zoom meeting synced.'];
}

// Delete the Zoom-side meeting (cancel, or switching a meeting from zoom -> in_person).
function zoomSyncDeleteRow(PDO $pdo, int $meetingId, string $zoomMeetingId): array {
    $res = zoomDeleteMeeting($zoomMeetingId);
    if (!$res['success']) {
        $pdo->prepare("UPDATE meetings SET zoom_sync_status='failed' WHERE meeting_id=?")->execute([$meetingId]);
        return ['ok' => false, 'message' => $res['message']];
    }
    $pdo->prepare("UPDATE meetings SET zoom_sync_status='synced' WHERE meeting_id=?")->execute([$meetingId]);
    return ['ok' => true, 'message' => 'Zoom meeting removed.'];
}

try {
    switch ($action) {
        case 'add':
        case 'update': {
            $id = intval($_POST['meeting_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $agenda = trim($_POST['agenda'] ?? '');
            $date = trim($_POST['meeting_date'] ?? '');
            $start = trim($_POST['start_time'] ?? '');
            $endt = trim($_POST['end_time'] ?? '');
            $venue = trim($_POST['venue'] ?? '');
            $attendees = $_POST['attendees'] ?? [];
            $meetingType = ($_POST['meeting_type'] ?? 'in_person') === 'zoom' ? 'zoom' : 'in_person';
            $hostUserId = intval($_POST['host_user_id'] ?? 0);
            $zoomHostVideo = !empty($_POST['zoom_host_video']) ? 1 : 0;
            $zoomParticipantVideo = !empty($_POST['zoom_participant_video']) ? 1 : 0;
            $zoomWaitingRoom = isset($_POST['zoom_waiting_room']) ? (!empty($_POST['zoom_waiting_room']) ? 1 : 0) : 1;
            $zoomAutoRecording = !empty($_POST['zoom_auto_recording']) ? 1 : 0;
            if ($title === '') throw new Exception('Title is required');
            if (!strtotime($date)) throw new Exception('A valid meeting date is required');
            if (!is_array($attendees)) $attendees = [];
            if ($meetingType === 'zoom') {
                if (!zoomConfigured()) throw new Exception('Zoom is not enabled. Ask an administrator to configure it in Zoom Integration settings.');
                if (!$hostUserId) throw new Exception('Select a meeting host for a Zoom meeting.');
            }
            $zoomSyncStatus = $meetingType === 'zoom' ? 'pending' : null;

            $oldRow = ['meeting_type' => 'in_person', 'zoom_meeting_id' => null];
            if ($action === 'add') {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO meetings (title, agenda, meeting_date, start_time, end_time, venue, meeting_type, host_user_id, zoom_host_video, zoom_participant_video, zoom_waiting_room, zoom_auto_recording, zoom_sync_status, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)")
                    ->execute([$title, ($agenda!==''?$agenda:null), $date, ($start!==''?$start:null), ($endt!==''?$endt:null), ($venue!==''?$venue:null), $meetingType, ($hostUserId?:null), $zoomHostVideo, $zoomParticipantVideo, $zoomWaitingRoom, $zoomAutoRecording, $zoomSyncStatus, $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            } else {
                if (!$id) throw new Exception('Meeting id is required');
                $chk = $pdo->prepare("SELECT meeting_type, zoom_meeting_id FROM meetings WHERE meeting_id=? AND status!='deleted'");
                $chk->execute([$id]);
                $found = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$found) throw new Exception('Meeting not found');
                $oldRow = $found;
                $pdo->beginTransaction();
                // Switching away from Zoom keeps zoom_sync_status clean (not carried over as stale 'failed'/'synced').
                $pdo->prepare("UPDATE meetings SET title=?, agenda=?, meeting_date=?, start_time=?, end_time=?, venue=?, meeting_type=?, host_user_id=?, zoom_host_video=?, zoom_participant_video=?, zoom_waiting_room=?, zoom_auto_recording=?, zoom_sync_status=?, updated_by=? WHERE meeting_id=? AND status!='deleted'")
                    ->execute([$title, ($agenda!==''?$agenda:null), $date, ($start!==''?$start:null), ($endt!==''?$endt:null), ($venue!==''?$venue:null), $meetingType, ($hostUserId?:null), $zoomHostVideo, $zoomParticipantVideo, $zoomWaitingRoom, $zoomAutoRecording, $zoomSyncStatus, $_SESSION['user_id'], $id]);
                if ($meetingType !== 'zoom') {
                    $pdo->prepare("UPDATE meetings SET zoom_meeting_id=NULL, zoom_join_url=NULL, zoom_start_url=NULL, zoom_password=NULL WHERE meeting_id=?")->execute([$id]);
                }
                $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id=?")->execute([$id]);
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO meeting_attendees (meeting_id, employee_id) VALUES (?, ?)");
            foreach ($attendees as $eid) {
                $eid = (int)$eid; if (!$eid) continue;
                if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($eid);
                $chk2 = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id=? AND (status IS NULL OR status!='deleted')");
                $chk2->execute([$eid]);
                if ($chk2->fetch()) $ins->execute([$id, $eid]);
            }
            $pdo->commit();

            // Zoom-touching sync happens AFTER the local commit — a Zoom failure must
            // never roll back or block the local save (plan 4.3).
            $zoomMessage = '';
            if ($meetingType === 'zoom') {
                $sync = zoomSyncMeetingRow($pdo, $id, $oldRow, [
                    'title' => $title, 'agenda' => $agenda, 'meeting_date' => $date, 'start_time' => $start, 'end_time' => $endt,
                    'host_user_id' => $hostUserId, 'zoom_host_video' => $zoomHostVideo, 'zoom_participant_video' => $zoomParticipantVideo,
                    'zoom_waiting_room' => $zoomWaitingRoom, 'zoom_auto_recording' => $zoomAutoRecording,
                ]);
                if (!$sync['ok']) $zoomMessage = ' Zoom sync failed: ' . $sync['message'] . ' — use Retry from the meeting menu.';
                logActivity($pdo, $_SESSION['user_id'], 'Sync Zoom meeting', "meeting #$id: " . ($sync['ok'] ? 'synced' : 'failed — ' . $sync['message']));
                if (function_exists('logAudit')) logAudit($pdo, $_SESSION['user_id'], 'zoom_meeting_sync', [
                    'entity_type' => 'meeting', 'entity_id' => $id,
                    'description' => "Zoom sync on meeting #$id " . ($action==='add'?'create':'update') . ': ' . ($sync['ok'] ? 'ok' : $sync['message']),
                ]);
            } elseif ($oldRow['meeting_type'] === 'zoom' && !empty($oldRow['zoom_meeting_id'])) {
                // Switched from Zoom to in-person — remove the now-orphaned Zoom meeting so it never silently drifts.
                $del = zoomSyncDeleteRow($pdo, $id, $oldRow['zoom_meeting_id']);
                logActivity($pdo, $_SESSION['user_id'], 'Remove Zoom meeting (switched to in-person)', "meeting #$id: " . ($del['ok'] ? 'removed' : 'failed — ' . $del['message']));
            }

            $joinUrlForNotify = $meetingType === 'zoom'
                ? $pdo->query("SELECT zoom_join_url FROM meetings WHERE meeting_id=$id")->fetchColumn() ?: null
                : null;
            notifyMeetingAttendees($pdo, $id, $title, 'scheduled', $joinUrlForNotify);
            logActivity($pdo, $_SESSION['user_id'], ($action==='add'?'Add':'Update').' meeting', "meeting '$title' (#$id)");
            echo json_encode(['success' => true, 'message' => 'Meeting ' . ($action==='add'?'scheduled':'updated') . $zoomMessage, 'meeting_id' => $id]);
            break;
        }
        case 'mark_attendance': {
            $id = intval($_POST['meeting_id'] ?? 0);
            $present = $_POST['present'] ?? [];   // [employee_id => 1]
            if (!$id) throw new Exception('Meeting id is required');
            if (!is_array($present)) $present = [];
            $pdo->prepare("UPDATE meeting_attendees SET attended = 0 WHERE meeting_id = ?")->execute([$id]);
            $upd = $pdo->prepare("UPDATE meeting_attendees SET attended = 1 WHERE meeting_id = ? AND employee_id = ?");
            foreach ($present as $eid => $v) if ((int)$v) $upd->execute([$id, (int)$eid]);
            logActivity($pdo, $_SESSION['user_id'], 'Mark meeting attendance', "meeting #$id");
            echo json_encode(['success' => true, 'message' => 'Attendance saved']);
            break;
        }
        case 'complete': {
            $id = intval($_POST['meeting_id'] ?? 0);
            $minutes = trim($_POST['minutes'] ?? '');
            if (!$id) throw new Exception('Meeting id is required');
            $cur = $pdo->query("SELECT status FROM meetings WHERE meeting_id=$id AND status!='deleted'")->fetchColumn();
            if ($cur !== 'scheduled') throw new Exception('Only a scheduled meeting can be completed');
            $pdo->prepare("UPDATE meetings SET status='completed', minutes=?, updated_by=? WHERE meeting_id=?")->execute([($minutes!==''?$minutes:null), $_SESSION['user_id'], $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Complete meeting', "meeting #$id completed");
            echo json_encode(['success' => true, 'message' => 'Meeting completed']);
            break;
        }
        case 'cancel': {
            $id = intval($_POST['meeting_id'] ?? 0);
            if (!$id) throw new Exception('Meeting id is required');
            $cur = $pdo->query("SELECT title, status, meeting_type, zoom_meeting_id FROM meetings WHERE meeting_id=$id AND status!='deleted'")->fetch(PDO::FETCH_ASSOC);
            if (!$cur || $cur['status'] !== 'scheduled') throw new Exception('Only a scheduled meeting can be cancelled');

            // Zoom's side is deleted BEFORE the local cancel commits (plan 0.4) — but a
            // Zoom failure still never blocks the local cancel (plan 4.3); it's recorded
            // as zoom_sync_status='failed' with a Retry path instead.
            $zoomMessage = '';
            if ($cur['meeting_type'] === 'zoom' && !empty($cur['zoom_meeting_id'])) {
                $del = zoomSyncDeleteRow($pdo, $id, $cur['zoom_meeting_id']);
                if (!$del['ok']) $zoomMessage = ' Zoom-side deletion failed: ' . $del['message'] . ' — use Retry from the meeting menu.';
            }

            $pdo->prepare("UPDATE meetings SET status='cancelled', updated_by=? WHERE meeting_id=?")->execute([$_SESSION['user_id'], $id]);
            notifyMeetingAttendees($pdo, $id, $cur['title'], 'cancelled');
            logActivity($pdo, $_SESSION['user_id'], 'Cancel meeting', "meeting #$id cancelled" . ($zoomMessage ? " ($zoomMessage)" : ''));
            if (function_exists('logAudit') && $cur['meeting_type'] === 'zoom') logAudit($pdo, $_SESSION['user_id'], 'zoom_meeting_cancel', [
                'entity_type' => 'meeting', 'entity_id' => $id,
                'description' => "Zoom meeting #$id cancelled: " . ($zoomMessage ? 'zoom delete failed' : 'zoom delete ok'),
            ]);
            echo json_encode(['success' => true, 'message' => 'Meeting cancelled' . $zoomMessage]);
            break;
        }
        case 'retry_zoom': {
            $id = intval($_POST['meeting_id'] ?? 0);
            if (!$id) throw new Exception('Meeting id is required');
            $rowStmt = $pdo->prepare("SELECT * FROM meetings WHERE meeting_id=? AND status!='deleted'");
            $rowStmt->execute([$id]);
            $m = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$m) throw new Exception('Meeting not found');
            if ($m['meeting_type'] !== 'zoom') throw new Exception('This meeting is not a Zoom meeting.');
            if ($m['zoom_sync_status'] !== 'failed') throw new Exception('Nothing to retry — Zoom sync is not in a failed state.');

            if ($m['status'] === 'cancelled') {
                if (empty($m['zoom_meeting_id'])) {
                    $pdo->prepare("UPDATE meetings SET zoom_sync_status='synced' WHERE meeting_id=?")->execute([$id]);
                    echo json_encode(['success' => true, 'message' => 'Nothing to delete on Zoom — marked synced.']);
                    break;
                }
                $res = zoomSyncDeleteRow($pdo, $id, $m['zoom_meeting_id']);
            } else {
                $res = zoomSyncMeetingRow($pdo, $id, $m, [
                    'title' => $m['title'], 'agenda' => $m['agenda'], 'meeting_date' => $m['meeting_date'],
                    'start_time' => $m['start_time'], 'end_time' => $m['end_time'], 'host_user_id' => (int)$m['host_user_id'],
                    'zoom_host_video' => $m['zoom_host_video'], 'zoom_participant_video' => $m['zoom_participant_video'],
                    'zoom_waiting_room' => $m['zoom_waiting_room'], 'zoom_auto_recording' => $m['zoom_auto_recording'],
                ]);
            }
            logActivity($pdo, $_SESSION['user_id'], 'Retry Zoom sync', "meeting #$id: " . ($res['ok'] ? 'succeeded' : 'failed again — ' . $res['message']));
            echo json_encode(['success' => $res['ok'], 'message' => $res['ok'] ? 'Zoom sync succeeded.' : ('Retry failed: ' . $res['message'])]);
            break;
        }
        case 'delete': {
            $id = intval($_POST['meeting_id'] ?? 0);
            if (!$id) throw new Exception('Meeting id is required');
            $pdo->prepare("UPDATE meetings SET status='deleted', updated_by=? WHERE meeting_id=?")->execute([$_SESSION['user_id'], $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Delete meeting', "meeting #$id deleted");
            echo json_encode(['success' => true, 'message' => 'Meeting deleted']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
