<?php
/**
 * actions/superadmin_support_inbox.php — BJP agent actions on support requests
 * (support_center_plan.md, Phase 5). Control database only.
 * Guard order mirrors actions/superadmin_platform_settings.php: host, session, POST, CSRF.
 *
 * Actions: reply · assign · set_status · set_priority · save_canned · set_canned_active
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/support_inbox.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

function si_out(int $code, array $body): void { http_response_code($code); echo json_encode($body); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') si_out(405, ['success' => false, 'message' => 'Method not allowed.']);
$me = currentSuperadmin();
if ($me === null) si_out(401, ['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
csrf_check();

$id = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
$agent = ['id' => (int)$me['id'], 'name' => (string)($me['name'] ?? 'BJP Support')];
$res = fn(array $r, string $okMsg) => $r['ok'] ? si_out(200, ['success' => true, 'message' => $okMsg] + array_diff_key($r, ['ok' => 1, 'error' => 1]))
                                               : si_out(422, ['success' => false, 'message' => $r['error']]);

try {
    switch ((string)($_POST['action'] ?? '')) {
        case 'reply': {
            $up = supportValidateUploads($_FILES['attachments'] ?? null);
            if (!$up['ok']) si_out(422, ['success' => false, 'message' => $up['error']]);
            $internal = (string)($_POST['internal'] ?? '0') === '1';
            $r = supportInboxReply($id, $agent, (string)($_POST['body'] ?? ''), $internal, $up['files'], (string)($_POST['after'] ?? 'waiting_on_customer'));
            if ($r['ok']) logTenantAdminAction(null, null, 'support_reply', ($internal ? 'Internal note on' : 'Replied to') . " support request #{$id}");
            $res($r, $internal ? 'Note saved.' : 'Reply sent.');
        }
        case 'assign': {
            $aid = ctype_digit((string)($_POST['agent_id'] ?? '')) ? (int)$_POST['agent_id'] : null;
            $r = supportInboxAssign($id, $aid);
            if ($r['ok']) logTenantAdminAction(null, null, 'support_assign', "Support request #{$id} assigned to " . ($aid ?? 'nobody'));
            $res($r, 'Assignment saved.');
        }
        case 'set_status': {
            $r = supportInboxSetStatus($id, (string)($_POST['status'] ?? ''), $agent);
            if ($r['ok']) logTenantAdminAction(null, null, 'support_status', "Support request #{$id} → " . (string)$_POST['status']);
            $res($r, 'Status saved.');
        }
        case 'set_priority': {
            $r = supportInboxSetPriority($id, (string)($_POST['priority'] ?? ''));
            if ($r['ok']) logTenantAdminAction(null, null, 'support_priority', "Support request #{$id} priority " . (string)$_POST['priority']);
            $res($r, 'Priority saved.');
        }
        case 'save_canned': {
            $r = supportCannedSave($_POST, $id ?: null, $agent['id']);
            if ($r['ok']) logTenantAdminAction(null, null, 'support_canned', ($id ? 'Updated' : 'Created') . ' saved reply #' . $r['id']);
            $res($r, 'Saved reply saved.');
        }
        case 'set_canned_active': {
            $active = (string)($_POST['active'] ?? '') === '1';
            if (!$id || !supportCannedSetActive($id, $active)) si_out(404, ['success' => false, 'message' => 'That saved reply no longer exists.']);
            logTenantAdminAction(null, null, 'support_canned', "Saved reply #{$id} " . ($active ? 'in use' : 'retired'));
            si_out(200, ['success' => true, 'message' => $active ? 'Saved reply in use again.' : 'Saved reply retired.']);
        }
        default:
            si_out(400, ['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('superadmin_support_inbox: ' . $e->getMessage());
    si_out(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
