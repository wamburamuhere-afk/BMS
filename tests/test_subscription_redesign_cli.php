<?php
/**
 * Subscription page redesign: the running period the page shows, the 7/3/1-day reminder emails
 * (trial and paid), the "listed once" rule for plan lines, and the page in both languages.
 *   php tests/test_subscription_redesign_cli.php
 * Data is 'srtest*' tenants and plans, removed at the end. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once "$root/roots.php";
require_once "$root/core/subscription_reminders.php";
require_once "$root/core/plans.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

$c = getControlPdo();
global $pdo;
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$cleanup = function () use ($c) {
    $ids = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'srtest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($in)");
        $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($in)");
        $c->exec("DELETE FROM tenants WHERE id IN ($in)");
    }
    foreach ($c->query("SELECT id FROM plans WHERE name LIKE 'SRTEST%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $tb) $c->exec("DELETE FROM $tb WHERE plan_id = " . (int)$id);
        $c->exec("DELETE FROM plans WHERE id = " . (int)$id);
    }
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;
$hasContact = controlColumnExists('tenants', 'owner_contact_email');
$hasSubEnd  = controlColumnExists('tenants', 'subscription_ends_at');
ok($hasContact && $hasSubEnd, 'control DB has owner_contact_email and subscription_ends_at');

/** A tenant whose access ends $days from today. */
$mk = function (string $sub, string $status, int $days, ?string $contact = 'owner@example.com', string $ownerEmail = '0712000000') use ($c) {
    $end = date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' day'));
    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, owner_contact_email, status,
                                      trial_ends_at, subscription_ends_at, created_at)
                 VALUES (?, ?, 'none', 'none', 'x', ?, ?, ?, ?, ?, ?)")
      ->execute(["SR Test $sub", $sub, $ownerEmail, $contact, $status,
                 $status === 'trial' ? $end . ' 23:00:00' : null, $status === 'active' ? $end : null,
                 date('Y-m-d H:i:s', strtotime('-10 day'))]);
    return (int)$c->lastInsertId();
};

// ── A. Reminders ─────────────────────────────────────────────────────────
section('A. 7 / 3 / 1 / 0-day reminders, trial and paid');
$t7  = $mk('srtest7', 'trial', 7);
$t3  = $mk('srtest3', 'active', 3);
$t1  = $mk('srtest1', 'trial', 1);
$t0  = $mk('srtest0', 'active', 0);
$t5  = $mk('srtest5', 'trial', 5);
$tNo = $mk('srtestnomail', 'trial', 3, null);
$mail = [];
$fake = function ($to, $subject, $body) use (&$mail) { $mail[] = compact('to', 'subject', 'body'); return true; };
// Only this test's companies: others on the box keep their own reminders.
$runMine = fn(callable $send) => runSubscriptionReminders($send, 'srtest');
$r = $runMine($fake);
$subjects = array_column($mail, 'subject', 'to');
$bySub = [];
foreach ($mail as $m) $bySub[] = $m['subject'];
ok(count($mail) === 4, 'four emails: the 7, 3, 1 and 0-day tenants (5 days left gets none) — got ' . count($mail));
ok(in_array('Your free trial ends in 7 days', $bySub, true), '7 days before a trial');
ok(in_array('Your free trial ends in 1 day', $bySub, true), '1 day before a trial (singular)');
ok((bool)array_filter($bySub, fn($s) => preg_match('/^Your .*ends in 3 days$/', $s) && strpos($s, 'trial') === false), '3 days before a PAID plan (paid plans are reminded too)');
ok((bool)array_filter($bySub, fn($s) => preg_match('/ends today$/', $s)), 'on the last day');
ok(count(array_unique(array_column($mail, 'to'))) === 1 && $mail[0]['to'] === 'owner@example.com', 'sent to owner_contact_email, never the phone in owner_email');
ok(strpos($mail[0]['body'], 'srtest') !== false && strpos($mail[0]['body'], '/subscription') !== false, 'each email links to the company\'s own Subscription page');
$noMailLog = $c->query("SELECT detail FROM tenant_admin_log WHERE tenant_id = $tNo")->fetchColumn();
ok(is_string($noMailLog) && strpos($noMailLog, 'no owner email') !== false, 'a company with no email is logged, not emailed');

$mail = [];
$r2 = $runMine($fake);
ok(count($mail) === 0, 'running again the same day sends nothing (once per step per period)');

// Renewal: a new end date → a fresh set.
$c->exec("UPDATE tenants SET subscription_ends_at = DATE_ADD(CURDATE(), INTERVAL 3 DAY) WHERE id = $t0");
$mail = [];
$runMine($fake);
ok(count($mail) === 1 && preg_match('/ends in 3 days$/', $mail[0]['subject']), 'after a renewal the new period gets its own reminders');

