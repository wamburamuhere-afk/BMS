<?php
/**
 * applySubscriptionPayment() + vendor context — the single path that extends a
 * tenant's paid period (operator's Record Payment today, BJP invoice bridge next).
 *   php tests/test_subscription_payment_cli.php
 * Creates throwaway registry rows (subdomain 'subpaytest%') and removes them. Exit 0 = pass.
 */
$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());
@session_start();

// The host/vendor-context part needs no database; the payment part runs only
// where a config exists (not in CI).
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) {
    require_once "$root/roots.php";
    require_once "$root/core/control_db.php";
    require_once "$root/core/tenant_admin.php";
    require_once "$root/core/tenant_lifecycle_policy.php";
}
require_once "$root/core/vendor_context.php";
if (!function_exists('bmsCurrentTenant')) {
    function bmsCurrentTenant(): ?array { return $GLOBALS['__bms_tenant'] ?? null; }
}

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }

// ── Pure host matching (no DB) ────────────────────────────────────────────
section('vendor host matching');
$base = 'bms.bjptechnologies.co.tz';
ok(vendorHostMatchesBase('bms.bjptechnologies.co.tz', $base), 'root domain is the vendor');
ok(vendorHostMatchesBase('BMS.bjptechnologies.co.tz:443', $base), 'case + port ignored');
ok(vendorHostMatchesBase('www.bms.bjptechnologies.co.tz', $base), 'www. of the root is the vendor');
ok(!vendorHostMatchesBase('dukalajuma.bms.bjptechnologies.co.tz', $base), 'a tenant subdomain is NOT the vendor');
ok(!vendorHostMatchesBase('superadmin.bms.bjptechnologies.co.tz', $base), 'the superadmin host is NOT the vendor');
ok(!vendorHostMatchesBase('evilbms.bjptechnologies.co.tz', $base), 'a look-alike host is NOT the vendor');
ok(!vendorHostMatchesBase('bms.bjptechnologies.co.tz', null), 'no base domain configured → never the vendor');

section('vendor context (CLI)');
$savedMode = getenv('TENANT_MODE');
putenv('TENANT_MODE=off');
putenv('BMS_VENDOR_CONTEXT=1');
ok(!isPlatformVendorContext(), 'tenancy switched off → never the vendor (single-tenant installs see nothing)');
putenv('TENANT_MODE=on');
ok(isPlatformVendorContext(), 'tenancy on + CLI opt-in → vendor');
putenv('BMS_VENDOR_CONTEXT');
ok(!isPlatformVendorContext(), 'CLI without the opt-in → not the vendor');
$GLOBALS['__bms_tenant'] = ['id' => 1];
putenv('BMS_VENDOR_CONTEXT=1');
ok(!isPlatformVendorContext(), 'inside a resolved tenant → never the vendor, even with the opt-in');
unset($GLOBALS['__bms_tenant']);
putenv('BMS_VENDOR_CONTEXT');
putenv($savedMode === false ? 'TENANT_MODE' : "TENANT_MODE=$savedMode");

// ── applySubscriptionPayment (control DB) ─────────────────────────────────
if (!$hasConfig) { echo "\nSKIP payment part: no includes/config.php (CI)\nPasses: $pass\nFailures: $fail\n"; exit($fail ? 1 : 0); }
try { $c = getControlPdo(); } catch (Throwable $e) { echo "SKIP DB part: {$e->getMessage()}\n"; exit($fail ? 1 : 0); }

