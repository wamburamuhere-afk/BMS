<?php
/**
 * Multi-tenancy — Phase 3 (connection routing) CLI test
 *   php tests/test_tenant_routing_cli.php
 *
 * This is the phase that changes how every request picks a database, so the
 * tests run the real bootstrap in real subprocesses rather than asserting about
 * the source. Each subprocess gets a simulated HTTP_HOST and reports which
 * database it actually ended up connected to.
 *
 * What it proves:
 *   - with TENANT_MODE unset, NOTHING changes — the app connects to `bms`
 *   - each tenant's subdomain connects to that tenant's own database
 *   - an unknown subdomain 404s instead of quietly serving the main database
 *     (the failure that would hand one company's data to anyone guessing a host)
 *   - suspending one tenant blocks ONLY that tenant
 *   - a session carrying another tenant's id is destroyed, not honoured
 *   - the root domain and reserved labels stay on the main database
 *   - the hostname parser rejects IPs, multi-level hosts and foreign domains
 *
 * Provisions two real throwaway tenants and removes them afterwards.
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_resolver.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

const BASE = 'bms.local';

$created = ['tenants' => [], 'databases' => [], 'users' => []];
$probe   = null;

function teardown(): void
{
    global $created, $probe;
    if ($probe && is_file($probe)) @unlink($probe);
    try { $admin = getProvisioningPdo(); } catch (Throwable $e) { return; }
    foreach ($created['databases'] as $db) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
    }
    foreach ($created['users'] as $u) {
        try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($u) . "@'%'"); } catch (Throwable $e) {}
    }
    try {
        getControlPdo()->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'rtest%'");
        getControlPdo()->exec("DELETE FROM tenants WHERE subdomain LIKE 'rtest%'");
    } catch (Throwable $e) {}
}
register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/**
 * Run the real bootstrap in a subprocess with a simulated host, and report what
 * it did.
 *
 * The output is PARSED rather than position-matched: includes/config.php ends
 * with a closing `?>` followed by blank lines, so every response it takes part
 * in is prefixed with stray whitespace. (That is pre-existing, and the reason
 * the Phase 3 config.php snippet in the docs drops the closing tag.)
 *
 * @return array{db:?string, tenant:?string, raw:string}
 */
function runBootstrap(string $host, array $env = [], ?int $sessionTenantId = null): array
{
    global $probe;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($host)
         . ' ' . escapeshellarg((string)($sessionTenantId ?? ''));
    foreach ($env as $k => $v) { putenv("$k=$v"); }
    $raw = (string)shell_exec($cmd . ' 2>&1');
    foreach ($env as $k => $v) { putenv($k); }   // unset so cases cannot leak into each other

    $db = $tenant = null;
    if (preg_match('/DB:([A-Za-z0-9_]*)\|TENANT:([0-9-]*)/', $raw, $m)) {
        $db = $m[1]; $tenant = $m[2];
    }
    return ['db' => $db, 'tenant' => $tenant, 'raw' => $raw];
}

/** True if the subprocess connected to exactly this database. */
function connectedTo(array $r, string $db): bool { return $r['db'] === $db; }
/** True if the subprocess opened no database connection at all. */
function connectedToNothing(array $r): bool { return $r['db'] === null; }

try {
    // The probe is written at runtime so this suite stays self-contained and
    // leaves nothing in the repo (scratch/ is gitignored and would not ship).
    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_route_probe_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, <<<'PHP'
<?php
// Throwaway probe: exercises the REAL bootstrap with a simulated host.
$root = getenv('BMS_ROOT');
$_SERVER['HTTP_HOST'] = $argv[1] ?? '';

// The session must be opened BEFORE anything produces output — config.php ends
// with a closing PHP tag and blank lines, and once those are emitted
// session_start() refuses. This mirrors roots.php, which calls session_start()
// well before it requires config.php.
//
// (Note for future editors: never write a literal close-tag in this probe, not
// even inside a comment — it ends PHP mode mid-line and truncates the file.)
//
// The CLI php.ini here also points session.save_path at a directory that does not
// exist, so without an override the session silently fails to open and the guard
// under test would never run.
if (($argv[2] ?? '') !== '') {
    ini_set('session.save_path', sys_get_temp_dir());
    session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) { echo 'PROBE-ERROR:no-session'; exit; }
    $_SESSION['tenant_id'] = (int)$argv[2];
}

