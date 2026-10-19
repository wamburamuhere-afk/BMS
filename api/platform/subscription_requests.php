<?php
// Subscription requests sent by companies from their Subscription page — Quotations, platform root only.
//   GET                            → pending requests (the "Requests from systems" panel)
//   POST action=decline, id, reason → decline one (the company sees the reason)
// A request becomes a quotation through quotation_create.php?request=ID (api/account/save_quotation.php marks it quoted).
// scope-audit: skip — subscription requests are company-wide (no project/warehouse scope)
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/vendor_context.php';
require_once __DIR__ . '/../../core/vendor_link.php';
require_once __DIR__ . '/../../core/subscription_requests.php';

header('Content-Type: application/json');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => 'Unauthorized'], 401);
if (!isPlatformVendorContext()) $out(['success' => false, 'message' => 'Not available here.'], 404);
if (!canView('quotations')) $out(['success' => false, 'message' => 'Permission denied'], 403);
if (!srReady($pdo)) $out(['success' => true, 'requests' => [], 'can_create' => false]);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = srPending($pdo);
    // The customer the company is linked to NOW (it may have been linked since it asked).
    $cust = []; $links = [];
    foreach ($rows as $r) {
        $t = vlFindTenant(null, $r['tenant_id']);
        $cid = (int)($t['vendor_customer_id'] ?? 0);
        if ($cid > 0 && !isset($cust[$cid])) {
            $st = $pdo->prepare("SELECT customer_code, customer_name FROM customers WHERE customer_id = ?");
            $st->execute([$cid]);
            $cust[$cid] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $links[$r['id']] = $cid > 0 && !empty($cust[$cid]) ? $cust[$cid]['customer_code'] . ' — ' . $cust[$cid]['customer_name'] : null;
    }
    $out(['success' => true, 'can_create' => canCreate('quotations'), 'requests' => array_map(fn($r) => [
        'id' => $r['id'], 'number' => $r['request_number'], 'company' => $r['company_name'], 'subdomain' => $r['subdomain'],
        'phone' => $r['phone'], 'email' => $r['email'], 'term' => $r['term_label'], 'total' => $r['total'],
        'customer' => $links[$r['id']] ?? null, 'date' => $r['created_at'],
        'lines' => array_map(fn($l) => ['name' => $l['name'], 'qty' => (float)$l['qty'], 'term' => $l['term_label'] ?? null], $r['lines']),
    ], $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => 'Method not allowed'], 405);
csrf_check();
if (!canCreate('quotations')) $out(['success' => false, 'message' => 'Permission denied'], 403);

$me = (int)$_SESSION['user_id'];
if ((string)($_POST['action'] ?? '') === 'decline') {
    $r = srGet($pdo, (int)($_POST['id'] ?? 0));
    if (!$r) $out(['success' => false, 'message' => 'Request not found.'], 404);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') $out(['success' => false, 'message' => 'Give the company a reason.'], 422);
    if (!srDecline($pdo, $r['id'], $me, $reason)) $out(['success' => false, 'message' => 'This request was already handled.'], 409);
    logActivity($pdo, $me, 'Decline subscription request', "{$r['request_number']} from {$r['subdomain']}: $reason");
    logAudit($pdo, $me, 'subscription_request_declined', ['entity_type' => 'subscription_request', 'entity_id' => $r['id'],
        'new_values' => ['status' => 'declined', 'reason' => $reason]]);
    $out(['success' => true, 'message' => "{$r['request_number']} declined. {$r['company_name']} sees the reason on its Subscription page."]);
}

$out(['success' => false, 'message' => 'Unknown action'], 422);
