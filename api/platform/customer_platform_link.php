<?php
// The platform companies (tenants) a customer pays for — Customer Details, platform root only.
//   GET  customer_id                          → linked companies
//   POST customer_id, action=link, subdomain   → link a company (e.g. one that registered without a visit)
//   POST customer_id, action=unlink, tenant_id → remove a link
// customer_subscription_plan.md Phase 3. The link itself lives in the control DB (tenants.vendor_customer_id).
// scope-audit: skip — customers are company-wide (no project scope)
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/vendor_context.php';
require_once __DIR__ . '/../../core/vendor_link.php';
require_once __DIR__ . '/../../core/vendor_bridge.php';
require_once __DIR__ . '/../../core/subscription_contracts.php';

header('Content-Type: application/json');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => 'Unauthorized'], 401);
if (!isPlatformVendorContext()) $out(['success' => false, 'message' => 'Not available here.'], 404);
if (!canView('customers')) $out(['success' => false, 'message' => 'Permission denied'], 403);

$cid = (int)($_REQUEST['customer_id'] ?? 0);
$st = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = ? AND status <> 'deleted'");
$st->execute([$cid]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) $out(['success' => false, 'message' => 'Customer not found'], 404);

$list = function () use ($cid) {
    return array_map(fn($t) => ['id' => (int)$t['id'], 'subdomain' => $t['subdomain'], 'company_name' => $t['company_name'], 'status' => $t['status']],
        vlTenantsOfCustomer($cid));
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contracts = array_map(fn($k) => [
        'code' => $k['document_code'], 'service' => $k['product_name'], 'period' => $k['period_label'],
        'invoice' => $k['invoice_ref'], 'state' => $k['state'], 'document_id' => (int)$k['document_id'],
        'signed_document_id' => $k['final_document_id'] ?? $k['bjp_signed_document_id'],
    ], scForCustomer($pdo, $cid));
    $out(['success' => true, 'ready' => vlLinkReady(), 'tenants' => $list(), 'can_edit' => canEdit('customers'),
          'contracts' => $contracts, 'can_sign' => canCreate('documents')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => 'Method not allowed'], 405);
csrf_check();
if (!canEdit('customers')) $out(['success' => false, 'message' => 'Permission denied'], 403);
if (!vlLinkReady()) $out(['success' => false, 'message' => 'The platform database has not been upgraded yet. Ask the administrator to run the platform setup.'], 503);

$me  = (int)$_SESSION['user_id'];
$who = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ('user #' . $me);
$action = (string)($_POST['action'] ?? '');

if ($action === 'link') {
    $sub = vlNormalizeSubdomain((string)($_POST['subdomain'] ?? ''));
    $t = $sub !== '' ? vlFindTenant($sub) : null;
    if (!$t) $out(['success' => false, 'message' => 'No company with that web address is registered on the platform. Check the spelling.'], 422);
    $r = vlSetTenantCustomer((int)$t['id'], $cid, $who, $c['customer_code'] . ' — ' . $c['customer_name']);
    if (!$r['ok']) $out(['success' => false, 'message' => $r['error']], 409);
    logActivity($pdo, $me, 'Link platform company', "{$c['customer_code']} now pays for {$t['subdomain']}");
    vendorRequeueCustomer($pdo, $cid);   // paid invoices that were waiting for this link apply now
    logAudit($pdo, $me, 'vendor_customer_linked', ['entity_type' => 'customer', 'entity_id' => $cid,
        'new_values' => ['tenant_id' => (int)$t['id'], 'subdomain' => $t['subdomain']]]);
    $out(['success' => true, 'message' => $t['subdomain'] . ' is now linked to this customer.', 'tenants' => $list()]);
}

if ($action === 'unlink') {
    $tid = (int)($_POST['tenant_id'] ?? 0);
    $t = $tid > 0 ? vlFindTenant(null, $tid) : null;
    if (!$t || (int)($t['vendor_customer_id'] ?? 0) !== $cid) $out(['success' => false, 'message' => 'That company is not linked to this customer.'], 404);
    $r = vlSetTenantCustomer($tid, null, $who);
    if (!$r['ok']) $out(['success' => false, 'message' => $r['error']], 500);
    logActivity($pdo, $me, 'Unlink platform company', "{$c['customer_code']} no longer pays for {$t['subdomain']}");
    logAudit($pdo, $me, 'vendor_customer_unlinked', ['entity_type' => 'customer', 'entity_id' => $cid,
        'old_values' => ['tenant_id' => $tid, 'subdomain' => $t['subdomain']]]);
    $out(['success' => true, 'message' => 'Link removed.', 'tenants' => $list()]);
}

$out(['success' => false, 'message' => 'Unknown action'], 422);