$cleanup = function () use ($c) {
    $ids = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'subpaytest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($in)");
        $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($in)");
    }
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'subpaytest%'");
};
$cleanup();
register_shutdown_function(function () use ($cleanup) {
    global $pass, $fail;
    $cleanup();
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

$mk = function (string $sub, array $f) use ($c): int {
    $cols = array_merge(['company_name' => "Test $sub", 'subdomain' => $sub, 'db_name' => 'none', 'db_username' => 'none',
        'db_password_encrypted' => 'x', 'owner_email' => "$sub@example.com"], $f);
    $c->prepare("INSERT INTO tenants (`" . implode('`,`', array_keys($cols)) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
      ->execute(array_values($cols));
    return (int)$c->lastInsertId();
};
$row = function (int $id) use ($c): array {
    $s = $c->prepare("SELECT * FROM tenants WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC);
};
$d = fn(string $rel) => date('Y-m-d', strtotime($rel));

section('trial tenant pays');
$id = $mk('subpaytest1', ['status' => 'trial', 'trial_ends_at' => $d('+5 days') . ' 23:59:59']);
$r = applySubscriptionPayment($id, 6, 45000, 'manual', null, 'cash', null);
$t = $row($id);
ok($r['ok'], 'payment accepted');
ok($t['status'] === 'active', 'trial → active (the old bug: paid tenants stayed on trial and got suspended)');
ok($r['starts_at'] === $d('+5 days'), 'period starts when the trial ends — the 5 trial days are kept');
ok($t['subscription_ends_at'] === date('Y-m-d', strtotime($d('+5 days') . ' +6 months')), 'ends 6 months after that');
ok($t['payment_status'] === 'current' && $t['billing_cycle'] === 'biannual', 'billing fields set');

section('active tenant renews early');
$id = $mk('subpaytest2', ['status' => 'active', 'subscription_ends_at' => $d('+20 days'), 'trial_ends_at' => $d('+3 days')]);
$r = applySubscriptionPayment($id, 1, 10000);
ok($r['starts_at'] === $d('+20 days'), 'new month starts when the paid period ends — no days lost (the old bug restarted from today)');
ok($row($id)['subscription_ends_at'] === date('Y-m-d', strtotime($d('+20 days') . ' +1 month')), 'end date pushed forward by one month');

section('lapsed tenant');
$id = $mk('subpaytest3', ['status' => 'suspended', 'suspension_reason' => 'subscription_expired',
    'subscription_ends_at' => $d('-30 days'), 'grace_until' => $d('-23 days')]);
$r = applySubscriptionPayment($id, 3, 25000);
$t = $row($id);
ok($r['starts_at'] === date('Y-m-d'), 'an expired period restarts from today, not from the past');
ok($t['status'] === 'active' && $t['suspension_reason'] === null && $t['grace_until'] === null, 'expiry-suspended → active, grace cleared');
ok($r['activated'] === true, 'reported as activated');

section('operator-suspended tenant');
$id = $mk('subpaytest4', ['status' => 'suspended', 'suspension_reason' => 'manual']);
applySubscriptionPayment($id, 1, 10000);
ok($row($id)['status'] === 'suspended', 'a manual suspension is NOT undone by a payment');
applySubscriptionPayment($id, 1, 10000, 'manual', null, null, null, null, true);
ok($row($id)['status'] === 'active', '…unless the operator ticks "re-activate"');

section('invoice idempotency');
$id = $mk('subpaytest5', ['status' => 'active', 'subscription_ends_at' => $d('+1 day')]);
$a = applySubscriptionPayment($id, 6, 45000, 'vendor_invoice', 'BJP-INV-TEST-1');
$b = applySubscriptionPayment($id, 6, 45000, 'vendor_invoice', 'BJP-INV-TEST-1');
$n = (int)$c->query("SELECT COUNT(*) FROM tenant_payments WHERE tenant_id = $id")->fetchColumn();
ok($a['ok'] && !$a['duplicate'], 'first application of an invoice extends');
ok($b['ok'] && $b['duplicate'], 'the same invoice again is recognised as a duplicate');
ok($n === 1, 'only one payment row exists');
ok($row($id)['subscription_ends_at'] === $a['ends_at'], 'end date moved only once');
$src = $c->query("SELECT source, source_ref FROM tenant_payments WHERE tenant_id = $id")->fetch(PDO::FETCH_ASSOC);
ok($src['source'] === 'vendor_invoice' && $src['source_ref'] === 'BJP-INV-TEST-1', 'payment row records its source invoice');
ok(!applySubscriptionPayment($id, 6, 45000, 'vendor_invoice', '')['ok'], 'an invoice payment without a reference is refused');

section('refusals');
$id = $mk('subpaytest6', ['status' => 'archived']);
ok(!applySubscriptionPayment($id, 1, 10000)['ok'], 'archived tenant refused');
ok(!applySubscriptionPayment(999999999, 1, 10000)['ok'], 'unknown tenant refused');
ok(!applySubscriptionPayment($id, 0, 10000)['ok'], '0 months refused');
ok(!applySubscriptionPayment($id, 1, 10000, 'bogus')['ok'], 'unknown source refused');
