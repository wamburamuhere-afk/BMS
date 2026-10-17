<?php
/**
 * api/my_subscription.php — "My Subscription" in a company's own system
 * (customer_subscription_plan.md Phase 5). Company admins only, tenant subdomains only.
 *
 *   GET  action=status     → this company's access period + payments (control DB, own row only)
 *   GET  action=catalog    → subscription periods BJP sells            (signed call to the platform root)
 *   GET  action=documents  → this company's quotations + invoices from BJP
 *   POST action=request    → product_id, term_id → an approved quotation at BJP
 *
 * The tenant id is always this server's own (bmsCurrentTenantId()), never from the request.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/platform_link.php';
require_once __DIR__ . '/../core/tenant_lifecycle_policy.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => 'Unauthorized'], 401);
// A decision about the company's own subscription — the same hard admin gate as Available Modules.
if (!isAdmin()) $out(['success' => false, 'message' => 'Permission denied'], 403);
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
if ($tenantId === null) $out(['success' => false, 'message' => 'Not available on this installation.'], 404);

$action = (string)($_REQUEST['action'] ?? '');
$docUrl = function (?string $token): ?string {
    $root = plRootUrl();
    return ($token && $root) ? $root . '/api/platform/customer_document.php?t=' . rawurlencode($token) : null;
};

if ($action === 'status') {
    try {
        $st = getControlPdo()->prepare("SELECT company_name, subdomain, status, plan, trial_ends_at, subscription_ends_at,
                                               grace_until, billing_cycle, payment_status FROM tenants WHERE id = ?");
        $st->execute([$tenantId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) $out(['success' => false, 'message' => 'Company not found.'], 404);
        $pay = getControlPdo()->prepare("SELECT amount_tzs, duration_months, starts_at, ends_at, recorded_at" .
            (controlColumnExists('tenant_payments', 'source_ref') ? ", source, source_ref" : ", 'manual' AS source, NULL AS source_ref") .
            " FROM tenant_payments WHERE tenant_id = ? ORDER BY recorded_at DESC LIMIT 24");
        $pay->execute([$tenantId]);
        $out(['success' => true,
            'tenant' => $t,
            'ends_at' => tenantAccessEndsAt($t + ['status' => $t['status']]),
            'days_left' => tenantDaysRemaining($t),
            'payments' => $pay->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        error_log('my_subscription status: ' . $e->getMessage());
        $out(['success' => false, 'message' => 'Could not load your subscription right now.'], 500);
    }
}

if ($action === 'catalog') {
    $r = plCall($tenantId, 'catalog');
    $out($r, !empty($r['success']) ? 200 : 502);
}

if ($action === 'documents') {
    $r = plCall($tenantId, 'documents');
    if (!empty($r['success'])) {
        foreach (['quotations', 'invoices'] as $k) {
            foreach ($r[$k] ?? [] as $i => $d) {
                $r[$k][$i]['view_url'] = $docUrl($d['view_token'] ?? null);
                unset($r[$k][$i]['view_token']);
            }
        }
    }
    $out($r, !empty($r['success']) ? 200 : 502);
}

if ($action === 'request') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => 'Method not allowed'], 405);
    csrf_check();
    $pid = (int)($_POST['product_id'] ?? 0);
    $tid = (int)($_POST['term_id'] ?? 0);
    if ($pid <= 0 || $tid <= 0) $out(['success' => false, 'message' => 'Choose a subscription period.'], 422);
    $r = plCall($tenantId, 'request_quote', ['product_id' => $pid, 'term_id' => $tid]);
    if (!empty($r['success'])) {
        $r['view_url'] = $docUrl($r['view_token'] ?? null);
        unset($r['view_token']);
        logActivity($pdo, (int)$_SESSION['user_id'], 'Subscription request', "Requested {$r['term_label']} — BJP quotation {$r['quotation_number']}");
    }
    $out($r, !empty($r['success']) ? 200 : 422);
}

$out(['success' => false, 'message' => 'Unknown action'], 422);
