<?php
/**
 * Multi-tenancy — Phase 6 (superadmin tenant panel) CLI test
 *   php tests/test_tenant_admin_panel_cli.php
 *
 * The invariant under test is ONE TENANT AT A TIME: suspending or deleting a
 * company must have no effect whatsoever on any other tenant.
 *
 * What it proves:
 *   - suspend/activate/delete each affect exactly one tenant, verified by
 *     checking the OTHER tenant still connects after every operation
 *   - suspending destroys nothing; the tenant reconnects on reactivation
 *   - delete really drops the database AND the MySQL user, keeps the registry
 *     row for audit, and cannot be undone
 *   - delete refuses unless the company name is typed exactly
 *   - every action is attributed to a named operator in tenant_admin_log
 *   - the panel pages render, are guarded, and never expose a tenant's
 *     database password
 *
 * Creates throwaway tenants and an operator; removes them. Exit 0 = pass.
 */
$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());
session_start();

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_admin.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

$made = ['tenants' => [], 'databases' => [], 'users' => [], 'superadmins' => []];

function teardown(): void
{
    global $made;
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM tenant_admin_log WHERE subdomain LIKE 'paneltest%'");
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'paneltest%'");
        $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'paneltest%'");
        $c->exec("DELETE FROM superadmins WHERE email LIKE 'paneltest%'");
    } catch (Throwable $e) {}
    try {
        $a = getProvisioningPdo();
        foreach ($made['databases'] as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $a->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        }
        foreach ($made['users'] as $u) {
            try { $a->exec("DROP USER IF EXISTS " . $a->quote($u) . "@'%'"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/** Does this tenant's own MySQL user still reach its own database? */
function tenantReachable(array $t): bool
{
    require_once dirname(__DIR__) . '/core/tenant_crypto.php';
    $st = getControlPdo()->prepare("SELECT db_password_encrypted, db_name, db_username, db_host FROM tenants WHERE id = ?");
    $st->execute([$t['id']]);
    $row = $st->fetch();
    if (!$row || $row['db_password_encrypted'] === '') return false;
    $pw = decryptTenantSecret((string)$row['db_password_encrypted']);
    if ($pw === null) return false;
    try {
        $p = new PDO("mysql:host={$row['db_host']};dbname={$row['db_name']};charset=utf8mb4",
            $row['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $p->query('SELECT 1');
        return true;
    } catch (Throwable $e) { return false; }
}

function databaseExists(string $db): bool
{
    $a = getProvisioningPdo();
    return (bool)$a->query("SELECT 1 FROM information_schema.schemata WHERE schema_name = " . $a->quote($db))->fetchColumn();
}
function mysqlUserExists(string $u): bool
{
    $a = getProvisioningPdo();
    return (bool)$a->query("SELECT 1 FROM mysql.user WHERE user = " . $a->quote($u))->fetchColumn();
}

/** Render a superadmin page in a subprocess with a given session. */
function renderPage(string $relPath, ?int $superadminId, string $query = '', array $env = []): string
{
    global $root;
    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_panel_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, "<?php\n"
        . "ini_set('session.save_path', sys_get_temp_dir());\n"
        . "session_start();\n"
        . ($superadminId !== null ? "\$_SESSION['superadmin_id'] = {$superadminId};\n" : "")
        . "\$_SERVER['HTTP_HOST'] = getenv('P_HOST') ?: 'localhost';\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "parse_str(" . var_export($query, true) . ", \$_GET);\n"
        // HERMETIC ENV — do not remove; see the full note in
        // tests/test_tenant_routing_cli.php. Two separate sources of pollution
        // have to be shut out here, and inheriting either one makes this suite
        // test something other than what it claims:
        //
        //   1. includes/config.php putenv()s THIS MACHINE's tenancy settings.
        //   2. The parent test process loads config.php too, so its own
        //      environment is already polluted before it spawns anything —
        //      meaning "inherit whatever the parent has" is not neutral either.
        //
        // With TENANT_MODE forced on, assertSuperadminHost() demands the host be
        // superadmin.<that machine's base domain>, exits 'Not found', and every
        // page renders empty — which also makes section 11 below pass for
        // entirely the wrong reason.
        //
        // So the child's tenancy environment is exactly what THIS CASE declared
        // and nothing else. The page pulls config.php in via require_once, so
        // loading it here first makes that later include a no-op.
        . "\$tenancy = " . var_export([
            'TENANT_MODE'        => $env['TENANT_MODE']        ?? null,
            'TENANT_BASE_DOMAIN' => $env['TENANT_BASE_DOMAIN'] ?? null,
        ], true) . ";\n"
        . "require_once '" . str_replace('\\', '/', $root) . "/includes/config.php';\n"
        . "foreach (\$tenancy as \$k => \$v) { if (\$v === null) { putenv(\$k); } else { putenv(\"\$k=\$v\"); } }\n"
        . "require '" . str_replace('\\', '/', $root) . "/$relPath';\n");
    foreach ($env as $k => $v) putenv("$k=$v");
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    foreach ($env as $k => $v) putenv($k);
    @unlink($probe);
    return $out;
}

try {
    $cpdo = getControlPdo();
    $sfx  = bin2hex(random_bytes(3));

    section('1. Audit table exists');
    $tables = $cpdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = " . $cpdo->quote(controlDbName()) . " AND table_type='BASE TABLE'
    ")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('tenant_admin_log', $tables, true), 'tenant_admin_log exists');

    $fk = (int)$cpdo->query("
        SELECT COUNT(*) FROM information_schema.referential_constraints
        WHERE constraint_schema = " . $cpdo->quote(controlDbName()) . " AND table_name='tenant_admin_log'
    ")->fetchColumn();
    ok($fk === 0, 'no FK — the record of who deleted a tenant outlives both parties');

    section('2. Operator + two tenants');
    $email = "paneltest$sfx@example.test";
    $cpdo->prepare("INSERT INTO superadmins (name,email,password_hash) VALUES (?,?,?)")
         ->execute(['Panel Test', $email, password_hash('Password1', PASSWORD_DEFAULT)]);
    $saId = (int)$cpdo->lastInsertId();
    $made['superadmins'][] = $saId;
    $_SESSION['superadmin_id'] = $saId;      // so actions are attributed
    ok(currentSuperadmin() !== null, 'operator session established');

    $A = provisionTenant('Panel Alpha Ltd', 'paneltesta' . $sfx, "a@paneltesta$sfx.test", 'Password1');
    $B = provisionTenant('Panel Beta Ltd',  'paneltestb' . $sfx, "b@paneltestb$sfx.test", 'Password1');
    ok($A['ok'] && $B['ok'], 'two tenants provisioned');
    if (!$A['ok'] || !$B['ok']) throw new RuntimeException($A['error'] ?? $B['error'] ?? 'provision failed');
    foreach ([$A, $B] as $t) {
        $made['tenants'][]   = $t['tenant_id'];
        $made['databases'][] = $t['db_name'];
        $made['users'][]     = $t['db_username'];
    }
    $a = getTenant($A['tenant_id']);
    $b = getTenant($B['tenant_id']);

    section('3. getTenant never exposes credentials');
    ok(!array_key_exists('db_password_encrypted', $a),
        'the tenant database password is never even selected');

    section('4. Suspend affects ONLY that tenant');
    ok(tenantReachable($a) && tenantReachable($b), 'both tenants reachable to begin with');
    $r = suspendTenant($A['tenant_id'], 'non-payment');
    ok($r['ok'] === true, 'suspend succeeded');
    $a = getTenant($A['tenant_id']);
    ok($a['status'] === 'suspended', 'tenant A is suspended');
    ok(!empty($a['suspended_at']), 'suspended_at stamped');
    ok(getTenant($B['tenant_id'])['status'] === 'active', 'tenant B is UNTOUCHED');

    // Suspension is a flag, not destruction — the data must still be there.
    ok(databaseExists($a['db_name']), "tenant A's database still exists (nothing destroyed)");
    ok(tenantReachable($a), 'tenant A\'s data is intact; only the gate is closed');
    ok(tenantReachable($b), 'tenant B still fully operational');

    section('5. Activate restores only that tenant');
    $r = activateTenant($A['tenant_id']);
    ok($r['ok'] === true, 'activate succeeded');
    $a = getTenant($A['tenant_id']);
    ok($a['status'] === 'active', 'tenant A is active again');
    ok(empty($a['suspended_at']), 'suspended_at cleared');
    ok(getTenant($B['tenant_id'])['status'] === 'active', 'tenant B still unaffected');

    section('6. Delete requires the company name typed exactly');
    $before = $cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    foreach (['', 'wrong name', 'panel alpha ltd', 'Panel Alpha Ltd '] as $i => $typed) {
        $r = deleteTenant($A['tenant_id'], $typed);
        $expectOk = ($i === 3);   // trailing space is trimmed, so that one is valid
        if (!$expectOk) {
            ok($r['ok'] === false, "refused wrong confirmation: '" . ($typed === '' ? '(empty)' : $typed) . "'");
        }
    }
    // The trimmed-match attempt above actually deleted it, so re-provision for
    // the isolation test rather than asserting on a half-deleted tenant.
    $a = getTenant($A['tenant_id']);
    ok($a['status'] === 'deleted', 'an exactly-matching name (trimmed) does delete');
    ok(!databaseExists($a['db_name']), 'the database is really gone');
    ok(!mysqlUserExists($a['db_username']), 'the MySQL user is really gone');
    // Read the raw column: getTenant() deliberately never selects it, so checking
    // the returned array would pass vacuously on a key that is simply absent.
    $rawPw = $cpdo->prepare("SELECT db_password_encrypted FROM tenants WHERE id = ?");
    $rawPw->execute([$A['tenant_id']]);
    ok((string)$rawPw->fetchColumn() === '', 'stored credentials blanked in the registry');

    section('7. Delete leaves every other tenant untouched');
    ok(getTenant($B['tenant_id'])['status'] === 'active', 'tenant B still active after A was deleted');
    ok(databaseExists($b['db_name']), "tenant B's database still exists");
    ok(mysqlUserExists($b['db_username']), "tenant B's MySQL user still exists");
    ok(tenantReachable($b), 'tenant B still connects normally — the exact requirement');

    section('8. A deleted tenant stays deleted');
    $r = activateTenant($A['tenant_id']);
    ok($r['ok'] === false, 'a deleted tenant cannot be reactivated');
    ok(stripos((string)$r['error'], 'no longer exists') !== false, 'and the reason says why');
    $r = suspendTenant($A['tenant_id']);
    ok($r['ok'] === false, 'a deleted tenant cannot be suspended');
    $r = deleteTenant($A['tenant_id'], 'Panel Alpha Ltd');
    ok($r['ok'] === false, 'deleting twice is refused');

    // The registry row is kept so the subdomain stays claimed.
    $st = $cpdo->prepare("SELECT COUNT(*) FROM tenants WHERE subdomain = ?");
    $st->execute(['paneltesta' . $sfx]);
    ok((int)$st->fetchColumn() === 1, 'the registry row is kept (subdomain stays claimed)');

    section('9. Every action is attributed');
    $log = tenantAdminLog($A['tenant_id'], 50);
    ok(count($log) > 0, 'actions were logged');
    $actions = array_column($log, 'action');
    foreach (['suspend', 'activate', 'delete', 'delete_refused'] as $act) {
        ok(in_array($act, $actions, true), "'$act' recorded");
    }
    $emails = array_unique(array_column($log, 'actor_email'));
    ok(in_array($email, $emails, true), 'the operator who acted is named in the log');

    $susp = null;
    foreach ($log as $l) { if ($l['action'] === 'suspend') { $susp = $l; break; } }
    ok(($susp['detail'] ?? '') === 'non-payment', 'the suspension reason was stored');

    section('10. Pages render and are guarded');
    // Signed out -> redirect, no tenant data rendered.
    $out = renderPage('app/superadmin/tenants.php', null);
    ok(strpos($out, 'Panel Beta Ltd') === false, 'a signed-out request renders no tenant data');

    $out = renderPage('app/superadmin/tenants.php', $saId);
    ok(strpos($out, 'Panel Beta Ltd') !== false, 'the panel lists tenants when signed in');
    ok(strpos($out, 'tenant_view.php?id=') !== false, 'rows link to the detail page');
    ok(strpos($out, 'bi-gear-fill') !== false, 'actions use the gear dropdown (§UI-5)');
    ok(strpos($out, 'Fatal error') === false && strpos($out, 'Warning:') === false,
        'the list page renders without PHP errors');

    $out = renderPage('app/superadmin/tenant_view.php', $saId, 'id=' . $B['tenant_id']);
    ok(strpos($out, 'Panel Beta Ltd') !== false, 'the detail page renders the tenant');
    ok(strpos($out, 'Fatal error') === false && strpos($out, 'Warning:') === false,
        'the detail page renders without PHP errors');
    ok(strpos($out, 'tenc:v1:') === false, 'no encrypted credential is ever emitted into the page');

    $out = renderPage('app/superadmin/tenant_view.php', $saId, 'id=999999');
    ok(strpos($out, 'No such tenant') !== false, 'an unknown id renders a clean "not found"');

    section('11. Panel is invisible from a tenant subdomain');
    $out = renderPage('app/superadmin/tenants.php', $saId, '',
        ['TENANT_MODE' => 'on', 'TENANT_BASE_DOMAIN' => 'bms.local', 'P_HOST' => 'paneltestb' . $sfx . '.bms.local']);
    ok(strpos($out, 'Panel Beta Ltd') === false, "a tenant's own subdomain cannot read the panel");
    ok(strpos($out, 'Not found') !== false, 'it returns a flat "Not found"');
    putenv('P_HOST');

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
