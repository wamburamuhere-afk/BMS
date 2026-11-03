<?php
// scope-audit: skip — support requests live in the control DB, scoped to this company and user by core/support_requests.php
/**
 * api/mobile/support.php — Help & Support for the Flutter app
 * (mobile_api_upgrade_plan.md Phase 8).
 *
 * Help articles and support requests run the web endpoints themselves, so the
 * app follows the same rules (signed-in + 'help' permission; staff see their
 * own requests, admins the company's; staff → admin → BJP forwarding):
 *
 *   POST action=home | search | article | feedback | dismiss_notice   → api/support_help.php
 *        (page_key, q, id, helpful … — the web's own fields)
 *   POST action=create | list | reply | resolve | rate | forward      → api/support_requests.php
 *        (subject, body, category, attachments[] … — multipart for files)
 *   GET  action=view&id=N        one request + its conversation, as the web Help page shows it
 *   GET  action=attachment&id=N  download one attachment (the web's access check)
 *
 * The Bearer token is the authentication, so the web CSRF check passes.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();
mobileJsonBody();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if (!empty($GLOBALS['BMS_MOBILE_TOKEN_ID'])) $_POST['_csrf'] = csrf_token();
if (isset($_SESSION['user_lang']) && function_exists('loadLanguage')) loadLanguage($_SESSION['user_lang']);

// ── Download: the web endpoint streams the file after its own access check ──
if ($action === 'attachment') {
    require __DIR__ . '/../support_attachment.php';
    exit;
}

// ── Articles and requests: the web endpoints, unchanged ──────────────────────
$help     = ['home', 'search', 'article', 'feedback', 'dismiss_notice'];
$requests = ['create', 'list', 'reply', 'resolve', 'rate', 'forward'];
if (in_array($action, $help, true) || in_array($action, $requests, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    $_POST['action'] = $action;
    global $pdo;
    require __DIR__ . (in_array($action, $help, true) ? '/../support_help.php' : '/../support_requests.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('help'))   { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

// ── One request + its conversation (app/constant/settings/help.php, request view) ──
if ($action === 'view') {
    require_once __DIR__ . '/../../core/support_requests.php';
    $me = supportCurrentUser();
    $id = ctype_digit((string)($_GET['id'] ?? $_POST['id'] ?? '')) ? (int)($_GET['id'] ?? $_POST['id']) : 0;
    $r  = $id ? supportRequestGetForUser($id, $me) : null;
    if (!$r) { http_response_code(404); echo json_encode(['success' => false, 'message' => t('This request is not available.')]); exit; }

    $isRequester = (int)$r['tenant_user_id'] === (int)$me['user_id'];
    $open        = !in_array($r['status'], ['resolved', 'closed'], true);
    $supportName = $me['admin'] ? t('BJP Support') : t('Support');
    [$label, $cls] = supportStatusLabel($r, $me);

    $messages = [];
    foreach (supportRequestMessages($id) as $m) {
        $messages[] = [
            'id'          => (int)$m['id'],
            'author_type' => $m['author_type'],          // tenant | bjp | system
            'author_name' => $m['author_type'] === 'bjp' ? $supportName : (string)$m['author_name'],
            'mine'        => $m['author_type'] === 'tenant' && (int)$m['author_id'] === (int)$me['user_id'],
            'body'        => (string)$m['body'],
            'created_at'  => (string)$m['created_at'],
            'attachments' => array_map(fn($a) => [
                'id' => (int)$a['id'], 'name' => (string)$a['original_name'], 'mime' => (string)$a['mime'], 'size' => (int)$a['size_bytes'],
                'url' => '/api/mobile/support.php?action=attachment&id=' . (int)$a['id'],
            ], $m['attachments']),
        ];
    }
    echo json_encode([
        'success' => true,
        'request' => [
            'id' => (int)$r['id'], 'ref' => $r['ref_no'], 'subject' => $r['subject'], 'category' => $r['category'],
            'status' => $r['status'], 'status_label' => t($label), 'status_class' => $cls,
            'created_at' => $r['created_at'], 'updated' => $r['last_activity_at'], 'rating' => $r['rating'] !== null ? (int)$r['rating'] : null,
        ],
        'messages' => $messages,
        // The same buttons the web page shows.
        'can_reply'   => $r['status'] !== 'closed',
        'can_resolve' => $open && ($isRequester || $me['admin']),
        'can_forward' => $me['admin'] && !$isRequester && $r['audience'] === 'company' && $open,
        'can_rate'    => $isRequester && $r['status'] === 'resolved',
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
