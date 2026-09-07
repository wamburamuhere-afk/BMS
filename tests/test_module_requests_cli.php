<?php
/**
 * tests/test_module_requests_cli.php — Phase C acceptance gate
 * (tenant_module_control_plan.md): self-service module requests.
 *
 *   php tests/test_module_requests_cli.php
 *
 * Proves:
 *   1. listAvailableModulesForTenant() reports active/available/requires correctly
 *   2. createModuleRequest() — creates, is idempotent (updates, not duplicates),
 *      refuses a module the tenant already has
 *   3. decideModuleRequest() approve — grants the full dependency closure via
 *      the real setTenantFeatures(), notifies the tenant, cannot be redecided
 *   4. decideModuleRequest() decline — changes no entitlement, records why
 *   5. remindStalePendingRequests() — reminds an old pending request once,
 *      leaves a fresh one alone
 *   6. The real pages/endpoints (available_modules.php, the request API, the
 *      superadmin inbox + its approve/decline action) render/refuse correctly
 *
 * Creates one throwaway tenant; removes it. Exit 0 = pass.
 */
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $method = (string)($argv[4] ?? 'POST');
    $host   = (string)($argv[5] ?? 'localhost');
    $auth   = (string)($argv[6] ?? '0');   // '0' none, '1' superadmin, '2' tenant admin

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/' . $file;

    if ($auth === '1') {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    } elseif ($auth === '2') {
        ini_set('session.save_path', sys_get_temp_dir());
        session_start();
        $_SESSION['user_id'] = (int)($argv[7] ?? 0);
        $_SESSION['is_admin'] = true;
        $_SESSION['role'] = 'admin';
        require_once __DIR__ . '/../helpers.php';
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }

    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    if (($argv[4] ?? '') === 'superadmin') {
        require_once __DIR__ . '/../roots.php';
        require_once __DIR__ . '/../core/superadmin_auth.php';
        superadminSessionReady();
        $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    } else {
        ini_set('session.save_path', sys_get_temp_dir());
        session_start();
        $_SESSION['user_id']  = (int)($argv[5] ?? 0);
        $_SESSION['is_admin'] = true;   // fast path isAdmin() reads first — see core/permissions.php
        require_once __DIR__ . '/../roots.php';
    }

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/plans.php";
require_once "$root/core/module_requests.php";

$pass = 0; $fail = 0;
function ok(bool $cond, string $what, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✅\033[0m $what\n"; }
    else       { $fail++; echo "  \033[31m❌ $what\033[0m" . ($detail !== '' ? "\n     -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n\033[1m── $s ──\033[0m\n"; }

function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? 'localhost') . ' '
         . escapeshellarg((string)($server['auth'] ?? '0')) . ' '
         . escapeshellarg((string)($server['uid'] ?? '0'));
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) $joined = 'WORKER_CRASHED: ' . $joined;
    return ['out' => $joined, 'rc' => $rc];
}
function route(string $host, string $uri, string $mode = 'tenant', int $uid = 0): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri)
         . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg((string)$uid);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

$cpdo = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$sfx  = bin2hex(random_bytes(3));
$made = ['databases' => [], 'users' => []];

register_shutdown_function(function () use (&$made, $cpdo, $pass, $fail) {
    try {
        $cpdo->exec("DELETE FROM feature_upgrade_requests WHERE tenant_id IN (SELECT id FROM tenants WHERE subdomain LIKE 'modreqtest%')");
        $cpdo->exec("DELETE FROM tenant_features WHERE tenant_id IN (SELECT id FROM tenants WHERE subdomain LIKE 'modreqtest%')");
        $cpdo->exec("DELETE FROM tenant_admin_log WHERE subdomain LIKE 'modreqtest%'");
        $cpdo->exec("DELETE FROM tenants WHERE subdomain LIKE 'modreqtest%'");
    } catch (Throwable $e) {}
    try {
        $a = getProvisioningPdo();
        foreach ($made['databases'] as $db) { if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $a->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} } }
        foreach ($made['users'] as $u) { try { $a->exec("DROP USER IF EXISTS " . $a->quote($u) . "@'%'"); } catch (Throwable $e) {} }
    } catch (Throwable $e) {}
});

