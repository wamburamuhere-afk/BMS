<?php
/**
 * Tenant lifecycle — Phase 3 (archive / release / purge) CLI test
 *   php tests/test_tenant_lifecycle_cli.php
 *
 * Works against REAL provisioned tenants, because the whole point of these
 * operations is what they do to real databases and real registry rows.
 *
 * What it proves:
 *   - archiving locks a tenant out WITHOUT touching their database, and
 *     reactivating brings them straight back
 *   - archive, release and purge each demand the company name typed exactly,
 *     and a near miss changes nothing and is recorded
 *   - purge refuses anything whose database has not already been destroyed —
 *     there is no path through it that loses data
 *   - purge removes the registry row but the audit log survives, including an
 *     entry explaining where the row went
 *   - releasing an address frees the name for a new signup while keeping the
 *     old company's row and history
 *   - an address is NOT released by accident: it stays claimed until asked for
 *   - the 'archived' status locks the tenant out at the bootstrap gate
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);

ini_set('session.save_path', sys_get_temp_dir());
if (session_status() === PHP_SESSION_NONE) session_start();

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_admin.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$created = ['tenants' => [], 'databases' => [], 'users' => []];

function teardown(): void
{
    global $created;
    try { $admin = getProvisioningPdo(); } catch (Throwable $e) { $admin = null; }
    if ($admin) {
        foreach ($created['databases'] as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        }
        foreach ($created['users'] as $u) {
            try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($u) . "@'%'"); } catch (Throwable $e) {}
        }
    }
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM tenant_admin_log WHERE subdomain LIKE 'lctest%'");
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'lctest%'");
        $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'lctest%'");
    } catch (Throwable $e) {}
}

register_shutdown_function(function () {
    global $pass, $fail;
    teardown();
    echo "\n\033[1m── Result ──\033[0m\n";
    echo "  \033[32m$pass passed\033[0m" . ($fail ? ", \033[31m$fail failed\033[0m" : '') . "\n\n";
});

function makeTenant(string $name, array &$created): array
{
    $sub = 'lctest' . bin2hex(random_bytes(3));
    $r = provisionTenant($name, $sub, "owner@$sub.test", 'Provision123', [
        'status'              => 'active',
        'owner_contact_email' => "owner@$sub.test",
        'skip_welcome_email'  => true,
    ]);
    if ($r['ok']) {
        $created['tenants'][]   = (int)$r['tenant_id'];
        $created['databases'][] = $r['db_name'];
        $created['users'][]     = $r['db_username'];
    }
    return $r;
}

function dbExists(string $db): bool
{
    try {
        $n = getProvisioningPdo()->query(
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = "
            . getProvisioningPdo()->quote($db)
        )->fetchColumn();
        return (int)$n > 0;
    } catch (Throwable $e) { return false; }
}

echo "\n\033[1mTenant lifecycle — archive / release / purge\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('Archiving keeps everything');

$a = makeTenant('Archive Me Ltd', $created);
ok($a['ok'] === true, 'tenant provisioned' . ($a['ok'] ? '' : ': ' . $a['error']));
if (!$a['ok']) exit(1);
$aid = (int)$a['tenant_id'];

ok(archiveTenant($aid, 'Wrong Name Ltd')['ok'] === false, 'a mistyped company name is refused');
ok(getTenant($aid)['status'] === 'active', 'and the tenant is untouched by the refusal');
$log = getControlPdo()->prepare("SELECT action FROM tenant_admin_log WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
$log->execute([$aid]);
ok($log->fetchColumn() === 'archive_refused', 'the refused attempt is recorded');

$r = archiveTenant($aid, 'Archive Me Ltd', 'customer moved on');
ok($r['ok'] === true, 'archiving succeeds with the name typed correctly');
$t = getTenant($aid);
ok($t['status'] === 'archived',     'status is archived');
ok($t['suspended_at'] !== null,     'the date it closed is recorded');
ok(dbExists($a['db_name']),         'THE DATABASE IS STILL THERE — nothing was destroyed');

$conn = false;
try {
    // provisionTenant()'s return has no db_host — read the stored one, which
    // is what every other caller uses anyway.
    $reg = getControlPdo()->query("SELECT db_host, db_password_encrypted FROM tenants WHERE id = $aid")
                          ->fetch(PDO::FETCH_ASSOC);
    $pw = decryptTenantSecret((string)$reg['db_password_encrypted']);
    $p = new PDO("mysql:host=" . $reg['db_host'] . ";dbname=" . $a['db_name'] . ";charset=utf8mb4",
        $a['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $conn = (int)$p->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
} catch (Throwable $e) {}
ok($conn, 'and its data is still readable — the owner account is still in it');

ok(archiveTenant($aid, 'Archive Me Ltd')['ok'] === true, 'archiving an archived tenant is a no-op, not an error');

section('Reactivating brings them straight back');

ok(activateTenant($aid)['ok'] === true, 'an archived tenant can be reactivated');
$t = getTenant($aid);
ok($t['status'] === 'active',            'status is active again');
ok($t['suspension_reason'] === null,     'the suspension reason is cleared');

section("'archived' locks the tenant out at the gate");

// core/tenant_bootstrap.php serves 'active' and 'trial' and refuses the rest.
$boot = file_get_contents("$root/core/tenant_bootstrap.php");
ok(strpos($boot, "\$status === 'archived'") !== false, 'the bootstrap has an explicit archived branch');
ok(preg_match("/\\\$status !== 'active' && \\\$status !== 'trial'/", $boot) === 1,
   'and a catch-all so any future status is refused rather than served by default');

// ─────────────────────────────────────────────────────────────────────────────
section('Purge will not touch a tenant whose data still exists');

archiveTenant($aid, 'Archive Me Ltd');
$p1 = purgeTenant($aid, 'Archive Me Ltd');
ok($p1['ok'] === false, 'purging an ARCHIVED tenant is refused');
ok(stripos((string)$p1['error'], 'delete this tenant first') !== false, 'and says the deletion step cannot be skipped');
ok(dbExists($a['db_name']), 'its database is still there');

activateTenant($aid);
ok(purgeTenant($aid, 'Archive Me Ltd')['ok'] === false, 'purging an ACTIVE tenant is refused');
ok(getTenant($aid) !== null, 'the row is still there');

// ─────────────────────────────────────────────────────────────────────────────
section('Releasing an address');

$b = makeTenant('Release Me Ltd', $created);
ok($b['ok'] === true, 'second tenant provisioned');
$bid = (int)$b['tenant_id'];
$bsub = $b['subdomain'];

ok(releaseTenantSubdomain($bid, 'Release Me Ltd')['ok'] === false, 'a LIVE tenant\'s address cannot be released');
ok(stripos((string)releaseTenantSubdomain($bid, 'Release Me Ltd')['error'], 'closed or archived') !== false,
   'and the reason says why');

ok(deleteTenant($bid, 'Release Me Ltd')['ok'] === true, 'the tenant is deleted');
ok(!dbExists($b['db_name']), 'its database is gone');
ok(tenantSubdomainAvailable($bsub) === false,
   'its address is STILL CLAIMED — old links must not reach a new company by accident');

ok(releaseTenantSubdomain($bid, 'Wrong Name')['ok'] === false, 'a mistyped name is refused');
ok(tenantSubdomainAvailable($bsub) === false, 'and the address stays claimed');

$rel = releaseTenantSubdomain($bid, 'Release Me Ltd');
ok($rel['ok'] === true, 'releasing succeeds when asked for explicitly');
ok($rel['released'] === $bsub, 'and reports which address was freed');
ok(tenantSubdomainAvailable($bsub) === true, 'THE ADDRESS IS AVAILABLE AGAIN');

$kept = getTenant($bid);
ok($kept !== null, 'the company\'s row is kept');
ok(strpos((string)$kept['subdomain'], '~released~') !== false, 'under a tombstone name');
ok((string)$kept['company_name'] === 'Release Me Ltd', 'with its identity intact');
ok(releaseTenantSubdomain($bid, 'Release Me Ltd')['ok'] === false, 'releasing twice is refused');

// The freed name must really be usable again, not just reported as free.
$c = provisionTenant('New Owner Ltd', $bsub, "new@$bsub.test", 'Provision123', [
    'status' => 'active', 'owner_contact_email' => "new@$bsub.test", 'skip_welcome_email' => true,
]);
ok($c['ok'] === true, 'a NEW company can now claim that address' . ($c['ok'] ? '' : ': ' . $c['error']));
if ($c['ok']) {
    $created['tenants'][]   = (int)$c['tenant_id'];
    $created['databases'][] = $c['db_name'];
    $created['users'][]     = $c['db_username'];
}

// ─────────────────────────────────────────────────────────────────────────────
section('Purging a deleted tenant');

$d = makeTenant('Purge Me Ltd', $created);
ok($d['ok'] === true, 'third tenant provisioned');
$did  = (int)$d['tenant_id'];
$dsub = $d['subdomain'];

deleteTenant($did, 'Purge Me Ltd');
ok(getTenant($did) !== null, 'after deletion the tombstone row is still listed');
ok(getTenant($did)['status'] === 'deleted', 'as deleted');

ok(purgeTenant($did, 'Nope Ltd')['ok'] === false, 'a mistyped name is refused');
ok(getTenant($did) !== null, 'and the row survives the refusal');

$pr = purgeTenant($did, 'Purge Me Ltd');
ok($pr['ok'] === true, 'purging succeeds');
ok(getTenant($did) === null, 'THE REGISTRY ROW IS GONE');

$rows = getControlPdo()->prepare("SELECT action, detail FROM tenant_admin_log WHERE tenant_id = ? ORDER BY id");
$rows->execute([$did]);
$entries = $rows->fetchAll(PDO::FETCH_ASSOC);
$actions = array_column($entries, 'action');
ok(in_array('delete', $actions, true), 'the audit log still records the deletion');
ok(in_array('purge', $actions, true),  'and records the purge itself');
$purgeDetail = '';
foreach ($entries as $e) if ($e['action'] === 'purge') $purgeDetail = (string)$e['detail'];
ok(strpos($purgeDetail, 'Purge Me Ltd') !== false, 'the purge entry names the company, which no longer has a row');
ok(strpos($purgeDetail, $dsub) !== false, 'and the address it gave back');

ok(tenantSubdomainAvailable($dsub) === true, 'purging frees the address as a side effect');
ok(purgeTenant($did, 'Purge Me Ltd')['ok'] === false, 'purging an already-purged tenant is refused');

section('Stats count the new state');

$s = tenantStats();
ok(array_key_exists('archived', $s), 'tenantStats() reports an archived bucket');

// The first tenant was reactivated during the purge-refusal checks above, so
// put it back in the archived state before counting it.
$before = (int)tenantStats()['archived'];
archiveTenant($aid, 'Archive Me Ltd');
ok((int)tenantStats()['archived'] === $before + 1, 'archiving a tenant increments that bucket');

exit($fail === 0 ? 0 : 1);
