<?php
/**
 * Tenant expiry policy — Phase 4 CLI test
 *   php tests/test_tenant_expiry_policy_cli.php
 *
 * What it proves:
 *   - trial length and grace period are platform settings, not constants, and
 *     out-of-range values fall back rather than taking effect
 *   - a tenant's own grace_days overrides the default; NULL follows it; 0 is a
 *     real value meaning "no grace", not "unset"
 *   - tenantAccessEndsAt() reads the right column per status, and answers null
 *     for a tenant that genuinely never expires
 *   - extending works for a TRIAL and for an ACTIVE subscription — the thing
 *     the old extend-trial action could not do
 *   - a lapsed date is extended from TODAY, so the days asked for are the days
 *     given; a future date is extended from itself
 *   - a tenant auto-suspended by expiry is put back into service; one an
 *     operator suspended by hand is NOT
 *   - grace_until is cleared when the clock moves
 *   - the hard-coded constants are gone from all three files
 *   - the deprecated extend-trial endpoint still answers in its old shape
 *
 * Touches the control database only — no tenant databases are provisioned, so
 * this suite is fast. Exit 0 = pass.
 */
$root = dirname(__DIR__);

ini_set('session.save_path', sys_get_temp_dir());
if (session_status() === PHP_SESSION_NONE) session_start();

require_once "$root/includes/config.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/tenant_lifecycle_policy.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$ctrl        = getControlPdo();
$madeTenants = [];
$savedTrial  = getPlatformSetting('default_trial_days', '');
$savedGrace  = getPlatformSetting('default_grace_days', '');

register_shutdown_function(function () use (&$madeTenants, $savedTrial, $savedGrace) {
    global $pass, $fail;
    try {
        $c = getControlPdo();
        foreach ($madeTenants as $id) {
            $c->prepare("DELETE FROM tenant_admin_log WHERE tenant_id = ?")->execute([$id]);
            $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$id]);
        }
        $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'exptest%'");
        // Put the operator's own settings back exactly as they were.
        setPlatformSetting('default_trial_days', $savedTrial === '' ? null : $savedTrial);
        setPlatformSetting('default_grace_days', $savedGrace === '' ? null : $savedGrace);
    } catch (Throwable $e) {}
    echo "\n\033[1m── Result ──\033[0m\n";
    echo "  \033[32m$pass passed\033[0m" . ($fail ? ", \033[31m$fail failed\033[0m" : '') . "\n\n";
});

/**
 * A registry row only — these tests are about dates and status, so no database
 * is provisioned for them.
 */
function fakeTenant(array $cols, array &$made): int
{
    $ctrl = getControlPdo();
    $sub  = 'exptest' . bin2hex(random_bytes(4));
    $base = [
        'company_name' => 'Expiry Test Ltd', 'subdomain' => $sub,
        'db_host' => 'localhost', 'db_name' => 'none', 'db_username' => 'none',
        'db_password_encrypted' => '', 'owner_email' => "o@$sub.test",
        'status' => 'active',
    ];
    $row  = array_merge($base, $cols);
    $keys = array_keys($row);
    $ctrl->prepare("INSERT INTO tenants (" . implode(',', array_map(fn($k) => "`$k`", $keys)) . ")
                    VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")")
         ->execute(array_values($row));
    $id = (int)$ctrl->lastInsertId();
    $made[] = $id;
    return $id;
}