try {
    section('0. Fixture — a real throwaway tenant');
    $sub = 'modreqtest' . $sfx;
    $pr = provisionTenant('Module Request Test Co', $sub, "owner@$sub.test", 'Password1');
    ok($pr['ok'] === true, 'tenant provisioned', (string)($pr['error'] ?? ''));
    if (!$pr['ok']) throw new RuntimeException('cannot continue');
    $tenantId = (int)$pr['tenant_id'];
    $tRow = $cpdo->prepare("SELECT * FROM tenants WHERE id = ?"); $tRow->execute([$tenantId]); $T = $tRow->fetch();
    $made['databases'][] = $T['db_name'];
    $made['users'][]     = $T['db_username'];

    // Seed the notification event into this ONE tenant's own database, the
    // same effective state a tenant already caught up by
    // core/tenant_migration_runner.php would have — see migrations/tenant/
    // 2026_09_07_module_request_notification_event.php, not re-run here.
    require_once "$root/core/tenant_crypto.php";
    $tPdo = new PDO('mysql:host=' . $T['db_host'] . ';dbname=' . $T['db_name'] . ';charset=utf8mb4',
        $T['db_username'], decryptTenantSecret((string)$T['db_password_encrypted']),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tPdo->exec("
        INSERT IGNORE INTO notification_events
            (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
        VALUES ('module_request_decided', 'Module request approved or declined', 'test', 'Settings',
                'available_modules', 'view', 'high', 0, 1, NOW())
    ");
    $ownerId = (int)$tPdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();

    // Start this tenant on a known, non-default footing: only 'ai_assistant'
    // on, everything else off — so "active" / "available" / "requires" all
    // have something real to report.
    $sf = setTenantFeatures($tenantId, array_merge(array_fill_keys(allFeatureKeys(), false), ['ai_assistant' => true]));
    ok($sf['ok'] === true, 'fixture: tenant starts with only ai_assistant on');

    section('1. listAvailableModulesForTenant()');
    $modules = listAvailableModulesForTenant($tenantId);
    $byKey = [];
    foreach ($modules as $m) $byKey[$m['key']] = $m;

    ok($byKey['ai_assistant']['active'] === true, 'ai_assistant reports active');
    ok($byKey['ai_assistant']['requires'] === [], 'an active module has no "requires" (moot)');
    ok($byKey['sales']['active'] === false, 'sales reports available (not active)');
    ok($byKey['sales']['requires'] !== [] && $byKey['sales']['requires'][0]['key'] === 'warehouses',
       'sales correctly reports it requires warehouses');
    ok($byKey['sales']['pending'] === false, 'sales has no pending request yet');
    ok(!empty($byKey['projects']['requires']), 'projects reports missing dependencies too (procurement, transitively warehouses)');
    $projReqKeys = array_column($byKey['projects']['requires'], 'key');
    sort($projReqKeys);
    ok($projReqKeys === ['procurement', 'warehouses'], 'projects requires exactly [procurement, warehouses]');

    section('2. createModuleRequest()');
    $cr = createModuleRequest($tenantId, 'ai_assistant', $ownerId, 'test note');
    ok($cr['ok'] === false, 'requesting a module the tenant ALREADY has is refused');

    $cr = createModuleRequest($tenantId, 'projects', $ownerId, 'we need project tracking');
    ok($cr['ok'] === true, 'requesting projects succeeds', (string)($cr['error'] ?? ''));
    $reqId = (int)$cr['request_id'];
    ok($cr['already_updated'] === false, 'a brand-new request is not flagged as an update');

    $row = $cpdo->prepare("SELECT * FROM feature_upgrade_requests WHERE id = ?"); $row->execute([$reqId]);
    $reqRow = $row->fetch();
    ok($reqRow['status'] === 'pending', 'the row is pending');
    ok($reqRow['feature_key'] === 'projects', 'feature_key is the primary requested key');
    ok($reqRow['note'] === 'we need project tracking', 'the note is stored');

    $cr2 = createModuleRequest($tenantId, 'projects', $ownerId, 'updated note');
    ok($cr2['ok'] === true, 'a second request for the same module succeeds');
    ok($cr2['already_updated'] === true, 'it is flagged as an update, not a new request');
    ok((int)$cr2['request_id'] === $reqId, 'it updates the SAME row (no duplicate)');
    $countStmt = $cpdo->prepare("SELECT COUNT(*) FROM feature_upgrade_requests WHERE tenant_id = ? AND feature_key = 'projects'");
    $countStmt->execute([$tenantId]);
    ok((int)$countStmt->fetchColumn() === 1, 'exactly one row exists for (tenant, projects)');

    $modules2 = listAvailableModulesForTenant($tenantId);
    foreach ($modules2 as $m) if ($m['key'] === 'projects') $projectsAfterRequest = $m;
    ok($projectsAfterRequest['pending'] === true, 'the tenant-facing list now shows projects as pending');

    section('3. decideModuleRequest() — approve');
    $dr = decideModuleRequest($reqId, 1, true, null);
    ok($dr['ok'] === true, 'approval succeeds', (string)($dr['error'] ?? ''));
    ok($dr['features_changed'] === 3, 'exactly 3 features changed (projects + procurement + warehouses)');

    bmsPrimeTenantFeatures($tenantId);
    ok(tenantFeatureEnabled('projects') === true, 'projects is now ON');
    ok(tenantFeatureEnabled('procurement') === true, 'procurement was auto-granted too (dependency)');
    ok(tenantFeatureEnabled('warehouses') === true, 'warehouses was auto-granted too (transitive dependency)');
    ok(tenantFeatureEnabled('sales') === false, 'sales — unrelated — is still off');

    $row->execute([$reqId]); $reqRow = $row->fetch();
    ok($reqRow['status'] === 'approved', 'the request row is marked approved');
    ok((int)$reqRow['decided_by'] === 1, 'attributed to the deciding superadmin');
    ok($reqRow['decided_at'] !== null, 'decided_at is stamped');

    $notif = $tPdo->query("SELECT * FROM notifications ORDER BY notification_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($notif !== false && str_contains((string)$notif['title'], 'approved'), 'the tenant received an in-app notification of the approval');

    $log = $cpdo->prepare("SELECT * FROM tenant_admin_log WHERE tenant_id = ? AND action = 'module_request_approved'");
    $log->execute([$tenantId]);
    ok($log->fetch() !== false, 'the approval is recorded in tenant_admin_log');

    $redo = decideModuleRequest($reqId, 1, false, 'too late');
    ok($redo['ok'] === false, 'the same request cannot be decided twice');

    section('4. decideModuleRequest() — decline');
    $cr3 = createModuleRequest($tenantId, 'tenders', $ownerId, null);
    ok($cr3['ok'] === true, 'a second, independent request (tenders) is created');
    $reqId2 = (int)$cr3['request_id'];

    $dr2 = decideModuleRequest($reqId2, 1, false, 'not in your current agreement');
    ok($dr2['ok'] === true, 'decline succeeds', (string)($dr2['error'] ?? ''));
    ok($dr2['features_changed'] === 0, 'declining changes zero features');

    bmsPrimeTenantFeatures($tenantId);
    ok(tenantFeatureEnabled('tenders') === false, 'tenders stays off after a decline');

    $row->execute([$reqId2]); $reqRow2 = $row->fetch();
    ok($reqRow2['status'] === 'declined', 'the request row is marked declined');
    ok($reqRow2['decision_note'] === 'not in your current agreement', 'the decision reason is stored');

    section('5. remindStalePendingRequests()');
    $cr4 = createModuleRequest($tenantId, 'compliance', $ownerId, null);
    $staleId = (int)$cr4['request_id'];
    $cpdo->prepare("UPDATE feature_upgrade_requests SET created_at = NOW() - INTERVAL 72 HOUR WHERE id = ?")->execute([$staleId]);

    $cr5 = createModuleRequest($tenantId, 'crm', $ownerId, null);
    $freshId = (int)$cr5['request_id'];

    $rr = remindStalePendingRequests(48);
    ok($rr['reminded'] === 1, 'exactly one stale (72h-old) request is reminded, not the fresh one');

    $row->execute([$staleId]); $staleRow = $row->fetch();
    ok($staleRow['reminded_at'] !== null, 'the stale request now has reminded_at set');
    $row->execute([$freshId]); $freshRow = $row->fetch();
    ok($freshRow['reminded_at'] === null, 'the fresh request is untouched');

    $rr2 = remindStalePendingRequests(48);
    ok($rr2['reminded'] === 0, 'running it again reminds nobody a second time (reminded_at guards it)');

    // Clean up the two still-pending requests from this section so section 6's
    // rendering checks show a known state.
    $cpdo->prepare("DELETE FROM feature_upgrade_requests WHERE id IN (?,?)")->execute([$staleId, $freshId]);

    section('6. Real pages/endpoints');

    $htmlTenant = route($sub . '.' . $BASE, '/available_modules', 'tenant', $ownerId);
    ok(!str_contains($htmlTenant, 'Fatal error'), 'available_modules.php renders with no PHP fatal');
    ok(str_contains($htmlTenant, 'Available Modules'), 'shows the page title');
    ok(str_contains($htmlTenant, 'Included in your plan'), 'shows at least one active module');
    ok(str_contains($htmlTenant, 'Request this module'), 'shows a request button for an available module');

    $r = endpoint('api/request_module_access.php', ['feature_key' => 'sales'], ['auth' => '0']);
    ok(str_contains($r['out'], '"success":false') && str_contains($r['out'], 'Unauthorized'),
       'the request API refuses an unauthenticated caller', $r['out']);

    $r = endpoint('api/request_module_access.php', ['feature_key' => 'sales'], ['method' => 'GET', 'auth' => '2', 'uid' => $ownerId]);
    ok(str_contains($r['out'], '"success":false') && str_contains($r['out'], 'Method not allowed'),
       'the request API refuses GET', $r['out']);

    $SA_HOST = superadminHostLabel() . '.' . $BASE;
    $htmlInbox = route($SA_HOST, '/module-requests', 'superadmin');
    ok(!str_contains($htmlInbox, 'Fatal error'), 'module_requests.php (superadmin inbox) renders with no PHP fatal');
    ok(str_contains($htmlInbox, 'Module Requests'), 'shows the page title');

    $r = endpoint('actions/superadmin_module_requests.php', ['action' => 'approve', 'id' => '999999999'], ['auth' => '1', 'host' => $SA_HOST]);
    ok(str_contains($r['out'], '"success":false') && str_contains($r['out'], 'not found'),
       'approving a non-existent request id is refused cleanly', $r['out']);

    $r = endpoint('actions/superadmin_module_requests.php', ['action' => 'approve', 'id' => '1'], ['host' => $SA_HOST]);
    ok(str_contains($r['out'], '"success":false'), 'the superadmin action refuses without a session', $r['out']);

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
