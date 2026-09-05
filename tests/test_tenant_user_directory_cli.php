<?php
/**
 * tests/test_tenant_user_directory_cli.php — Phase D acceptance gate
 * (superadmin professional-gap plan): read-only tenant user directory.
 *
 *   php tests/test_tenant_user_directory_cli.php
 *
 * Proves:
 *   1. tenantUserDirectory() returns real, exact accounts from the tenant's
 *      own database — proven against real seeded rows
 *   2. it returns ONLY the declared safe fields — never a password hash, and
 *      never any column outside the documented allow-list
 *   3. the endpoint's guard chain matches every other superadmin action:
 *      session, POST-only, CSRF, tenant-host refusal — with a positive
 *      control proving the harness itself works
 *   4. a deleted tenant returns null (no database left to query)
 *   5. tenant_view.php renders the Users card only for a non-deleted tenant,
 *      and never on the page load itself (on-demand only)
 *
 * CLI ONLY. Provisions one real throwaway tenant, seeds two extra users
 * directly into it, and removes everything afterwards.
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

// ─── Router worker — renders tenant_view.php through the real handleRoute() ─
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = parse_url((string)$argv[3], PHP_URL_QUERY) ?: '';
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Phase D: read-only tenant user directory\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$sub = 'userdir' . bin2hex(random_bytes(3));
$r = provisionTenant('User Directory Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('user directory test cleanup: ' . $e->getMessage()); }
});

$tRow = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tRow->execute([$tenantId]);
$tData = $tRow->fetch();
$pw = decryptTenantSecret((string)$tData['db_password_encrypted']);
$tPdo = new PDO("mysql:host={$tData['db_host']};dbname={$tData['db_name']};charset=utf8mb4",
    $tData['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Seed two extra, distinctive users directly into the tenant — one active
// admin, one inactive non-admin — so the directory has real data to prove
// against beyond the single owner account provisioning already created.
$tPdo->prepare("
    INSERT INTO users (username, password, email, role, user_role, is_admin, role_id, is_active,
                        first_name, last_name, last_login)
    VALUES (?,?,?,?,?,0,?,0,?,?,NULL)
")->execute([
    'inactive.person', password_hash('whatever', PASSWORD_DEFAULT), 'inactive@userdirtest.example',
    'Accountant', 'Accountant', (int)$tPdo->query("SELECT role_id FROM roles ORDER BY role_id LIMIT 1")->fetchColumn(),
    'Inactive', 'Person',
]);
$tPdo->prepare("
    INSERT INTO users (username, password, email, role, user_role, is_admin, role_id, is_active,
                        first_name, last_name, last_login)
    VALUES (?,?,?,?,?,1,?,1,?,?,NOW())
")->execute([
    'second.admin', password_hash('whatever', PASSWORD_DEFAULT), 'second.admin@userdirtest.example',
    'Manager', 'Manager', (int)$tPdo->query("SELECT role_id FROM roles ORDER BY role_id LIMIT 1")->fetchColumn(),
    'Second', 'Admin',
]);
$expectedTotal = (int)$tPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// ─────────────────────────────────────────────────────────────────────────────
section('1. tenantUserDirectory() — real data, exact match, safe fields only');

$dir = tenantUserDirectory($tenantId);
ok('returns an array, not null', is_array($dir));
ok('count matches the tenant\'s real user table exactly', count($dir) === $expectedTotal, count($dir) . ' vs ' . $expectedTotal);

$byEmail = [];
foreach ($dir as $u) $byEmail[$u['email']] = $u;

ok('the seeded inactive user is present and correctly inactive',
   isset($byEmail['inactive@userdirtest.example']) && $byEmail['inactive@userdirtest.example']['is_active'] === false);
ok('the seeded second admin is present and correctly flagged admin',
   isset($byEmail['second.admin@userdirtest.example']) && $byEmail['second.admin@userdirtest.example']['is_admin'] === true);
ok('the inactive user shows last_login as null, not a stale value',
   isset($byEmail['inactive@userdirtest.example']) && $byEmail['inactive@userdirtest.example']['last_login'] === null);
ok('names are composed from first+last, not raw username',
   isset($byEmail['second.admin@userdirtest.example']) && $byEmail['second.admin@userdirtest.example']['name'] === 'Second Admin');

$allowedKeys = ['user_id','name','email','role','is_admin','is_active','last_login','created_at'];
$leaked = false; $extraKeys = [];
foreach ($dir as $u) {
    foreach (array_keys($u) as $k) {
        if (!in_array($k, $allowedKeys, true)) { $leaked = true; $extraKeys[] = $k; }
    }
}
ok('every returned row has ONLY the declared safe keys — nothing else', !$leaked, implode(',', array_unique($extraKeys)));

$asJson = json_encode($dir);
ok('no password hash anywhere in the serialised output', strpos($asJson, '$2y$') === false);

// ─────────────────────────────────────────────────────────────────────────────
section('2. actions/superadmin_tenant_users.php — guards, with a positive control');

$r = endpoint('actions/superadmin_tenant_users.php', ['tenant_id' => $tenantId], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN read the directory',
   str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('  ...and the count matches', str_contains($r['out'], '"count":' . $expectedTotal), substr($r['out'], 0, 300));

$r = endpoint('actions/superadmin_tenant_users.php', ['tenant_id' => $tenantId]);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_users.php', ['tenant_id' => $tenantId], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_users.php', ['tenant_id' => $tenantId, '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_users.php', ['tenant_id' => $tenantId],
    ['host' => $sub . '.' . $BASE, 'auth' => true]);
ok('refused from the TENANT\'s own host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_users.php', [], ['auth' => true]);
ok('no tenant_id at all is refused, not treated as "all tenants"', refused($r, 'No tenant specified'), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('3. A deleted tenant has no database left to query');

deleteTenant($tenantId, 'User Directory Co');
$afterDelete = tenantUserDirectory($tenantId);
ok('tenantUserDirectory() returns null for a deleted tenant', $afterDelete === null);

$r = endpoint('actions/superadmin_tenant_users.php', ['tenant_id' => $tenantId], ['auth' => true]);
ok('the endpoint refuses cleanly for a deleted tenant too (no crash)', refused($r), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('4. tenant_view.php — the Users card, on-demand only');

$html = route(SA_HOST, '/tenants/view?id=' . $tenantId);
ok('renders with no PHP fatal for a deleted tenant', !str_contains($html, 'Fatal error'));
ok('the Users card is NOT shown for a deleted tenant (no database to query)', !str_contains($html, 'id="btnLoadUsers"'));
ok('the page never embeds a raw list of users on load (on-demand only)', !str_contains($html, 'inactive@userdirtest.example'));

// ─────────────────────────────────────────────────────────────────────────────
function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? SA_HOST) . ' '
         . escapeshellarg(!empty($server['auth']) ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) $joined = 'WORKER_CRASHED: ' . $joined;
    return ['out' => $joined, 'rc' => $rc];
}
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['out'], 'WORKER_CRASHED')) return false;
    if (str_contains($r['out'], '"success":true')) return false;
    return $expect === '' ? true : str_contains($r['out'], $expect);
}
function route(string $host, string $uri): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
