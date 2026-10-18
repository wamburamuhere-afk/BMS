<?php
// New platform companies that look like an existing customer — a person decides (D4).
// service_plans_plan.md Phase 4. Customers page, platform root only.
//   GET                                      → open "possible match" alerts with their look-alike customers
//   POST action=link,   alert_id, customer_id → this company is that customer
//   POST action=create, alert_id              → a different client: create a new customer for it
// scope-audit: skip — customers are company-wide (no project scope)
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/vendor_context.php';
require_once __DIR__ . '/../../core/vendor_link.php';
require_once __DIR__ . '/../../core/vendor_bridge.php';

header('Content-Type: application/json');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => 'Unauthorized'], 401);
if (!isPlatformVendorContext()) $out(['success' => false, 'message' => 'Not available here.'], 404);
if (!canView('customers')) $out(['success' => false, 'message' => 'Permission denied'], 403);
if (!vlLinkReady() || !vbAlertsReady()) $out(['success' => true, 'items' => []]);

$openAlert = function (int $alertId): ?array {
    $st = getControlPdo()->prepare("SELECT id, tenant_id, message, created_at FROM vendor_bridge_alerts
                                     WHERE id = ? AND kind = 'possible_match' AND resolved_at IS NULL");
    $st->execute([$alertId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = getControlPdo()->query("SELECT id, tenant_id, created_at FROM vendor_bridge_alerts
                                     WHERE kind = 'possible_match' AND resolved_at IS NULL ORDER BY id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    foreach ($rows as $a) {
        $t = vlFindTenant(null, (int)$a['tenant_id']);
        if (!$t) continue;
        $owner = trim(($t['owner_first_name'] ?? '') . ' ' . ($t['owner_last_name'] ?? ''));
        $email = vlTenantContactEmail($t);
        // Re-checked live: the look-alikes may have changed since the alert was raised.
        $items[] = [
            'alert_id' => (int)$a['id'], 'created_at' => $a['created_at'],
            'tenant'   => ['id' => (int)$t['id'], 'company_name' => $t['company_name'], 'subdomain' => $t['subdomain'],
                           'owner' => $owner, 'phone' => $t['owner_phone'] ?? '', 'email' => $email],
            'matches'  => vlCustomerMatches($pdo, (string)($t['owner_phone'] ?? ''), $email),
        ];
    }
    $out(['success' => true, 'items' => $items, 'can_edit' => canEdit('customers'), 'can_create' => canCreate('customers')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => 'Method not allowed'], 405);
csrf_check();

$me  = (int)$_SESSION['user_id'];
$who = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ('user #' . $me);
$action = (string)($_POST['action'] ?? '');
$a = $openAlert((int)($_POST['alert_id'] ?? 0));
if (!$a) $out(['success' => false, 'message' => 'Already dealt with. Refresh the page.'], 404);
$t = vlFindTenant(null, (int)$a['tenant_id']);
if (!$t) $out(['success' => false, 'message' => 'That company is no longer on the platform.'], 404);

if ($action === 'link') {
    if (!canEdit('customers')) $out(['success' => false, 'message' => 'Permission denied'], 403);
    $cid = (int)($_POST['customer_id'] ?? 0);
    $st = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = ? AND status <> 'deleted'");
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) $out(['success' => false, 'message' => 'Customer not found'], 404);
    $r = vlSetTenantCustomer((int)$t['id'], $cid, $who, $c['customer_code'] . ' — ' . $c['customer_name']);
    if (!$r['ok']) $out(['success' => false, 'message' => $r['error']], 409);
    vendorRequeueCustomer($pdo, $cid);   // paid invoices that were waiting for this link apply now
    logActivity($pdo, $me, 'Link platform company', "{$c['customer_code']} now pays for {$t['subdomain']}");
    logAudit($pdo, $me, 'vendor_customer_linked', ['entity_type' => 'customer', 'entity_id' => $cid,
        'new_values' => ['tenant_id' => (int)$t['id'], 'subdomain' => $t['subdomain'], 'from' => 'possible_match']]);
    $out(['success' => true, 'message' => "{$t['subdomain']} is now linked to {$c['customer_code']}."]);
}

if ($action === 'create') {
    if (!canCreate('customers')) $out(['success' => false, 'message' => 'Permission denied'], 403);
    $r = vlEnsureCustomerForTenant($pdo, (int)$t['id'], 'possible match reviewed', ['force' => true, 'user_id' => $me, 'who' => $who]);
    if (!in_array($r['state'], ['created', 'linked'], true)) {
        error_log("platform_match_review create: tenant {$t['id']}: {$r['state']} — {$r['message']}");
        $out(['success' => false, 'message' => 'Server error. Please try again.'], 500);
    }
    if ($r['state'] === 'created') {
        logActivity($pdo, $me, 'Create customer', "Customer {$r['customer_code']} created for platform company {$t['subdomain']}");
        logAudit($pdo, $me, 'vendor_customer_linked', ['entity_type' => 'customer', 'entity_id' => (int)$r['customer_id'],
            'new_values' => ['tenant_id' => (int)$t['id'], 'subdomain' => $t['subdomain'], 'from' => 'possible_match_create']]);
    }
    $out(['success' => true, 'customer_id' => $r['customer_id'],
          'message' => $r['state'] === 'created' ? "Customer {$r['customer_code']} created for {$t['subdomain']}."
                                                 : "Already a customer — {$r['customer_code']}."]);
}

$out(['success' => false, 'message' => 'Unknown action'], 422);