require $root . '/includes/config.php';          // defines DB_* (and its own legacy $pdo)
require $root . '/core/tenant_bootstrap.php';
$pdo = bmsConnectPdo();
echo 'DB:' . $pdo->query('SELECT DATABASE()')->fetchColumn();
echo '|TENANT:' . (bmsCurrentTenantId() ?? '-');
PHP
    );
    putenv('BMS_ROOT=' . $root);

    section('1. Hostname parsing (pure, no database)');
    $cases = [
        ['kampunia.bms.local', BASE, 'kampunia', 'a normal tenant host'],
        ['KampuniA.BMS.Local', BASE, 'kampunia', 'case is normalised'],
        ['kampunia.bms.local:8080', BASE, 'kampunia', 'a port is ignored'],
        ['bms.local', BASE, null, 'the root domain is not a tenant'],
        ['www.bms.local', BASE, null, 'www is reserved'],
        ['admin.bms.local', BASE, null, 'admin is reserved'],
        ['superadmin.bms.local', BASE, null, 'superadmin is reserved'],
        ['a.b.bms.local', BASE, null, 'multi-level hosts rejected (no masquerading)'],
        ['kampunia.evil.com', BASE, null, 'a foreign domain is not a tenant'],
        ['127.0.0.1', BASE, null, 'an IPv4 address is not a tenant'],
        ['192.168.1.10:8080', BASE, null, 'an IP with a port is not a tenant'],
        ['[::1]:8080', BASE, null, 'an IPv6 literal is not a tenant'],
        ['localhost', BASE, null, 'a bare hostname is not a tenant'],
        ['', BASE, null, 'an empty host is not a tenant'],
        [null, BASE, null, 'a null host is not a tenant'],
        ['kampunia.bms.local', null, null, 'no base domain configured -> never resolves'],
    ];
    foreach ($cases as [$host, $base, $want, $label]) {
        ok(extractTenantSubdomain($host, $base) === $want, $label);
    }

    section('2. Multi-tenancy is OFF unless explicitly enabled');
    putenv('TENANT_MODE');
    ok(tenantModeEnabled() === false, 'unset TENANT_MODE means disabled');
    foreach (['off','0','false','ON ','yes','enabled'] as $v) {
        putenv("TENANT_MODE=$v");
        $on = tenantModeEnabled();
        ok($v === 'ON ' ? $on === true : $on === false, "TENANT_MODE='$v' -> " . ($on ? 'on' : 'off'));
    }
    putenv('TENANT_MODE');
    ok(resolveTenantFromRequest('kampunia.bms.local')['status'] === 'disabled',
        'resolution short-circuits to "disabled" when off');

    section('3. Provision two real tenants');
    $sfx  = bin2hex(random_bytes(3));
    $subA = 'rtesta' . $sfx;
    $subB = 'rtestb' . $sfx;
    $a = provisionTenant('Routing Alpha Ltd', $subA, "owner@$subA.test", 'Password!123');
    $b = provisionTenant('Routing Beta Ltd',  $subB, "owner@$subB.test", 'Password!123');
    ok($a['ok'] && $b['ok'], 'both tenants provisioned');
    if (!$a['ok'] || !$b['ok']) { throw new RuntimeException($a['error'] ?? $b['error'] ?? 'provisioning failed'); }
    foreach ([$a, $b] as $t) {
        $created['tenants'][]   = $t['tenant_id'];
        $created['databases'][] = $t['db_name'];
        $created['users'][]     = $t['db_username'];
    }

    section('4. Tenant lookup');
    putenv('TENANT_MODE=on');
    putenv('TENANT_BASE_DOMAIN=' . BASE);
    $rA = resolveTenantFromRequest("$subA." . BASE);
    ok($rA['status'] === 'found', 'tenant A resolves');
    ok((int)$rA['tenant']['id'] === $a['tenant_id'], 'tenant A resolves to the RIGHT row');
    ok(resolveTenantFromRequest('nosuchtenant.' . BASE)['status'] === 'unknown',
        'an unknown subdomain is "unknown", NOT "none" (never falls through)');
    ok(resolveTenantFromRequest(BASE)['status'] === 'none', 'the root domain is "none"');
    ok(resolveTenantFromRequest('admin.' . BASE)['status'] === 'none', 'a reserved label is "none"');
    putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN');

    section('5. The real bootstrap, in subprocesses');
    $mt = ['TENANT_MODE' => 'on', 'TENANT_BASE_DOMAIN' => BASE];

    // The single most important assertion in this phase: deploying it with the
    // switch off must change nothing at all.
    $r = runBootstrap("$subA." . BASE, []);
    ok(connectedTo($r, DB_NAME),
        'TENANT_MODE unset -> still connects to `' . DB_NAME . '` (deploy is a no-op)');
    ok($r['tenant'] === '-', 'no tenant is attached when disabled');

    $r = runBootstrap("$subA." . BASE, $mt);
    ok(connectedTo($r, $a['db_name']), "tenant A's host connects to {$a['db_name']}");
    ok($r['tenant'] === (string)$a['tenant_id'], 'tenant A is attached to the request');

    $r = runBootstrap("$subB." . BASE, $mt);
    ok(connectedTo($r, $b['db_name']), "tenant B's host connects to {$b['db_name']}");

    $r = runBootstrap(BASE, $mt);
    ok(connectedTo($r, DB_NAME), 'the root domain uses the main database');

    $r = runBootstrap('admin.' . BASE, $mt);
    ok(connectedTo($r, DB_NAME), 'a reserved label uses the main database');

    $r = runBootstrap('nosuchtenant.' . BASE, $mt);
    ok(strpos($r['raw'], 'Account not found') !== false, 'an unknown subdomain returns "Account not found"');
    ok(connectedToNothing($r),
        'an unknown subdomain NEVER connects to the main database (the leak this prevents)');

    section('6. Suspending one tenant affects ONLY that tenant');
    $cpdo = getControlPdo();
    $cpdo->prepare("UPDATE tenants SET status='suspended', suspended_at=NOW() WHERE id=?")
         ->execute([$a['tenant_id']]);

    $r = runBootstrap("$subA." . BASE, $mt);
    ok(strpos($r['raw'], 'Account suspended') !== false, 'tenant A is blocked');
    ok(connectedToNothing($r), 'tenant A gets no database connection at all');

    $r = runBootstrap("$subB." . BASE, $mt);
    ok(connectedTo($r, $b['db_name']),
        'tenant B is COMPLETELY UNAFFECTED — the exact requirement');

    $r = runBootstrap(BASE, $mt);
    ok(connectedTo($r, DB_NAME), 'the main site is unaffected too');

    $cpdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$a['tenant_id']]);
    $r = runBootstrap("$subA." . BASE, $mt);
    ok(connectedTo($r, $a['db_name']), 'reactivating tenant A restores it immediately');

    section('7. Cross-tenant session guard');
    // A session cookie claiming tenant B, replayed against tenant A's hostname.
    $r = runBootstrap("$subA." . BASE, $mt, $b['tenant_id']);
    ok(strpos($r['raw'], 'PROBE-ERROR') === false, 'the probe really opened a session (not a vacuous pass)');
    ok(strpos($r['raw'], 'sign in again') !== false, 'a session from another tenant is refused');
    ok(connectedToNothing($r), "the mismatched session never reaches tenant A's data");

    $r = runBootstrap("$subA." . BASE, $mt, $a['tenant_id']);
    ok(strpos($r['raw'], 'PROBE-ERROR') === false, 'the probe really opened a session here too');
    ok(connectedTo($r, $a['db_name']), "tenant A's own session is accepted");

    section('8. A deleted tenant is gone, not fallen back');
    $cpdo->prepare("UPDATE tenants SET status='deleted' WHERE id=?")->execute([$b['tenant_id']]);
    $r = runBootstrap("$subB." . BASE, $mt);
    ok(strpos($r['raw'], 'Account closed') !== false, 'a deleted tenant reports closed');
    ok(connectedToNothing($r), 'a deleted tenant never connects anywhere');
    $cpdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$b['tenant_id']]);

    section('9. Nothing leaks into the response');
    $r = runBootstrap('nosuchtenant.' . BASE, $mt);
    ok(trim($r['raw']) !== '', 'the error page actually rendered (guards against vacuous passes)');
    foreach (['bms_u', 'password', 'PDO', 'SQLSTATE', 'mysql:host'] as $needle) {
        ok(stripos($r['raw'], $needle) === false, "the error page reveals no '$needle'");
    }

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