function row(int $id): array
{
    $s = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

echo "\n\033[1mTenant expiry policy — configurable trial, grace and extension\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('The numbers are settings now, not constants');

foreach ([__DIR__ . '/../core/tenant_bootstrap.php', __DIR__ . '/../api/cron/trial_enforcement.php'] as $f) {
    $name = basename($f);
    // Both files still NAME the old constant, in a comment explaining why it
    // went — which is worth keeping. So strip comments before looking: what
    // must be gone is the define(), not the memory of it.
    $code = '';
    foreach (token_get_all(file_get_contents($f)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    ok(!preg_match('/define\s*\(\s*[\x27"](BMS_)?GRACE_PERIOD_DAYS/', $code),
       "$name no longer defines a grace-period constant");
    ok(strpos($code, 'GRACE_PERIOD_DAYS') === false,
       "$name does not use one either");
}
$prov = file_get_contents(__DIR__ . '/../core/tenant_provisioner.php');
ok(strpos($prov, "strtotime('+14 days')") === false, 'tenant_provisioner.php no longer hard-codes a 14-day trial');
ok(strpos($prov, 'tenantDefaultTrialDays()') !== false, 'and asks the policy instead');

setPlatformSetting('default_trial_days', '21');
setPlatformSetting('default_grace_days', '3');
ok(tenantDefaultTrialDays() === 21, 'the trial default follows the setting');
ok(tenantDefaultGraceDays() === 3,  'the grace default follows the setting');

setPlatformSetting('default_grace_days', '0');
ok(tenantDefaultGraceDays() === 0, '0 grace days is honoured — "cut them off on the day" is a real policy');

setPlatformSetting('default_trial_days', '9999');
ok(tenantDefaultTrialDays() === 14, 'an out-of-range trial setting falls back to 14 rather than taking effect');
setPlatformSetting('default_grace_days', '-5');
ok(tenantDefaultGraceDays() === 7, 'an out-of-range grace setting falls back to 7');

setPlatformSetting('default_trial_days', '14');
setPlatformSetting('default_grace_days', '7');

// ─────────────────────────────────────────────────────────────────────────────
section('Per-tenant grace override');

$gid = fakeTenant(['status' => 'active'], $madeTenants);
ok(tenantGraceDaysFor(row($gid)) === 7, 'with no override, the platform default applies');

ok(setTenantGraceDays($gid, 21)['ok'] === true, 'an override can be set');
ok(tenantGraceDaysFor(row($gid)) === 21, 'and it wins over the default');

ok(setTenantGraceDays($gid, 0)['ok'] === true, '0 can be set for one tenant');
ok(tenantGraceDaysFor(row($gid)) === 0, 'and 0 means 0, not "unset"');

ok(setTenantGraceDays($gid, null)['ok'] === true, 'it can be cleared');
ok(row($gid)['grace_days'] === null, 'which stores NULL');
ok(tenantGraceDaysFor(row($gid)) === 7, 'and falls back to the platform default again');

ok(setTenantGraceDays($gid, 500)['ok'] === false, 'an absurd override is refused');
ok(setTenantGraceDays($gid, -1)['ok']  === false, 'a negative override is refused');
ok(tenantGraceDaysFor(null) === 7, 'a missing tenant row still yields the default, not a crash');

// ─────────────────────────────────────────────────────────────────────────────
section('Which clock is running');

$trialId = fakeTenant(['status' => 'trial', 'trial_ends_at' => date('Y-m-d 23:59:59', strtotime('+10 days'))], $madeTenants);
$subId   = fakeTenant(['status' => 'active', 'subscription_ends_at' => date('Y-m-d', strtotime('+20 days'))], $madeTenants);
$noneId  = fakeTenant(['status' => 'active'], $madeTenants);
$suspId  = fakeTenant(['status' => 'suspended', 'trial_ends_at' => date('Y-m-d 23:59:59', strtotime('-5 days')),
                       'suspension_reason' => 'trial_expired'], $madeTenants);

ok(tenantAccessEndsAt(row($trialId)) !== null, 'a trial reports its trial_ends_at');
ok(tenantDaysRemaining(row($trialId)) === 10, 'and 10 days remaining');
ok(tenantDaysRemaining(row($subId)) === 20, 'an active subscription reports its own date');
ok(tenantAccessEndsAt(row($noneId)) === null, 'an active tenant with no end date reports null');
ok(tenantDaysRemaining(row($noneId)) === null, 'and no day count');
ok(tenantAccessEndsAt(row($suspId)) === null, 'a suspended tenant has no clock running');

$lbl = tenantExpiryLabel(row($noneId));
ok($lbl['text'] === 'No expiry', 'the list label says "No expiry" rather than an em-dash');
ok($lbl['tone'] === 'none', 'flagged, not styled as healthy');
ok(tenantExpiryLabel(row($trialId))['tone'] === 'ok',   '10 days out reads as fine');
ok(tenantExpiryLabel(row($subId))['tone']   === 'ok',   'so does 20');

$soonId = fakeTenant(['status' => 'active', 'subscription_ends_at' => date('Y-m-d', strtotime('+3 days'))], $madeTenants);
ok(tenantExpiryLabel(row($soonId))['tone'] === 'warn', '3 days out is a warning');
$pastId = fakeTenant(['status' => 'active', 'subscription_ends_at' => date('Y-m-d', strtotime('-2 days'))], $madeTenants);
$pl = tenantExpiryLabel(row($pastId));
ok($pl['tone'] === 'danger', 'already past is danger');
ok($pl['text'] === '2 days ago', 'and says how long ago');

// ─────────────────────────────────────────────────────────────────────────────
section('Extending — the half-feature that is now whole');

ok(extendTenantAccess($trialId, 0)['ok']   === false, '0 days is refused');
ok(extendTenantAccess($trialId, 400)['ok'] === false, '400 days is refused');
ok(extendTenantAccess(999999, 7)['ok']     === false, 'an unknown tenant is refused');

$r = extendTenantAccess($trialId, 7);
ok($r['ok'] === true, 'a trial can be extended');
ok($r['field'] === 'trial_ends_at', 'and it is trial_ends_at that moves');
ok(tenantDaysRemaining(row($trialId)) === 17, '10 days + 7 = 17');

// THE case the old action could not handle at all.
$r = extendTenantAccess($subId, 30);
ok($r['ok'] === true, 'an ACTIVE paying subscription can be extended');
ok($r['field'] === 'subscription_ends_at', 'and it is subscription_ends_at that moves');
ok(tenantDaysRemaining(row($subId)) === 50, '20 days + 30 = 50');

// A lapsed tenant must get the days asked for, not lose some to the past.
$r = extendTenantAccess($pastId, 10);
ok($r['ok'] === true, 'a lapsed subscription can be extended');
ok(tenantDaysRemaining(row($pastId)) === 10,
   'a date 2 days in the past + 10 days gives 10 days from TODAY, not 8');

// No expiry at all → the clock starts now.
$r = extendTenantAccess($noneId, 15);
ok($r['ok'] === true, 'a tenant with no expiry can be given one');
ok(tenantDaysRemaining(row($noneId)) === 15, 'counted from today');

section('Extending restores service only when expiry was the reason');

ok(row($suspId)['status'] === 'suspended', 'the auto-suspended tenant starts suspended');
$r = extendTenantAccess($suspId, 14);
ok($r['ok'] === true && $r['resumed'] === true, 'extending resumes it');
$s = row($suspId);
ok($s['status'] === 'trial', 'back to trial, because a trial is what expired');
ok($s['suspension_reason'] === null, 'the suspension reason is cleared');
ok($s['suspended_at'] === null, 'and the suspension date');

$manualId = fakeTenant(['status' => 'suspended', 'suspension_reason' => 'manual',
                        'subscription_ends_at' => date('Y-m-d', strtotime('+5 days'))], $madeTenants);
$r = extendTenantAccess($manualId, 30);
ok($r['ok'] === true, 'a manually suspended tenant can still be given more time');
ok($r['resumed'] === false, 'but is NOT put back into service');
ok(row($manualId)['status'] === 'suspended', 'a hand suspension is a decision, not a clock');

section('Moving the clock clears a stale grace window');

$graceId = fakeTenant(['status' => 'active',
                       'subscription_ends_at' => date('Y-m-d', strtotime('-1 day')),
                       'grace_until' => date('Y-m-d', strtotime('+3 days'))], $madeTenants);
ok(row($graceId)['grace_until'] !== null, 'it starts inside a grace window');
extendTenantAccess($graceId, 30);
ok(row($graceId)['grace_until'] === null, 'extending clears it — the old window is meaningless now');

section('Closed tenants have nothing to extend');

$archId = fakeTenant(['status' => 'archived'], $madeTenants);
$delId  = fakeTenant(['status' => 'deleted'], $madeTenants);
ok(extendTenantAccess($archId, 7)['ok'] === false, 'an archived tenant is refused');
ok(extendTenantAccess($delId, 7)['ok']  === false, 'a deleted tenant is refused');
ok(stripos((string)extendTenantAccess($archId, 7)['error'], 'reactivate') !== false,
   'and the message says what to do first');

section('Who granted it is recorded, in the right place');

$logged = getControlPdo()->prepare("SELECT action, detail FROM tenant_admin_log WHERE tenant_id = ? AND action = 'extend_access'");
$logged->execute([$trialId]);
$e = $logged->fetch(PDO::FETCH_ASSOC);
ok($e !== false, 'the extension is in tenant_admin_log');
ok($e && strpos((string)$e['detail'], '+7 day') !== false, 'with the number of days — which is where a history belongs');
$pol = file_get_contents(__DIR__ . '/../core/tenant_lifecycle_policy.php');
ok(strpos($pol, 'COALESCE(trial_extended_by') === false,
   'trial_extended_by is not abused as a day counter — it holds the operator id, as its only previous writer did');

section('The deprecated endpoint still answers in its old shape');

$dep = file_get_contents(__DIR__ . '/../actions/superadmin_extend_trial.php');
ok(strpos($dep, 'extendTenantAccess(') !== false, 'superadmin_extend_trial.php forwards to the real function');
ok(strpos($dep, "'trial_ends_at' => \$r['ends_at']") !== false,
   'and still returns the trial_ends_at key an old caller would read');

exit($fail === 0 ? 0 : 1);