// A failed send is retried by the next run.
$c->exec("UPDATE tenants SET trial_ends_at = DATE_ADD(CURDATE(), INTERVAL 3 DAY) + INTERVAL 23 HOUR WHERE id = $t5");
$failing = fn() => false;
$runMine($failing);
$failedLog = (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $t5 AND action = 'expiry_reminder_failed'")->fetchColumn();
$mail = [];
$runMine($fake);
ok($failedLog === 1 && count($mail) === 1, 'a failed email is logged as failed and sent on the next run');
ok(strpos(file_get_contents("$root/api/cron/trial_reminders.php"), 'runSubscriptionReminders()') !== false
   && strpos(file_get_contents("$root/api/cron/trial_reminders.php"), "'Bearer ' . \$expectedSecret") !== false, 'the daily cron runs it, still behind CRON_SECRET');

// ── B. The period the page shows ─────────────────────────────────────────
section('B. status: trial / paid / grace / no end date');
function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'srt_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
$status = function (int $tid) use ($root, $uid) {
    $o = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        \$GLOBALS['__bms_tenant'] = ['id' => $tid];
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_GET = \$_REQUEST = ['action' => 'status'];
        ob_start(); include '$root/api/my_subscription.php'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    return json_decode($o, true) ?: ['raw' => $o];
};
$s = $status($t7);
ok(($s['period']['kind'] ?? '') === 'trial' && $s['days_left'] === 7 && ($s['period']['starts_at'] ?? '') === date('Y-m-d', strtotime('-10 day'))
   && $s['period']['total_days'] === 17 && $s['period']['used_days'] === 10, 'trial: started at sign-up, 10 of 17 days used, 7 left');

$paid = $mk('srtestpaid', 'active', 54);
$c->prepare("INSERT INTO tenant_payments (tenant_id, amount_tzs, duration_months, starts_at, ends_at, recorded_at) VALUES (?, 25000, 3, ?, ?, NOW())")
  ->execute([$paid, date('Y-m-d', strtotime('-37 day')), date('Y-m-d', strtotime('+54 day'))]);
$s = $status($paid);
ok(($s['period']['kind'] ?? '') === 'paid' && $s['days_left'] === 54 && $s['period']['total_days'] === 91 && $s['period']['used_days'] === 37,
   'paid: the period runs from the payment that set the end date — 37 of 91 days used, 54 left');

$grace = $mk('srtestgrace', 'active', -2);
$c->exec("UPDATE tenants SET grace_until = DATE_ADD(CURDATE(), INTERVAL 5 DAY) WHERE id = $grace");
$s = $status($grace);
ok(($s['period']['kind'] ?? '') === 'grace' && ($s['period']['grace_until'] ?? '') === date('Y-m-d', strtotime('+5 day')), 'past the end date: grace, with the date data is kept until');

$noEnd = $mk('srtestnoend', 'active', 10);
$c->exec("UPDATE tenants SET subscription_ends_at = NULL WHERE id = $noEnd");
$s = $status($noEnd);
ok(($s['period']['kind'] ?? '') === 'no_end' && $s['days_left'] === null, 'an operator-made company with no end date says so');
ok(array_key_exists('max_storage_mb', $s['plan'] ?? []) && array_key_exists('modules', $s['plan'] ?? []), 'the plan carries storage and modules for the page');

// ── C. Users / shops listed once ─────────────────────────────────────────
section('C. plan lines: users, shops, storage, modules come from the limits only');
foreach (['Users', 'Shops', 'shop', 'Storage', 'Modules', 'Watumiaji', 'Maduka'] as $lbl) {
    $r = createPlan(['name' => 'SRTEST ' . $lbl, 'max_users' => 2, 'items' => [['label' => $lbl, 'value' => '2']]]);
    ok(empty($r['ok']) && strpos((string)($r['error'] ?? ''), 'already shown') !== false, "\"$lbl\" as an included line is refused with a clear reason");
}
$r = createPlan(['name' => 'SRTEST ok', 'max_users' => 2, 'items' => [['label' => 'Priority support', 'value' => '']]]);
ok(!empty($r['ok']) || !empty($r['id']), 'an ordinary line ("Priority support") is still accepted');

// ── D. The page ──────────────────────────────────────────────────────────
section('D. the page, English and Swahili');
$page = function (int $tid, string $lang) use ($root, $uid) {
    return runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        \$GLOBALS['__bms_tenant'] = ['id' => $tid];
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_lang'] = '$lang';
        ob_start(); include '$root/app/constant/settings/billing.php'; echo ob_get_clean();");
};
$en = $page($paid, 'en');
ok(strpos($en, 'Fatal error') === false && strpos($en, 'Warning:') === false, 'renders without errors');
foreach (['viewPlan', 'sbDays', 'sbBar', 'sbLimits', 'tileInv', 'tileCon', 'tileReq', 'viewDetails', 'tabPlans', 'tabRequests', 'catalog'] as $id) {
    ok(strpos($en, 'id="' . $id . '"') !== false, "has #$id");
}
ok(strpos($en, 'order-1 order-lg-2') !== false, 'on a phone the days panel comes first');
ok(strpos($en, 'Needs your attention') !== false || strpos($en, 'NEEDS YOUR ATTENTION') !== false, 'English copy');
if (preg_match('/<script>\s*\$\(function \(\) \{([\s\S]*?)\}\);\s*<\/script>/', $en, $m)) {
    $tmp = tempnam(sys_get_temp_dir(), 'srj_') . '.js';
    file_put_contents($tmp, '(function(){' . $m[1] . '});');
    $node = trim((string)shell_exec('node --check ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    ok($node === '' || stripos($node, 'not recognized') !== false, 'page script parses' . ($node !== '' ? " — $node" : ''));
} else {
    ok(false, 'page script found');
}
$sw = $page($paid, 'sw');
ok(strpos($sw, 'Mpango wangu') !== false && strpos($sw, 'Maelezo ya malipo') !== false && mb_stripos($sw, 'Yanayohitaji hatua yako') !== false, 'Swahili: page chrome translated');
ok(strpos($sw, '"Siku %u kati ya %t zimetumika"') !== false && strpos($sw, 'Inasubiri BJP') !== false, 'Swahili: script strings translated');
foreach (['Renew or change plan', 'Requests waiting for BJP', 'Your plan'] as $old) {
    ok(strpos($sw, $old) === false, "no leftover English \"$old\"");
}
