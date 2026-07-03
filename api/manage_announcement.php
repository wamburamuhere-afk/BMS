<?php
// API: Manage announcements (Tier 4, Phase 4.2 — D25).
// add / edit / publish / archive / delete. On publish, resolve the audience
// (all active users / by department / by project) and create one in-app
// notification each, deduped via notification_dedupe (event hr_announcement).
// The message_center thread model is left completely untouched.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/notify.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
// publish/archive are workflow verbs → canPublish, falling back to canEdit when
// the verb column is absent; create/edit/delete use the CRUD gates.
$need = ($action === 'add') ? 'create' : (($action === 'delete') ? 'delete' : 'edit');
$ok = $need === 'create' ? canCreate('announcements') : ($need === 'delete' ? canDelete('announcements') : canEdit('announcements'));
if (in_array($action, ['publish', 'archive'], true)) {
    $ok = function_exists('canPublish') ? canPublish('announcements') : canEdit('announcements');
}
if (!$ok) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    switch ($action) {
        case 'add':
        case 'edit': {
            $id = intval($_POST['announcement_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $body  = trim($_POST['body'] ?? '');
            $priority = $_POST['priority'] ?? 'normal';
            if (!in_array($priority, ['normal','important','urgent'], true)) $priority = 'normal';
            $audience = $_POST['audience_type'] ?? 'all';
            if (!in_array($audience, ['all','department','project'], true)) $audience = 'all';
            $dept = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
            $proj = ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null;
            $pub  = trim($_POST['publish_date'] ?? date('Y-m-d'));
            $exp  = trim($_POST['expire_date'] ?? '');
            if ($title === '') throw new Exception('Title is required');
            if ($body === '') throw new Exception('Body is required');
            if (!strtotime($pub)) throw new Exception('A valid publish date is required');
            if ($exp !== '' && (!strtotime($exp) || strtotime($exp) < strtotime($pub))) throw new Exception('Expiry must be on or after the publish date');
            if ($audience === 'department' && !$dept) throw new Exception('Pick a department for a department-scoped announcement');
            if ($audience === 'project') {
                if (!$proj) throw new Exception('Pick a project for a project-scoped announcement');
                if (function_exists('userCan') && !userCan('project', $proj)) throw new Exception('That project is not in your scope');
            }
            if ($audience !== 'department') $dept = null;
            if ($audience !== 'project') $proj = null;

            if ($action === 'add') {
                $pdo->prepare("INSERT INTO announcements (title, body, priority, audience_type, department_id, project_id, publish_date, expire_date, status, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)")
                    ->execute([$title, $body, $priority, $audience, $dept, $proj, $pub, ($exp !== '' ? $exp : null), $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
                logActivity($pdo, $_SESSION['user_id'], 'Add announcement', "drafted announcement '$title'");
                echo json_encode(['success' => true, 'message' => 'Announcement saved as draft', 'announcement_id' => $id]);
            } else {
                if (!$id) throw new Exception('Announcement id is required');
                $pdo->prepare("UPDATE announcements SET title=?, body=?, priority=?, audience_type=?, department_id=?, project_id=?, publish_date=?, expire_date=?, updated_by=?
                               WHERE announcement_id=? AND status!='deleted'")
                    ->execute([$title, $body, $priority, $audience, $dept, $proj, $pub, ($exp !== '' ? $exp : null), $_SESSION['user_id'], $id]);
                logActivity($pdo, $_SESSION['user_id'], 'Edit announcement', "updated announcement #$id");
                echo json_encode(['success' => true, 'message' => 'Announcement updated']);
            }
            break;
        }
        case 'publish': {
            $id = intval($_POST['announcement_id'] ?? 0);
            if (!$id) throw new Exception('Announcement id is required');
            $a = $pdo->prepare("SELECT * FROM announcements WHERE announcement_id=? AND status!='deleted'");
            $a->execute([$id]);
            $ann = $a->fetch(PDO::FETCH_ASSOC);
            if (!$ann) throw new Exception('Announcement not found');

            $pdo->prepare("UPDATE announcements SET status='published', updated_by=? WHERE announcement_id=?")->execute([$_SESSION['user_id'], $id]);

            // Resolve audience users (D25)
            if ($ann['audience_type'] === 'department' && $ann['department_id']) {
                $us = $pdo->prepare("SELECT user_id FROM users WHERE is_active=1 AND department_id=?");
                $us->execute([(int)$ann['department_id']]);
            } elseif ($ann['audience_type'] === 'project' && $ann['project_id']) {
                $us = $pdo->prepare("SELECT DISTINCT user_id FROM user_projects WHERE project_id=?");
                $us->execute([(int)$ann['project_id']]);
            } else {
                $us = $pdo->query("SELECT user_id FROM users WHERE is_active=1");
            }
            $recipients = $us->fetchAll(PDO::FETCH_COLUMN);

            $sev = ['normal'=>'medium','important'=>'high','urgent'=>'high'][$ann['priority']] ?? 'medium';
            $sent = 0;
            foreach ($recipients as $uid) {
                $uid = (int)$uid;
                if (!notifClaimDedupe($pdo, "hr_announcement|$id|u$uid")) continue;
                $nid = createNotification($pdo, $uid, [
                    'title' => 'Announcement: ' . $ann['title'],
                    'message' => mb_substr(strip_tags($ann['body']), 0, 240),
                    'type' => 'system', 'event_key' => 'hr_announcement', 'category' => 'Human Resources',
                    'priority' => $sev, 'action_url' => function_exists('getUrl') ? getUrl('my_hr') : '/my_hr',
                ]);
                if ($nid > 0) $sent++;
            }
            logActivity($pdo, $_SESSION['user_id'], 'Publish announcement', "published '{$ann['title']}' to $sent recipient(s)");
            logAudit($pdo, $_SESSION['user_id'], 'publish', [
                'activity_type' => 'status_change', 'entity_type' => 'announcement', 'entity_id' => $id,
                'description' => "Published announcement '{$ann['title']}'", 'new_values' => ['status' => 'published', 'notified' => $sent],
            ]);
            echo json_encode(['success' => true, 'message' => "Published — notified $sent user(s)"]);
            break;
        }
        case 'archive': {
            $id = intval($_POST['announcement_id'] ?? 0);
            if (!$id) throw new Exception('Announcement id is required');
            $pdo->prepare("UPDATE announcements SET status='archived', updated_by=? WHERE announcement_id=? AND status!='deleted'")->execute([$_SESSION['user_id'], $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Archive announcement', "archived announcement #$id");
            echo json_encode(['success' => true, 'message' => 'Announcement archived']);
            break;
        }
        case 'delete': {
            $id = intval($_POST['announcement_id'] ?? 0);
            if (!$id) throw new Exception('Announcement id is required');
            $pdo->prepare("UPDATE announcements SET status='deleted', updated_by=? WHERE announcement_id=?")->execute([$_SESSION['user_id'], $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Delete announcement', "deleted announcement #$id");
            echo json_encode(['success' => true, 'message' => 'Announcement deleted']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
