<?php
// API: Manage meetings (Tier 4, Phase 4.3 — D29).
// Minimal: schedule + attendees + minutes + status. add / update / attendees /
// mark_attendance / complete / cancel / delete. Notifies attendees' linked
// users on schedule/cancel via the notification engine (deduped).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/notify.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
$need = ($action === 'add') ? 'create' : (($action === 'delete') ? 'delete' : 'edit');
$ok = $need === 'create' ? canCreate('meetings') : ($need === 'delete' ? canDelete('meetings') : canEdit('meetings'));
if (!$ok) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

// Notify an attendee-set's linked users of a meeting event
function notifyMeetingAttendees(PDO $pdo, int $meeting_id, string $title, string $verb): void {
    $rows = $pdo->prepare("SELECT u.user_id FROM meeting_attendees ma JOIN users u ON u.employee_id = ma.employee_id WHERE ma.meeting_id = ? AND u.is_active = 1");
    $rows->execute([$meeting_id]);
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $uid = (int)$uid;
        if (!function_exists('notifClaimDedupe') || !notifClaimDedupe($pdo, "meeting_$verb|$meeting_id|u$uid")) continue;
        if (function_exists('createNotification')) createNotification($pdo, $uid, [
            'title' => 'Meeting ' . $verb . ': ' . $title, 'message' => 'You are listed as an attendee.',
            'type' => 'system', 'event_key' => 'hr_meeting', 'category' => 'Human Resources', 'priority' => 'medium',
            'action_url' => function_exists('getUrl') ? getUrl('meetings') : '/meetings',
        ]);
    }
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
            if ($title === '') throw new Exception('Title is required');
            if (!strtotime($date)) throw new Exception('A valid meeting date is required');
            if (!is_array($attendees)) $attendees = [];

            if ($action === 'add') {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO meetings (title, agenda, meeting_date, start_time, end_time, venue, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)")
                    ->execute([$title, ($agenda!==''?$agenda:null), $date, ($start!==''?$start:null), ($endt!==''?$endt:null), ($venue!==''?$venue:null), $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            } else {
                if (!$id) throw new Exception('Meeting id is required');
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE meetings SET title=?, agenda=?, meeting_date=?, start_time=?, end_time=?, venue=?, updated_by=? WHERE meeting_id=? AND status!='deleted'")
                    ->execute([$title, ($agenda!==''?$agenda:null), $date, ($start!==''?$start:null), ($endt!==''?$endt:null), ($venue!==''?$venue:null), $_SESSION['user_id'], $id]);
                $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id=?")->execute([$id]);
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO meeting_attendees (meeting_id, employee_id) VALUES (?, ?)");
            foreach ($attendees as $eid) {
                $eid = (int)$eid; if (!$eid) continue;
                if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($eid);
                $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id=? AND (status IS NULL OR status!='deleted')");
                $chk->execute([$eid]);
                if ($chk->fetch()) $ins->execute([$id, $eid]);
            }
            $pdo->commit();
            notifyMeetingAttendees($pdo, $id, $title, 'scheduled');
            logActivity($pdo, $_SESSION['user_id'], ($action==='add'?'Add':'Update').' meeting', "meeting '$title' (#$id)");
            echo json_encode(['success' => true, 'message' => 'Meeting ' . ($action==='add'?'scheduled':'updated'), 'meeting_id' => $id]);
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
            $cur = $pdo->query("SELECT title, status FROM meetings WHERE meeting_id=$id AND status!='deleted'")->fetch(PDO::FETCH_ASSOC);
            if (!$cur || $cur['status'] !== 'scheduled') throw new Exception('Only a scheduled meeting can be cancelled');
            $pdo->prepare("UPDATE meetings SET status='cancelled', updated_by=? WHERE meeting_id=?")->execute([$_SESSION['user_id'], $id]);
            notifyMeetingAttendees($pdo, $id, $cur['title'], 'cancelled');
            logActivity($pdo, $_SESSION['user_id'], 'Cancel meeting', "meeting #$id cancelled");
            echo json_encode(['success' => true, 'message' => 'Meeting cancelled']);
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
