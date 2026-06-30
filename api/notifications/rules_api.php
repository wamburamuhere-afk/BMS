<?php
/**
 * api/notifications/rules_api.php
 * ---------------------------------------------------------------------------
 * Phase 5b — admin API for the Notification Rules screen.
 * Single action-based endpoint (admin-only):
 *   GET  ?action=list                      -> events (grouped) + their rules + roles + users + globals
 *   GET  ?action=preview&event_key=...     -> who would receive this event right now
 *   POST action=save                       -> add a routing rule
 *   POST action=delete&id=...              -> remove a rule
 *   POST action=toggle_event               -> enable/disable an event
 *   POST action=set_global                 -> master switch / global email toggle
 *   POST action=test_send&event_key=...    -> send a sample email to the current admin
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/notify.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!(isAdmin() || canView('notification_rules'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: admin only']);
    exit;
}

global $pdo;
$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? '';

try {
    if ($method !== 'GET') {
        csrf_check();
    }

    // ── LIST ────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $events = $pdo->query("SELECT event_key, title, description, module, page_key, required_verb,
                                       default_severity, scope_aware, is_active
                                FROM notification_events ORDER BY module, title")->fetchAll(PDO::FETCH_ASSOC);

        $roles = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
        $users = $pdo->query("SELECT user_id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, username, email
                              FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $roleMap = []; foreach ($roles as $r) $roleMap[(int)$r['role_id']] = $r['role_name'];
        $userMap = []; foreach ($users as $u) $userMap[(int)$u['user_id']] = ($u['name'] !== '' ? $u['name'] : $u['username']);

        $ruleRows = $pdo->query("SELECT * FROM notification_rules WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $rulesByEvent = [];
        foreach ($ruleRows as $r) {
            $tt = $r['target_type'];
            $label = $tt === 'permission' ? 'Everyone with access'
                   : ($tt === 'role' ? ('Role: ' . ($roleMap[(int)$r['target_id']] ?? ('#' . $r['target_id'])))
                   : ('User: ' . ($userMap[(int)$r['target_id']] ?? ('#' . $r['target_id']))));
            $chans = [];
            if ((int)$r['channel_inapp']) $chans[] = 'In-app';
            if ((int)$r['channel_email']) $chans[] = 'Email';
            $rulesByEvent[$r['event_key']][] = [
                'id' => (int)$r['id'], 'target_type' => $tt, 'target_id' => $r['target_id'] !== null ? (int)$r['target_id'] : null,
                'label' => $label, 'channels' => implode(' + ', $chans) ?: '—',
                'channel_email' => (int)$r['channel_email'], 'channel_inapp' => (int)$r['channel_inapp'],
            ];
        }
        foreach ($events as &$e) {
            $e['is_active'] = (int)$e['is_active'];
            $e['scope_aware'] = (int)$e['scope_aware'];
            $e['rules'] = $rulesByEvent[$e['event_key']] ?? [];
        }
        unset($e);

        echo json_encode([
            'success' => true,
            'events'  => $events,
            'roles'   => $roles,
            'users'   => array_map(fn($u) => ['user_id' => (int)$u['user_id'], 'name' => ($u['name'] !== '' ? $u['name'] : $u['username'])], $users),
            'globals' => [
                'notif_master_enabled'       => (string)get_setting('notif_master_enabled', '1'),
                'enable_email_notifications'  => (string)get_setting('enable_email_notifications', '0'),
                'notif_digest_enabled'        => (string)get_setting('notif_digest_enabled', '0'),
            ],
        ]);
        exit;
    }

    // ── PREVIEW ─────────────────────────────────────────────────────────
    if ($action === 'preview') {
        $eventKey = trim($_GET['event_key'] ?? '');
        if ($eventKey === '') { echo json_encode(['success' => false, 'message' => 'event_key required']); exit; }
        $list = previewRecipients($pdo, $eventKey);
        echo json_encode(['success' => true, 'recipients' => $list, 'count' => count($list)]);
        exit;
    }

    // ── SAVE rule ───────────────────────────────────────────────────────
    if ($action === 'save') {
        $eventKey = trim($_POST['event_key'] ?? '');
        $tt = $_POST['target_type'] ?? 'permission';
        $tid = ($_POST['target_id'] ?? '') !== '' ? (int)$_POST['target_id'] : null;
        $cEmail = !empty($_POST['channel_email']) ? 1 : 0;
        $cInapp = !empty($_POST['channel_inapp']) ? 1 : 0;

        if ($eventKey === '') throw new Exception('event_key is required');
        if (!in_array($tt, ['permission', 'role', 'user'], true)) throw new Exception('Invalid target type');
        if (($tt === 'role' || $tt === 'user') && !$tid) throw new Exception('Pick a ' . $tt);
        if (!$cEmail && !$cInapp) throw new Exception('Select at least one channel');

        $ok = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
        $ok->execute([$eventKey]);
        if (!$ok->fetchColumn()) throw new Exception('Unknown event');

        $pdo->prepare("INSERT INTO notification_rules
                        (event_key, target_type, target_id, channel_email, channel_inapp, created_by)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$eventKey, $tt, $tid, $cEmail, $cInapp, $_SESSION['user_id'] ?? null]);

        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Notification rule added for $eventKey ($tt)");
        echo json_encode(['success' => true, 'message' => 'Rule added']);
        exit;
    }

    // ── DELETE rule ─────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid rule id');
        $pdo->prepare("DELETE FROM notification_rules WHERE id = ?")->execute([$id]);
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Notification rule #$id removed");
        echo json_encode(['success' => true, 'message' => 'Rule removed']);
        exit;
    }

    // ── TOGGLE event on/off ─────────────────────────────────────────────
    if ($action === 'toggle_event') {
        $eventKey = trim($_POST['event_key'] ?? '');
        $active = !empty($_POST['is_active']) ? 1 : 0;
        if ($eventKey === '') throw new Exception('event_key required');
        $pdo->prepare("UPDATE notification_events SET is_active = ? WHERE event_key = ?")->execute([$active, $eventKey]);
        echo json_encode(['success' => true, 'message' => 'Event ' . ($active ? 'enabled' : 'disabled')]);
        exit;
    }

    // ── SET global toggle ───────────────────────────────────────────────
    if ($action === 'set_global') {
        $key = $_POST['key'] ?? '';
        if (!in_array($key, ['notif_master_enabled', 'enable_email_notifications', 'notif_digest_enabled'], true)) throw new Exception('Invalid setting');
        $val = !empty($_POST['value']) ? '1' : '0';
        save_setting($key, $val);
        echo json_encode(['success' => true, 'message' => 'Setting updated']);
        exit;
    }

    // ── TEST SEND (sample to the current admin) ─────────────────────────
    if ($action === 'test_send') {
        $eventKey = trim($_POST['event_key'] ?? '');
        if ($eventKey === '') throw new Exception('event_key required');

        $to = trim((string)($_SESSION['email'] ?? ''));
        if ($to === '') {
            $st = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
            $st->execute([$_SESSION['user_id'] ?? 0]);
            $to = trim((string)$st->fetchColumn());
        }
        if ($to === '') throw new Exception('Your account has no email address to send the test to.');

        $recips = previewRecipients($pdo, $eventKey);
        $names = array_slice(array_map(fn($r) => $r['name'], $recips), 0, 10);
        $body = '<p>This is a <strong>test</strong> of the notification event <code>' . htmlspecialchars($eventKey) . '</code>.</p>'
              . '<p>In production this event would be delivered to <strong>' . count($recips) . '</strong> recipient(s): '
              . htmlspecialchars(implode(', ', $names) . (count($recips) > 10 ? '…' : '')) . '</p>';

        require_once __DIR__ . '/../../core/mailer.php';
        $ok = sendEmail($to, 'BMS Notification Test — ' . $eventKey, $body, ['wrap' => true]);
        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Test email sent to $to. It would reach " . count($recips) . " recipient(s) in production."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Send failed: ' . mailer_last_error()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
