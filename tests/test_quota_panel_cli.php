<?php
/**
 * tests/test_quota_panel_cli.php — Phase 12.C acceptance gate.
 *
 *   php tests/test_quota_panel_cli.php
 *
 * Proves the superadmin's control surface for quotas:
 *   1. setTenantQuotas() writes correctly, validates, and logs only real changes
 *   2. the endpoints refuse without a session, on GET, without CSRF, and from
 *      a TENANT host — with a positive control proving the harness itself works
 *   3. tenant_view.php renders the Usage & Limits card, pre-filled correctly
 *   4. THE NARROW EXCEPTION: tenantUsageSnapshotFor() / the usage endpoint
 *      return real, exact numbers from the tenant's own database — proven
 *      against real seeded rows — and return ONLY the declared numeric keys,
 *      never a row, a name, or any other business content
 *   5. a superadmin_tenant_quotas.php write is completely inert to
 *      superadmin_tenant_usage.php and vice versa — the two endpoints that
 *      cross very different trust boundaries stay genuinely independent
 *
 * CLI ONLY. Provisions one real throwaway tenant and removes it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Endpoint worker — runs a real action file with real $_SERVER/$_POST ───
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $method = (string)($argv[4] ?? 'POST');
    $host   = (string)($argv[5] ?? 'localhost');
    $auth   = (string)($argv[6] ?? '0') === '1';

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/' . $file;

    if ($auth) {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }

    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_quotas.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Phase 12.C: superadmin usage & limits panel\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$sub = 'quotapanel' . bin2hex(random_bytes(3));
$r = provisionTenant('Quota Panel Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];
$host     = $sub . '.' . $BASE;

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('quota panel test cleanup: ' . $e->getMessage()); }
});

$tStmt = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tStmt->execute([$tenantId]);
$tRow = $tStmt->fetch();
$pw   = decryptTenantSecret((string)$tRow['db_password_encrypted']);
$pdo  = new PDO("mysql:host={$tRow['db_host']};dbname={$tRow['db_name']};charset=utf8mb4",
    $tRow['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? SA_HOST) . ' '
         . escapeshellarg(!empty($server['auth']) ? '1' : '0');
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) {
        $joined = 'WORKER_CRASHED: ' . $joined;
    }
    return ['out' => $joined, 'rc' => $rc];
}
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['out'], 'WORKER_CRASHED')) return false;
    if (str_contains($r['out'], '"success":true')) return false;
    return $expect === '' ? true : str_contains($r['out'], $expect);
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. setTenantQuotas() — writes, validates, logs only real changes');

$before = (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE action='update_quotas'")->fetchColumn();

$r1 = setTenantQuotas($tenantId, 5, 200);
ok('sets both limits', $r1['ok'] === true && $r1['changed'] === true, (string)($r1['error'] ?? ''));

$live = getTenant($tenantId);
ok('max_users persisted', (int)$live['max_users'] === 5);
ok('max_storage_mb persisted', (int)$live['max_storage_mb'] === 200);
ok('one audit row written', (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE action='update_quotas'")->fetchColumn() === $before + 1);

$r2 = setTenantQuotas($tenantId, 5, 200);   // identical values again
ok('saving identical values reports no change', $r2['changed'] === false);
ok('  ...and writes no extra audit row', (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE action='update_quotas'")->fetchColumn() === $before + 1);

$r3 = setTenantQuotas($tenantId, 0, null);
ok('a user limit of 0 is rejected (must be >=1 or unlimited)', $r3['ok'] === false);

$r4 = setTenantQuotas($tenantId, null, null);
ok('clearing both back to unlimited works', $r4['ok'] === true && $r4['changed'] === true);
$live = getTenant($tenantId);
ok('both are NULL again', $live['max_users'] === null && $live['max_storage_mb'] === null);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Endpoint guards — positive control first, then every refusal');

$r = endpoint('actions/superadmin_tenant_quotas.php',
    ['tenant_id' => $tenantId, 'max_users' => '7', 'max_storage_mb' => '150'], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN save through the endpoint',
   str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
$live = getTenant($tenantId);
ok('  ...and it really persisted', (int)$live['max_users'] === 7 && (int)$live['max_storage_mb'] === 150);
setTenantQuotas($tenantId, null, null);   // reset for the rest of the suite

$r = endpoint('actions/superadmin_tenant_quotas.php', ['tenant_id' => $tenantId, 'max_users' => '3']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_quotas.php', ['tenant_id' => $tenantId], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_quotas.php',
    ['tenant_id' => $tenantId, 'max_users' => '3', '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_quotas.php',
    ['tenant_id' => $tenantId, 'max_users' => '3'], ['host' => $host, 'auth' => true]);
ok('refused from a TENANT host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_usage.php', ['tenant_id' => $tenantId], ['host' => $host, 'auth' => true]);
ok('the usage endpoint is refused from a TENANT host too', refused($r), substr($r['out'], 0, 200));

$live = getTenant($tenantId);
ok('none of the refused calls changed anything', $live['max_users'] === null && $live['max_storage_mb'] === null);

// ─────────────────────────────────────────────────────────────────────────────
section('3. tenant_view.php renders the card, pre-filled correctly');

$_SERVER['HTTP_HOST'] = SA_HOST; $_GET['id'] = $tenantId;
superadminSessionReady();
$row = $c->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
$_SESSION['superadmin_id'] = (int)$row['id'];
setTenantQuotas($tenantId, 12, 500);
ob_start();
require __DIR__ . '/../app/superadmin/tenant_view.php';
$html = ob_get_clean();

ok('the page renders without a PHP error', !str_contains($html, 'Fatal error') && !str_contains($html, 'Warning:'));
ok('the Usage & Limits card is present', str_contains($html, 'Usage &amp; Limits') || str_contains($html, 'Usage &'));
ok('the max-users input is pre-filled with 12', (bool)preg_match('/id="f-max-users"[^>]*value="12"/', $html));
ok('the max-storage input is pre-filled with 500', (bool)preg_match('/id="f-max-storage"[^>]*value="500"/', $html));
ok('"Check current usage" button is present', str_contains($html, 'checkUsage()'));
setTenantQuotas($tenantId, null, null);

// ─────────────────────────────────────────────────────────────────────────────
section('4. THE NARROW EXCEPTION — real numbers, and ONLY numbers');

$pdo->exec("INSERT INTO users (username, email, password, first_name, last_name, is_active) VALUES ('qp2','qp2@t.test','x','Q','2',1)");
$pdo->exec("INSERT INTO users (username, email, password, first_name, last_name, is_active) VALUES ('qp3-inactive','qp3@t.test','x','Q','3',0)");
$pdo->exec("INSERT INTO documents (document_name, file_path, original_filename, file_size, file_type, uploaded_by)
            VALUES ('secret business document', 'uploads/secret.pdf', 'secret.pdf', 2097152, 'pdf', 1)");

$snap = tenantUsageSnapshotFor($tenantId);
ok('tenantUsageSnapshotFor() returns a result', is_array($snap));
ok('active_users is exactly right (owner + qp2, NOT the inactive one)', ($snap['active_users'] ?? null) === 2, json_encode($snap));
ok('storage_used_bytes is exactly right (2097152 bytes seeded)', ($snap['storage_used_bytes'] ?? null) === 2097152, json_encode($snap));

$r = endpoint('actions/superadmin_tenant_usage.php', ['tenant_id' => $tenantId], ['auth' => true]);
ok('the usage endpoint reports the same active_users', str_contains($r['out'], '"active_users":2'), substr($r['out'], 0, 300));
ok('  ...and the same storage in MB (2097152 / 1048576 = 2.0)', str_contains($r['out'], '"storage_used_mb":2'), substr($r['out'], 0, 300));

// The property that makes this a NARROW exception: only the declared numeric
// keys ever leave the endpoint. No document name, no filename, no row content.
$jsonStart = strpos($r['out'], '{');
$body = $jsonStart !== false ? json_decode(substr($r['out'], $jsonStart), true) : null;
ok('the response is valid JSON with exactly the declared keys', is_array($body)
   && array_keys($body) === ['success', 'active_users', 'storage_used_bytes', 'storage_used_mb'],
   json_encode($body));
ok('  ...the word "secret" never appears anywhere in the response', !str_contains($r['out'], 'secret'));
ok('  ...no filename ever appears in the response', !str_contains($r['out'], '.pdf'));

// A tenant mid-deletion must not be reachable by this either.
$snapNone = tenantUsageSnapshotFor(999999999);
ok('an unknown tenant id returns null, not an error', $snapNone === null);

// ─────────────────────────────────────────────────────────────────────────────
section('5. The two endpoints are genuinely independent');

setTenantQuotas($tenantId, 9, null);
$beforeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
endpoint('actions/superadmin_tenant_usage.php', ['tenant_id' => $tenantId], ['auth' => true]);
$live = getTenant($tenantId);
ok('reading usage does not change the stored limits', (int)$live['max_users'] === 9 && $live['max_storage_mb'] === null);
$afterUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
ok('reading usage does not change the tenant\'s own data', $afterUsers === $beforeUsers);

setTenantQuotas($tenantId, null, null);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
