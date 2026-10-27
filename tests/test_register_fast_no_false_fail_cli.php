<?php
/**
 * Web signup: faster, emails after the answer, no false "failed" — CLI regression
 *   php tests/test_register_fast_no_false_fail_cli.php
 *
 * Bug (2026-10-27, seen live): register.php often said "Registration failed.
 * Please try again." although the company was created and the owner could sign
 * in. The browser gives up after 120 s; the server carries on (ignore_user_abort)
 * and finishes. The wait was long because every signup re-ran all 91 tenant
 * migrations (old template) and sent the welcome + superadmin emails inline.
 *
 *   A. STATIC   — new files lint; the page sends a reg_token, polls
 *                 register_status.php when the answer is lost, beacons
 *                 register_after.php; the template records every migration.
 *   B. LIVE     — registerTenant() with defer_emails (mail captured, never
 *                 sent): no email during signup, apply_tenant_migrations has
 *                 nothing pending, and sendDeferredRegistrationEmails() sends the
 *                 welcome to the owner's EMAIL (not the username) plus one notice
 *                 per superadmin. The test company is removed afterwards.
 */

$root = dirname(__DIR__);

// Capture mail — tests never send real email. Defined before the mailer loads
// (both functions are function_exists-guarded).
$GLOBALS['__sent'] = [];
function sendEmail($to, string $subject, string $htmlBody, array $opts = []): bool { $GLOBALS['__sent'][] = ['to' => (string)$to, 'subject' => $subject]; return true; }
function platformMailerOpts(): array {
    return ['configured' => true, 'opts' => ['smtp' => ['host' => 'smtp.invalid', 'port' => 587, 'username' => 'x', 'password' => 'x',
        'encryption' => 'tls', 'from_email' => 'noreply@invalid', 'from_name' => 'Test']]];
}

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_migration_runner.php";
require_once "$root/core/tenant_registration.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 90) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('A. Static');
foreach (['register.php', 'actions/register_tenant.php', 'actions/register_status.php', 'actions/register_after.php',
          'core/tenant_registration.php', 'scripts/build_tenant_template.php'] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass("lint $f") : fail("php -l $f: " . implode(' ', $out));
}
$page = src($root, 'register.php');
has($page, "fd.append('reg_token', token);", 'page sends a per-signup reg_token');
has($page, "$.getJSON('/actions/register_status.php', { token: token })", 'lost answer → page asks register_status.php');
has($page, "if (st.state === 'done') { finish(); return created(st.response); }", 'status "done" shows the normal ready screen');
has($page, "navigator.sendBeacon('/actions/register_after.php');", 'ready screen beacons register_after.php for the emails');
has(src($root, 'actions/register_tenant.php'), "'defer_emails'             => true,", 'web signup defers its emails');

// The template must already include every tenant migration.
$seed = src($root, 'schema/tenant_seed_defaults.sql');
$missing = [];
foreach (tenantMigrationFiles() as $f) if (strpos($seed, "'" . basename($f) . "'") === false) $missing[] = basename($f);
$missing ? fail('template is missing ' . count($missing) . ' migration(s) — run scripts/build_tenant_template.php: ' . implode(', ', array_slice($missing, 0, 5)))
         : pass('template records all ' . count(tenantMigrationFiles()) . ' tenant migrations (none re-run at signup)');

// ─────────────────────────────────────────────────────────────────────────
section('B. Live signup (mail captured)');
putenv('TENANT_MODE=on');
if (!tenantBaseDomain()) putenv('TENANT_BASE_DOMAIN=bmstest.local');
if ($why = selfRegistrationClosedReason()) { echo "  \033[33m⚠ signup unavailable here ($why) — skipping live checks\033[0m\n"; exit; }

$sub   = 'artfast' . substr(bin2hex(random_bytes(3)), 0, 5);
$email = "owner.$sub@example.test";
$tid   = 0;
try {
    $t0 = microtime(true);
    $r = registerTenant([
        'company_name' => 'Fast Signup Co', 'subdomain' => $sub, 'owner_username' => 'Fastowner',
        'owner_phone' => '2557' . random_int(10000000, 99999999), 'owner_email' => $email,
        'owner_password' => 'Wambura@123', 'owner_password_confirm' => 'Wambura@123',
        'owner_first_name' => 'Fast', 'owner_last_name' => 'Owner', 'defer_emails' => true,
    ], '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250));
    $secs = microtime(true) - $t0;
    $tid = (int)($r['tenant_id'] ?? 0);
    $r['ok'] ? pass(sprintf('company created in %.1f s', $secs)) : fail('registerTenant failed: ' . ($r['error'] ?? '?'));
    if (!$r['ok']) exit;

    $GLOBALS['__sent'] === [] ? pass('no email sent while the visitor waits') : fail('emails sent during signup: ' . count($GLOBALS['__sent']));
    $mig = getControlPdo()->prepare("SELECT message FROM tenant_provisioning_log WHERE tenant_id = ? AND step = 'apply_tenant_migrations'");
    $mig->execute([$tid]);
    ($m = (string)$mig->fetchColumn()) === 'no pending migrations' ? pass('no migrations re-run at signup') : fail("apply_tenant_migrations: $m");

    $d = $r['deferred_emails'] ?? null;
    is_array($d) && $d['owner_email'] === $email && !empty($d['welcome']) ? pass('result carries the deferred emails for the owner') : fail('deferred_emails missing or wrong');

    sendDeferredRegistrationEmails($d);
    $to = array_column($GLOBALS['__sent'], 'to');
    in_array($email, $to, true) ? pass("welcome goes to the owner's email ($email)") : fail('welcome not sent to owner email: ' . implode(', ', $to));
    !in_array('Fastowner', $to, true) ? pass('welcome no longer addressed to the username') : fail('welcome still addressed to the username');
    $admins = getControlPdo()->query("SELECT email FROM superadmins")->fetchAll(PDO::FETCH_COLUMN);
    $notices = array_filter($GLOBALS['__sent'], fn($m) => str_starts_with($m['subject'], 'New company registered'));
    count($notices) === count($admins) ? pass('one notice per superadmin (' . count($admins) . ')') : fail('superadmin notices: ' . count($notices) . ' for ' . count($admins) . ' superadmins');
} finally {
    if ($tid) {
        try { getProvisioningPdo()->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); getProvisioningPdo()->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        $c = getControlPdo();
        foreach (['tenant_admin_log', 'tenant_provisioning_log', 'tenant_migration_log', 'vendor_link_queue', 'tenant_features'] as $tb) {
            try { $c->prepare("DELETE FROM $tb WHERE tenant_id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]);
    }
}
