<?php
/**
 * actions/superadmin_profile_action.php — an operator changing their OWN account.
 *
 * Guards mirror actions/superadmin_tenant_action.php exactly: correct host,
 * signed-in operator, CSRF, POST only — all before any work.
 *
 * There is deliberately no "which superadmin" parameter. The id always comes
 * from the session, so this endpoint can only ever modify the caller's own
 * account; an operator cannot reset a colleague's password through it, and no
 * amount of parameter tampering changes that.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$me = currentSuperadmin();
if ($me === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();   // §21 — exits 419 on mismatch

$id     = (int)$me['id'];
$action = (string)($_POST['action'] ?? '');

switch ($action) {
    case 'update_profile':
        $r = updateSuperadminProfile(
            $id,
            (string)($_POST['name'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['current_password'] ?? '')
        );
        $okMsg = 'Your details have been updated.';
        $audit = 'Changed their own name/email';
        break;

    case 'change_password':
        // NOTE: on success this regenerates the session id and deletes the old
        // session. The browser picks up the new cookie from this response and
        // the operator stays signed in — but any client that ignores Set-Cookie
        // on an XHR (or replays the old id) will be treated as signed out.
        $r = changeSuperadminPassword(
            $id,
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? '')
        );
        $okMsg = 'Your password has been changed. You remain signed in on this device.';
        $audit = 'Changed their own password';
        break;

    default:
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

// Attributed like every other platform action. tenant_id is null: this changed
// an operator, not a tenant.
logTenantAdminAction(null, null, 'sa_credential_change', $audit);

echo json_encode(['success' => true, 'message' => $okMsg]);
