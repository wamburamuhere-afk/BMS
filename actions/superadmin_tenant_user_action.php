<?php
/**
 * actions/superadmin_tenant_user_action.php — recover one tenant account:
 * send a one-time code, correct the recovery email, correct the username, or
 * re-enable a switched-off account.
 *
 * Every operation lives in core/tenant_account_recovery.php, which is the one
 * place the panel may write to a tenant's users table. This file is only the
 * door: same guard order as every other superadmin action — host, session,
 * POST, CSRF — then hand over.
 *
 * Note what is NOT here: anything that sets a password, and anything that
 * signs the operator in as the customer. The owner always chooses their own
 * password, through the tenant's own forgot-password.php.
 */
require_once __DIR__ . '/../core/tenant_account_recovery.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// A tenant subdomain gets a flat 404 — the panel does not exist as far as
// tenants are concerned.
assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();   // §21 — exits 419 on mismatch

$action   = (string)($_POST['action'] ?? '');
$tenantId = (int)($_POST['tenant_id'] ?? 0);
$userId   = (int)($_POST['user_id']   ?? 0);

if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}
if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No user specified.']);
    exit;
}

$extra = [];

switch ($action) {
    case 'issue_otp':
        $r = tenantIssueAdminOtp($tenantId, $userId);
        if ($r['ok']) {
            // The code itself never comes back here — see the header of
            // core/tenant_account_recovery.php.
            $extra = ['sent_to' => $r['sent_to'], 'expires_minutes' => $r['expires_minutes']];
            $okMsg = 'A one-time code has been emailed to ' . $r['sent_to']
                   . '. It expires in ' . $r['expires_minutes'] . ' minutes and works once. '
                   . 'They will be asked to choose their own password — you will not see it.';
        }
        break;

    case 'set_email':
        $r     = tenantSetUserEmail($tenantId, $userId, (string)($_POST['email'] ?? ''));
        $okMsg = 'Recovery email updated. The previous address has been told it changed.';
        break;

    case 'set_username':
        $r     = tenantSetUserUsername($tenantId, $userId, (string)($_POST['username'] ?? ''));
        $okMsg = 'Username updated. The account holder has been emailed the new one.';
        break;

    case 'reactivate':
        $r     = tenantReactivateUser($tenantId, $userId);
        $okMsg = 'Account re-enabled. They can sign in again.';
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

echo json_encode(['success' => true, 'message' => $okMsg] + $extra);
