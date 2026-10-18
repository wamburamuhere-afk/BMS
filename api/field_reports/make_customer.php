<?php
// "Make real customer" for a visited client who joined the platform (customer_subscription_plan.md Phase 3).
//   GET  visit_id                                → preview: the visit, its company, matches, any existing link
//   POST visit_id, mode=create, (form fields)    → new customer, linked to the visit and the company
//   POST visit_id, mode=link, customer_id        → link an existing customer instead
// Platform root only. Owner of the visit or admin; creating needs canCreate('customers').
// scope-audit: skip — customers are company-wide (no project scope) and the visit is owner-checked
require_once __DIR__ . '/_common.php';
require_once ROOT_DIR . '/core/vendor_context.php';
require_once ROOT_DIR . '/core/vendor_link.php';
require_once ROOT_DIR . '/core/vendor_bridge.php';

if (!isPlatformVendorContext()) frJson(['success' => false, 'message' => t('Not available here.')], 404);

$id = (int)($_REQUEST['visit_id'] ?? 0);
$v = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$v || !array_key_exists('tenant_id', $v)) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($v) || !canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
if (!(int)$v['joined'] || empty($v['tenant_id'])) {
    frJson(['success' => false, 'message' => t('Mark the visit as joined and enter the company\'s web address first.')], 422);
}
$tenant = vlFindTenant(null, (int)$v['tenant_id']);
if (!$tenant) frJson(['success' => false, 'message' => t('That company is no longer on the platform.')], 404);

$customerRow = function (int $cid) use ($pdo): ?array {
    $s = $pdo->prepare("SELECT customer_id, customer_code, customer_name, company_name, phone, email FROM customers WHERE customer_id = ? AND status <> 'deleted'");
    $s->execute([$cid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $owner = trim(($tenant['owner_first_name'] ?? '') . ' ' . ($tenant['owner_last_name'] ?? ''));
    $linked = !empty($tenant['vendor_customer_id']) ? $customerRow((int)$tenant['vendor_customer_id']) : null;
    frJson(['success' => true,
        'visit'   => ['visit_id' => (int)$v['visit_id'], 'client_name' => $v['client_name'], 'client_phone' => $v['client_phone'],
                      'customer_id' => $v['customer_id'] ? (int)$v['customer_id'] : null],
        'tenant'  => ['id' => (int)$tenant['id'], 'subdomain' => $tenant['subdomain'], 'company_name' => $tenant['company_name'], 'status' => $tenant['status']],
        'prefill' => [
            'customer_name'  => $tenant['company_name'] ?: $v['client_name'],
            'contact_person' => $owner !== '' ? $owner : $v['client_name'],
            'phone'          => ($tenant['owner_phone'] ?? '') ?: $v['client_phone'],
            'email'          => $tenant['owner_email'] ?? '',
            'address'        => $v['location'] ?? '',
            'postal_address' => '',
            'business'       => frBusinessLabel($v),
        ],
        'linked_customer' => $linked,
        'matches' => vlCustomerMatches($pdo, (string)($tenant['owner_phone'] ?? '') ?: (string)$v['client_phone'], (string)($tenant['owner_email'] ?? '')),
        'can_create' => canCreate('customers'),
        'link_ready' => vlLinkReady(),
    ]);
}

frRequirePost();
if (!vlLinkReady()) frJson(['success' => false, 'message' => t('The platform database has not been upgraded yet. Ask the administrator to run the platform setup.')], 503);

$mode = (string)($_POST['mode'] ?? '');
$me = (int)$_SESSION['user_id'];
$who = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ('user #' . $me);

try {
    if ($mode === 'link') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        $c = $cid > 0 ? $customerRow($cid) : null;
        if (!$c) frJson(['success' => false, 'message' => t('Customer not found')], 404);
    } elseif ($mode === 'create') {
        if (!canCreate('customers')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
        // The one place a platform company's customer is created (service_plans_plan.md
        // Phase 4): locked per company, so two clicks or two staff can never make two.
        // The person already saw the look-alike customers in the preview, hence force.
        $r = vlEnsureCustomerForTenant($pdo, (int)$tenant['id'], 'customer visit #' . $id, [
            'force'   => true,
            'user_id' => $me,
            'who'     => $who,
            'fields'  => [
                'customer_name'  => $_POST['customer_name'] ?? '',
                'company_name'   => $_POST['customer_name'] ?? '',
                'contact_person' => $_POST['contact_person'] ?? '',
                'phone'          => $_POST['phone'] ?? '',
                'email'          => $_POST['email'] ?? '',
                'address'        => $_POST['address'] ?? '',
                'postal_address' => $_POST['postal_address'] ?? '',
                'tin_number'     => $_POST['tin_number'] ?? '',
                'business'       => $_POST['business'] ?? '',
                'notes'          => 'From Customer Visit #' . $id . ' — ' . $tenant['subdomain'],
            ],
        ]);
        if (!in_array($r['state'], ['created', 'linked'], true)) {
            error_log("field_reports/make_customer: visit $id: {$r['state']} — {$r['message']}");
            frJson(['success' => false, 'message' => t('Server error')], 500);
        }
        $cid = (int)$r['customer_id'];
        $c = $customerRow($cid);
        if ($r['state'] === 'linked') {
            // Already a customer: link the visit only, never a second customer.
            $pdo->prepare("UPDATE field_visits SET customer_id = ?, updated_by = ? WHERE visit_id = ?")->execute([$cid, $me, $id]);
            frJson(['success' => true, 'customer_id' => $cid, 'customer_code' => $c['customer_code'], 'already' => true,
                    'message' => sprintf(t('Already a customer — %s. The visit is linked to it.'), $c['customer_code'])]);
        }
        logActivity($pdo, $me, 'Create customer', "Customer {$c['customer_code']} created from Customer Visit #$id ({$tenant['subdomain']})");
    } else {
        frJson(['success' => false, 'message' => t('Choose whether to create a new customer or link an existing one.')], 422);
    }

    if ($mode === 'link') {   // 'create' linked the company itself, under its lock
        $r = vlSetTenantCustomer((int)$tenant['id'], $cid, $who, $c['customer_code'] . ' — ' . $c['customer_name']);
        if (!$r['ok']) frJson(['success' => false, 'message' => $r['error']], 409);
    }
    $pdo->prepare("UPDATE field_visits SET customer_id = ?, updated_by = ? WHERE visit_id = ?")->execute([$cid, $me, $id]);
    vendorRequeueCustomer($pdo, $cid);   // paid invoices that were waiting for this link apply now
    logAudit($pdo, $me, 'vendor_customer_linked', [
        'entity_type' => 'customer', 'entity_id' => $cid,
        'new_values'  => ['tenant_id' => (int)$tenant['id'], 'subdomain' => $tenant['subdomain'], 'visit_id' => $id],
    ]);
    frJson(['success' => true, 'customer_id' => $cid, 'customer_code' => $c['customer_code'],
            'message' => sprintf(t('%s is now a customer, linked to %s.'), $c['customer_name'], $tenant['subdomain'])]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    frJson(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('field_reports/make_customer: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
