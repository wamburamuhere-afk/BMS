<?php
/**
 * api/my_subscription.php — the company's "Billing" page (customer_subscription_plan.md Phase 5,
 * service_plans_plan.md Phase 5b). Company admins only, tenant subdomains only.
 *
 *   GET  action=status     → access period, current plan + limits + extras, payments (control DB, own row only)
 *   GET  action=catalog    → plans / periods BJP sells                 (signed call to the platform root)
 *   GET  action=documents  → this company's approved quotations + invoices from BJP, requests
 *                            still waiting for BJP; marks them seen (the Billing badge)
 *   GET  action=badge      → how many documents became visible since last seen (cached 10 min)
 *   POST action=request    → product_id, term_id, extras{token: qty} → a pending quotation at BJP
 *   GET  action=contracts  → subscription contracts BJP has signed for this company
 *   POST action=contract_sign → contract_id → a one-time link to sign it
 *
 * The tenant id is always this server's own (bmsCurrentTenantId()), never from the request.
 * $GLOBALS['__pl_transport'] (PHP code only, never request input) lets tests answer in-process.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/platform_link.php';
require_once __DIR__ . '/../core/tenant_lifecycle_policy.php';
require_once __DIR__ . '/../core/plans.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = function (array $b, int $code = 200) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated()) $out(['success' => false, 'message' => 'Unauthorized'], 401);
// A decision about the company's own subscription — the same hard admin gate as Available Modules.
if (!isAdmin()) $out(['success' => false, 'message' => 'Permission denied'], 403);
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
if ($tenantId === null) $out(['success' => false, 'message' => 'Not available on this installation.'], 404);

$action = (string)($_REQUEST['action'] ?? '');

/** The plan this company is on: name, what it includes, limits (+ extras bought), a waiting change. */
function msPlanSummary(int $tenantId): ?array
{
    try {
        $c = getControlPdo();
        $cols = 'plan, max_users, max_shops'
              . (controlColumnExists('tenants', 'max_storage_mb') ? ', max_storage_mb' : ', NULL AS max_storage_mb')
              . (controlColumnExists('tenants', 'scheduled_plan_key') ? ', scheduled_plan_key, scheduled_plan_at' : ', NULL AS scheduled_plan_key, NULL AS scheduled_plan_at');
        $st = $c->prepare("SELECT $cols FROM tenants WHERE id = ?");
        $st->execute([$tenantId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $name = function (?string $key) use ($c) {
            if ($key === null || $key === '') return null;
            $s = $c->prepare("SELECT * FROM plans WHERE plan_key = ?");
            $s->execute([$key]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $cur = $name($t['plan']);
        $items = []; $modules = []; $product = null;
        if ($cur) {
            $s = $c->prepare("SELECT label, value FROM plan_items WHERE plan_id = ? ORDER BY sort_order, id");
            $s->execute([(int)$cur['id']]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
            if (function_exists('planOfferShape') && !empty($cur['product_key'])) {
                try { $modules = planOfferShape($cur)['modules']; } catch (Throwable $e) { $modules = []; }
                $p = $c->prepare("SELECT name FROM platform_products WHERE product_key = ?");
                $p->execute([$cur['product_key']]);
                $product = $p->fetchColumn() ?: null;
            }
        }
        $extra = fn(string $q) => function_exists('tenantAddonUnits') ? tenantAddonUnits($tenantId, $q) : 0;
        $next = $name($t['scheduled_plan_key']);
        return [
            'plan_key' => $t['plan'], 'name' => $cur['name'] ?? ($t['plan'] ? ucfirst((string)$t['plan']) : null), 'items' => $items,
            'product' => $product, 'tagline' => $cur['tagline'] ?? null, 'description' => $cur['description'] ?? null, 'modules' => $modules,
            'max_storage_mb' => $t['max_storage_mb'] !== null ? (int)$t['max_storage_mb'] : null,
            'max_users' => $t['max_users'] !== null ? (int)$t['max_users'] : null, 'extra_users' => $extra('max_users'),
            'max_shops' => $t['max_shops'] !== null ? (int)$t['max_shops'] : null, 'extra_shops' => $extra('max_shops'),
            'scheduled' => $t['scheduled_plan_key'] ? ['plan_key' => $t['scheduled_plan_key'], 'name' => $next['name'] ?? $t['scheduled_plan_key'], 'at' => $t['scheduled_plan_at']] : null,
        ];
    } catch (Throwable $e) {
        error_log('msPlanSummary: ' . $e->getMessage());
        return null;
    }
}
/**
 * The running period, in the words the page uses: kind = trial | paid | grace | no_end,
 * when it started (trial: sign-up; paid: the payment that set the current end date) and its length.
 */
function msPeriod(array $t, ?string $endsAt, ?int $daysLeft, array $payments): array
{
    $status = (string)$t['status'];
    if ($endsAt === null) return ['kind' => 'no_end', 'starts_at' => null, 'total_days' => null, 'used_days' => null];
    $kind = $daysLeft !== null && $daysLeft < 0 ? 'grace' : ($status === 'trial' ? 'trial' : 'paid');
    $start = null;
    if ($status === 'trial') {
        $start = !empty($t['created_at']) ? substr((string)$t['created_at'], 0, 10) : null;
    } else {
        $end = substr($endsAt, 0, 10);
        foreach ($payments as $p) if (substr((string)$p['ends_at'], 0, 10) === $end) { $start = substr((string)$p['starts_at'], 0, 10); break; }
        if ($start === null && $payments) $start = substr((string)$payments[0]['starts_at'], 0, 10);
    }
    $total = $used = null;
    if ($start !== null) {
        $s = strtotime($start); $e = strtotime(substr($endsAt, 0, 10));
        if ($s !== false && $e !== false && $e > $s) {
            $total = (int)round(($e - $s) / 86400);
            $used = max(0, min($total, (int)round((strtotime('today') - $s) / 86400)));
        } else {
            $start = null;
        }
    }
    return ['kind' => $kind, 'starts_at' => $start, 'total_days' => $total, 'used_days' => $used,
            'grace_until' => $kind === 'grace' && !empty($t['grace_until']) ? substr((string)$t['grace_until'], 0, 10) : null];
}
/** When the company last looked at its BJP documents (this company's own DB). */
function msSeenAt(PDO $pdo): ?string
{
    try { $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'billing_seen_at'")->fetchColumn(); return $v !== false && $v !== '' ? (string)$v : null; }
    catch (Throwable $e) { return null; }
}
function msCountNew(array $r, ?string $seen): int
{
    $n = 0;
    foreach (['quotations', 'invoices'] as $k) foreach ($r[$k] ?? [] as $d) {
        if (!empty($d['visible_at']) && ($seen === null || $d['visible_at'] > $seen)) $n++;
    }
    return $n;
}
$docUrl = function (?string $token): ?string {
    $root = plRootUrl();
    return ($token && $root) ? $root . '/api/platform/customer_document.php?t=' . rawurlencode($token) : null;
};

if ($action === 'status') {
    try {
        $st = getControlPdo()->prepare("SELECT company_name, subdomain, status, plan, trial_ends_at, subscription_ends_at,
                                               grace_until, billing_cycle, payment_status, created_at FROM tenants WHERE id = ?");
        $st->execute([$tenantId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) $out(['success' => false, 'message' => 'Company not found.'], 404);
        $pay = getControlPdo()->prepare("SELECT amount_tzs, duration_months, starts_at, ends_at, recorded_at" .
            (controlColumnExists('tenant_payments', 'source_ref') ? ", source, source_ref" : ", 'manual' AS source, NULL AS source_ref") .
            " FROM tenant_payments WHERE tenant_id = ? ORDER BY recorded_at DESC LIMIT 24");
        $pay->execute([$tenantId]);
        $payments = $pay->fetchAll(PDO::FETCH_ASSOC);
        $endsAt = tenantAccessEndsAt($t);
        $daysLeft = tenantDaysRemaining($t);
        $out(['success' => true,
            'tenant' => $t,
            'ends_at' => $endsAt,
            'days_left' => $daysLeft,
            'period' => msPeriod($t, $endsAt, $daysLeft, $payments),
            'plan' => msPlanSummary($tenantId),
            'payments' => $payments]);
    } catch (Throwable $e) {
        error_log('my_subscription status: ' . $e->getMessage());
        $out(['success' => false, 'message' => 'Could not load your subscription right now.'], 500);
    }
}

if ($action === 'catalog') {
    $r = plCall($tenantId, 'catalog', [], $GLOBALS['__pl_transport'] ?? null);
    $out($r, !empty($r['success']) ? 200 : 502);
}

$saveSeen = function (?string $at) use ($pdo) {
    if ($at === null) return;
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('billing_seen_at', ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")->execute([$at, $at]);
    } catch (Throwable $e) { error_log('billing_seen_at: ' . $e->getMessage()); }
    unset($_SESSION['billing_badge']);
};

if ($action === 'documents') {
    $r = plCall($tenantId, 'documents', [], $GLOBALS['__pl_transport'] ?? null);
    if (!empty($r['success'])) {
        $seen = msSeenAt($pdo);
        foreach (['quotations', 'invoices'] as $k) {
            foreach ($r[$k] ?? [] as $i => $d) {
                $r[$k][$i]['view_url'] = $docUrl($d['view_token'] ?? null);
                $r[$k][$i]['is_new'] = !empty($d['visible_at']) && $seen !== null && $d['visible_at'] > $seen;
                unset($r[$k][$i]['view_token']);
            }
        }
        // Opening Billing = the company has seen them; the header badge clears.
        if (!empty($r['latest']) && ($seen === null || $r['latest'] > $seen)) $saveSeen((string)$r['latest']);
    }
    $out($r, !empty($r['success']) ? 200 : 502);
}

if ($action === 'badge') {
    // At most one call to BJP per 10 minutes per session — the header asks after the page has loaded.
    $cache = $_SESSION['billing_badge'] ?? null;
    if (is_array($cache) && ($cache['at'] ?? 0) > time() - 600) $out(['success' => true, 'new' => (int)$cache['n']]);
    $r = plCall($tenantId, 'documents', [], $GLOBALS['__pl_transport'] ?? null);
    if (empty($r['success'])) $out(['success' => false, 'new' => 0], 200);
    $seen = msSeenAt($pdo);
    if ($seen === null) {
        // First time: everything already there counts as seen (no badge for old history).
        $saveSeen($r['latest'] ?? null);
        $n = 0;
    } else {
        $n = msCountNew($r, $seen);
    }
    $_SESSION['billing_badge'] = ['at' => time(), 'n' => $n];
    $out(['success' => true, 'new' => $n]);
}

if ($action === 'contracts') {
    $r = plCall($tenantId, 'contracts', [], $GLOBALS['__pl_transport'] ?? null);
    if (!empty($r['success'])) {
        foreach ($r['contracts'] ?? [] as $i => $c) {
            $r['contracts'][$i]['view_url'] = $docUrl($c['view_token'] ?? null);
            unset($r['contracts'][$i]['view_token']);
        }
    }
    $out($r, !empty($r['success']) ? 200 : 502);
}

if ($action === 'contract_sign') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => 'Method not allowed'], 405);
    csrf_check();
    $r = plCall($tenantId, 'contract_sign_link', ['contract_id' => (int)($_POST['contract_id'] ?? 0)], $GLOBALS['__pl_transport'] ?? null);
    if (!empty($r['success'])) logActivity($pdo, (int)$_SESSION['user_id'], 'Contract signing', 'Opened the BJP subscription contract for signing');
    $out($r, !empty($r['success']) ? 200 : 422);
}

if ($action === 'request') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'message' => 'Method not allowed'], 405);
    csrf_check();
    $pid = (int)($_POST['product_id'] ?? 0);
    // A local term id, or a plan token "p12" (BJP checks it against Superadmin's plans).
    $rawTerm = trim((string)($_POST['term_id'] ?? ''));
    $tid = preg_match('/^p\d+$/', $rawTerm) ? $rawTerm : (int)$rawTerm;
    if ($pid <= 0 || (is_int($tid) && $tid <= 0)) $out(['success' => false, 'message' => 'Choose a subscription period.'], 422);
    // Extras: {"a3-12": 2} — only well-formed tokens and whole numbers go on; BJP prices and checks them.
    $extras = [];
    $rawExtras = $_POST['extras'] ?? [];
    if (is_string($rawExtras)) $rawExtras = json_decode($rawExtras, true) ?: [];
    foreach (is_array($rawExtras) ? $rawExtras : [] as $tok => $qty) {
        if (!preg_match('/^a\d+-\d+$/', (string)$tok) || !preg_match('/^\d{1,2}$/', (string)$qty)) $out(['success' => false, 'message' => 'Choose a whole number of extras (0 to 99).'], 422);
        if ((int)$qty > 0) $extras[(string)$tok] = (int)$qty;
    }
    if ($extras && !is_string($tid)) $out(['success' => false, 'message' => 'Extras are sold only with a plan.'], 422);
    $r = plCall($tenantId, 'request_quote', ['product_id' => $pid, 'term_id' => $tid] + ($extras ? ['extras' => $extras] : []), $GLOBALS['__pl_transport'] ?? null);
    if (!empty($r['success'])) {
        logActivity($pdo, (int)$_SESSION['user_id'], 'Subscription request', "Requested {$r['term_label']} — BJP request " . ($r['request_number'] ?? $r['quotation_number'] ?? '') . ' (waiting for BJP)');
    }
    $out($r, !empty($r['success']) ? 200 : 422);
}

$out(['success' => false, 'message' => 'Unknown action'], 422);
