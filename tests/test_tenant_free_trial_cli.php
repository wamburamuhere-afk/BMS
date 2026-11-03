<?php
/**
 * Free trial — default for new companies + superadmin "Set free trial"
 *   php tests/test_tenant_free_trial_cli.php
 *
 * What it proves:
 *   - self-registration (and Smart BMS approval / continue-as-POS) starts a
 *     company on status 'trial', so its 14-day clock actually runs; 'active'
 *     with no paid-until date never expired and showed "No end date"
 *   - the New company form defaults to the free trial
 *   - setTenantTrial(): start date → end = start + default length, or a custom
 *     end; every refusal rule; a paying company is never turned into a trial;
 *     an expiry-suspended company is restored; the change is audited
 *   - the Subscription page counts the trial from the chosen start date
 *
 * Uses one throwaway registry row (no tenant database); removes it. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/tenant_lifecycle_policy.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }

$c   = getControlPdo();
$sub = 'ftrial' . bin2hex(random_bytes(3));
$tid = 0;
register_shutdown_function(function () use ($c, &$tid) {
    global $pass, $fail;
    if ($tid) {
        foreach (['tenant_payments', 'tenant_admin_log'] as $t) $c->prepare("DELETE FROM $t WHERE tenant_id = ?")->execute([$tid]);
        $c->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tid]);
    }
    echo "\nPasses:   $pass\nFailures: $fail\n";
});

$ymd   = fn(int $offset) => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days', strtotime(date('Y-m-d'))));
$row   = function () use ($c, &$tid) { $s = $c->prepare('SELECT * FROM tenants WHERE id = ?'); $s->execute([$tid]); return $s->fetch(PDO::FETCH_ASSOC); };
$state = function (string $status, ?string $reason = null) use ($c, &$tid) {
    $c->prepare("UPDATE tenants SET status = ?, suspension_reason = ?, suspended_at = IF(? = 'suspended', NOW(), NULL),
                        subscription_ends_at = NULL, trial_ends_at = NULL, trial_starts_at = NULL, grace_until = NULL WHERE id = ?")
      ->execute([$status, $reason, $status, $tid]);
};
$days = tenantDefaultTrialDays();

try {
    section('1. Schema');
    ok(controlColumnExists('tenants', 'trial_starts_at'), 'tenants.trial_starts_at exists (scripts/setup_control_db.php)');

    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, created_at)
                 VALUES ('Free Trial Test Ltd', ?, ?, ?, 'x', 'ftrial@example.test', 'active', DATE_SUB(NOW(), INTERVAL 10 DAY))")
      ->execute([$sub, 'bms_t_' . $sub, 'bms_u_' . substr($sub, 0, 20)]);
    $tid = (int)$c->lastInsertId();

    section('2. Active company with no end date → free trial from a chosen start');
    $r = setTenantTrial($tid, $ymd(-3));
    $t = $row();
    ok($r['ok'] === true, 'set free trial from 3 days ago with the default length' . ($r['ok'] ? '' : ': ' . $r['error']));
    ok($t['status'] === 'trial', 'status is now Trial');
    ok(substr((string)$t['trial_starts_at'], 0, 10) === $ymd(-3), 'trial starts on the chosen date');
    ok((string)$t['trial_ends_at'] === $ymd(-3 + $days) . ' 23:59:59', "trial ends start + $days days, end of day");
    ok($t['subscription_ends_at'] === null, 'no paid-until date');
    ok(tenantDaysRemaining($t) === $days - 3, 'days left counted from the start (' . tenantDaysRemaining($t) . ')');
    ok(tenantTrialStartsAt($t) === $ymd(-3), 'the Subscription page reads the chosen start date');
    $log = $c->prepare("SELECT detail FROM tenant_admin_log WHERE tenant_id = ? AND action = 'set_trial' ORDER BY id DESC LIMIT 1");
    $log->execute([$tid]);
    ok(str_contains((string)$log->fetchColumn(), $ymd(-3)), 'the change is in the superadmin activity log');

    section('3. Custom end date');
    $r = setTenantTrial($tid, $ymd(0), $ymd(30));
    ok($r['ok'] === true && substr((string)$row()['trial_ends_at'], 0, 10) === $ymd(30), 'a custom end date (30 days) is kept');

    section('4. Refusals');
    $bad = [
        [[$ymd(1), ''], 'a start date in the future'],
        [[$ymd(-400), ''], 'a start more than a year ago'],
        [['2026-02-30', ''], 'an impossible date'],
        [['not-a-date', ''], 'text instead of a date'],
        [[$ymd(-2), $ymd(-2)], 'an end on the same day as the start'],
        [[$ymd(-20), $ymd(-1)], 'a trial that would already have ended'],
        [[$ymd(0), $ymd(400)], 'a trial longer than 365 days'],
    ];
    foreach ($bad as [[$s, $e], $label]) {
        $r = setTenantTrial($tid, $s, $e);
        ok($r['ok'] === false && $r['error'] !== '', "refused: $label — \"{$r['error']}\"");
    }
    $state('suspended', 'manual');
    ok(setTenantTrial($tid, $ymd(0))['ok'] === false, 'refused: a company suspended by hand');
    $state('suspended', 'awaiting_approval');
    $r = setTenantTrial($tid, $ymd(0));
    ok($r['ok'] === false && stripos($r['error'], 'approv') !== false, 'refused: a company waiting for approval (approve it instead)');
    $state('deleted');
    ok(setTenantTrial($tid, $ymd(0))['ok'] === false, 'refused: a deleted company');

    section('5. A company whose trial expired gets service back');
    $state('suspended', 'trial_expired');
    $r = setTenantTrial($tid, $ymd(0));
    $t = $row();
    ok($r['ok'] === true && $r['resumed'] === true, 'trial set and service resumed');
    ok($t['status'] === 'trial' && $t['suspension_reason'] === null && $t['suspended_at'] === null, 'no longer suspended');

    section('6. A paying company is never turned into a trial');
    $state('active');
    $c->prepare("INSERT INTO tenant_payments (tenant_id, amount_tzs, duration_months, starts_at, ends_at) VALUES (?, 50000, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))")->execute([$tid]);
    $r = setTenantTrial($tid, $ymd(0));
    ok($r['ok'] === false && stripos($r['error'], 'Extend access') !== false, 'refused, pointing to Extend access');
    ok($row()['status'] === 'active', 'status unchanged');

    section('7. New companies start on the free trial');
    $reg = (string)file_get_contents("$root/core/tenant_registration.php");
    ok(preg_match("/provisionTenant\(\\\$company, \\\$sub, \\\$username, \\\$pw, \[.*?'status'\s*=>\s*'trial'/s", $reg) === 1, 'self-registration provisions with status trial');
    ok(str_contains($reg, "SET status='trial', suspended_at=NULL") && !str_contains($reg, "SET status='active', suspended_at=NULL, suspension_reason=NULL, grace_until=NULL,\n                   trial_ends_at"), 'Smart BMS approval / continue as Smart POS start a trial, not active');
    $new = (string)file_get_contents("$root/app/superadmin/tenant_new.php");
    ok(str_contains($new, '<option value="trial" selected>') && !str_contains($new, '<option value="active" selected>'), 'New company form defaults to the free trial');
    ok(str_contains($new, 'id="trialDateRow">'), 'its Trial ends box is visible by default');

    section('8. Superadmin control');
    $act = (string)file_get_contents("$root/actions/superadmin_tenant_access.php");
    ok(strpos($act, "\$action === 'set_trial'") > strpos($act, 'csrf_check();') && str_contains($act, 'setTenantTrial($tenantId,'), 'set_trial runs after the host/session/CSRF guards');
    $view = (string)file_get_contents("$root/app/superadmin/tenant_view.php");
    ok(str_contains($view, 'function doSetTrial()') && str_contains($view, "action: 'set_trial'"), 'tenant page has the Set free trial dialog');
    ok(str_contains($view, '!$__hasPayments'), 'the button is hidden for a company with payments');
    ok(str_contains($view, "return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')"), 'date maths stays in local time (no UTC day shift)');
    $api = (string)file_get_contents("$root/api/my_subscription.php");
    ok(str_contains($api, '$start = tenantTrialStartsAt($t);'), 'Subscription page counts from the trial start');
} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
