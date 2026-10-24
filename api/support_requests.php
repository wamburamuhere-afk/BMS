<?php
/**
 * api/support_requests.php — a company's support requests (support_center_plan.md, Phase 4).
 *
 * Signed-in users only, POST + CSRF. Everything is scoped to the caller's company and,
 * unless they are a company admin, to their own requests (core/support_requests.php).
 * Actions: create · list · reply · resolve · rate · forward
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/support_requests.php';
require_once __DIR__ . '/../core/support_articles.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('help')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$u  = supportCurrentUser();
$id = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
$out = function (int $code, array $body): void { http_response_code($code); echo json_encode($body); exit; };
$fail = fn(array $r) => $out(422, ['success' => false, 'message' => t($r['error'] ?? 'Something went wrong.'), 'errors' => $r['errors'] ?? null]);

try {
    switch ((string)($_POST['action'] ?? '')) {
        case 'create': {
            $up = supportValidateUploads($_FILES['attachments'] ?? null);
            if (!$up['ok']) $out(422, ['success' => false, 'message' => t($up['error'])]);
            $plan = null;
            if ($u['tenant_id']) {
                try { $s = getControlPdo()->prepare('SELECT plan FROM tenants WHERE id = ?'); $s->execute([$u['tenant_id']]); $plan = $s->fetchColumn() ?: null; } catch (Throwable $e) {}
            }
            $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
            $pageUrl = $ref !== '' ? (string)parse_url($ref, PHP_URL_PATH) : '';
            $r = supportRequestCreate($_POST, $u, $up['files'], [
                'page_url' => $pageUrl, 'browser' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'product_key' => supportTenantProductKey(), 'plan_code' => $plan,
            ]);
            if (!$r['ok']) $fail($r);
            logActivity($pdo, $u['user_id'], 'support_request_create', "Sent support request {$r['ref']}");
            $out(200, ['success' => true, 'id' => $r['id'], 'ref' => $r['ref'], 'url' => getUrl('help') . '?request=' . $r['id'],
                       'message' => t('Request sent.')]);
        }
        case 'list': {
            $scope = ($_POST['scope'] ?? '') === 'all' ? 'all' : 'mine';
            $rows = array_map(function ($t) use ($u) {
                [$label, $cls] = supportStatusLabel($t, $u);
                return ['id' => (int)$t['id'], 'ref' => $t['ref_no'], 'subject' => $t['subject'], 'from' => $t['user_name'],
                        'status' => t($label), 'status_class' => $cls, 'updated' => $t['last_activity_at']];
            }, supportRequestList($u, $scope));
            $out(200, ['success' => true, 'requests' => $rows, 'waiting' => supportRequestWaitingCount($u)]);
        }
        case 'reply': {
            $up = supportValidateUploads($_FILES['attachments'] ?? null);
            if (!$up['ok']) $out(422, ['success' => false, 'message' => t($up['error'])]);
            $r = supportRequestReply($id, $u, (string)($_POST['body'] ?? ''), $up['files']);
            if (!$r['ok']) $fail($r);
            logActivity($pdo, $u['user_id'], 'support_request_reply', "Replied on support request #{$id}");
            $out(200, ['success' => true, 'message' => t('Reply sent.')]);
        }
        case 'resolve': {
            $r = supportRequestResolve($id, $u);
            if (!$r['ok']) $fail($r);
            logActivity($pdo, $u['user_id'], 'support_request_resolve', "Resolved support request #{$id}");
            $out(200, ['success' => true, 'message' => t('Marked as resolved.')]);
        }
        case 'rate': {
            $r = supportRequestRate($id, $u, (int)($_POST['rating'] ?? 0), (string)($_POST['comment'] ?? ''));
            if (!$r['ok']) $fail($r);
            logActivity($pdo, $u['user_id'], 'support_request_rate', "Rated support request #{$id}");
            $out(200, ['success' => true, 'message' => t('Thanks for your feedback.')]);
        }
        case 'forward': {
            $r = supportRequestForward($id, $u, (string)($_POST['note'] ?? ''));
            if (!$r['ok']) $fail($r);
            logActivity($pdo, $u['user_id'], 'support_request_forward', "Forwarded support request #{$id} as {$r['ref']}");
            $out(200, ['success' => true, 'ref' => $r['ref'], 'message' => t('Forwarded to the support team.')]);
        }
        default:
            $out(400, ['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('support_requests: ' . $e->getMessage());
    $out(500, ['success' => false, 'message' => t('Something went wrong. Please try again.')]);
}
