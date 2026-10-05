<?php
/**
 * actions/superadmin_2fa_action.php — an operator turning their own second
 * factor on or off.
 *
 * Only ever acts on the SIGNED-IN operator: the id comes from the session, not
 * from the request. There is deliberately no tenant_id or superadmin_id
 * parameter here — one operator must not be able to strip the second factor
 * off another's account, and a parameter that could be tampered with is the
 * usual way that becomes possible.
 *
 * Same guard order as every other superadmin action: host, session, POST, CSRF.
 */
require_once __DIR__ . '/../core/superadmin_2fa.php';
require_once __DIR__ . '/../core/superadmin_auth.php';
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

csrf_check();

$id     = (int)$me['id'];
$action = (string)($_POST['action'] ?? '');

switch ($action) {
    case 'begin':
        $r = saTotpBeginEnrollment($id);
        if (!$r['ok']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $r['error']]);
            exit;
        }
        // The secret is shown ONCE, here, so the operator can scan or type it.
        // It is already stored encrypted; nothing logs this response.
        echo json_encode([
            'success'   => true,
            'uri'       => $r['uri'],
            'secret'    => $r['secret'],
            'formatted' => $r['formatted'],
        ]);
        exit;

    case 'confirm':
        $r = saTotpConfirm($id, (string)($_POST['code'] ?? ''));
        if (!$r['ok']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $r['error']]);
            exit;
        }
        // The only moment these exist outside a SHA-256. They are not logged
        // and cannot be fetched again.
        echo json_encode([
            'success'        => true,
            'message'        => 'Two-step sign-in is on.',
            'recovery_codes' => $r['recovery_codes'],
        ]);
        exit;

    case 'disable':
        $r = saTotpDisable($id, (string)($_POST['current_password'] ?? ''));
        if (!$r['ok']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $r['error']]);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Two-step sign-in is off.']);
        exit;

    default:
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
