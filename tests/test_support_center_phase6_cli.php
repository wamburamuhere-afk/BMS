<?php
/**
 * tests/test_support_center_phase6_cli.php — Help & Support Center, Phase 6
 * (support_center_plan.md): who is notified of what, in BMS and by email; overdue
 * first-reply reminders; auto-closing resolved requests; the background runner.
 *
 *   php tests/test_support_center_phase6_cli.php
 *
 * CLI ONLY. Emails are CAPTURED to a temp file (SUPPORT_MAIL_CAPTURE_FILE), never sent.
 * Snapshots support tables + the settings it changes; removes the in-app rows it created.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    $cfg = json_decode(file_get_contents($argv[3]), true);
    define('SUPPORT_MAIL_CAPTURE_FILE', $cfg['capture']);
    define('SUPPORT_TEST_UPLOADS', true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    require_once "$root/roots.php";
    $_POST = $cfg['post'] ?? [];
    require "$root/{$argv[2]}";
    exit;
}

$CAP = tempnam(sys_get_temp_dir(), 'mail');
define('SUPPORT_MAIL_CAPTURE_FILE', $CAP);
define('SUPPORT_TEST_UPLOADS', true);
require_once "$root/roots.php";
require_once "$root/core/support_notify.php";
require_once "$root/core/support_inbox.php";
global $pdo;
$c = getControlPdo();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function mails(): array { global $CAP; $out = []; foreach (file($CAP, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) $out[] = json_decode($l, true); return $out; }
function clearMails(): void { global $CAP; file_put_contents($CAP, ''); }
function inapp(int $uid): array { global $pdo; $st = $pdo->prepare("SELECT title, action_url FROM notifications WHERE user_id = ? AND event_key = 'support_request' ORDER BY notification_id DESC"); $st->execute([$uid]); return $st->fetchAll(PDO::FETCH_ASSOC); }
function call(string $file, array $post, array $session): array {
    global $CAP;
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode(['session' => $session, 'post' => $post, 'capture' => $CAP]));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $s = strpos($o, '{'); $j = $s === false ? null : json_decode(substr($o, $s, (strpos($o, '}', $s) ?: strlen($o)) - $s + 1), true);
    return ['raw' => $o, 'json' => is_array($j) ? $j : null];
}

echo "\nBMS — Help & Support Center, Phase 6\n";

// ── Snapshot + fixtures ──────────────────────────────────────────────────────
$tables = ['support_tickets', 'support_ticket_messages', 'support_attachments'];
$snaps = [];
foreach ($tables as $t) $snaps[$t] = $c->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
$setKeys = ['support_recipients', 'support_autoreply', 'support_autoclose_days', 'support_jobs_last_ts'];
$setSnap = [];
foreach ($setKeys as $k) { $st = $c->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?'); $st->execute([$k]); $setSnap[$k] = $st->fetchColumn(); }
$maxNotif = (int)$pdo->query('SELECT COALESCE(MAX(notification_id),0) FROM notifications')->fetchColumn();
register_shutdown_function(function () use ($c, $pdo, $snaps, $setSnap, $maxNotif, $CAP) {
    foreach ($snaps as $t => $rows) {
        $c->exec("DELETE FROM $t");
        foreach ($rows as $r) { $cols = array_keys($r); $c->prepare("INSERT INTO $t (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($r)); }
    }
    foreach ($setSnap as $k => $v) {
        if ($v === false) $c->prepare('DELETE FROM platform_settings WHERE setting_key = ?')->execute([$k]);
        else $c->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$k, $v]);
    }
    $pdo->prepare("DELETE FROM notifications WHERE notification_id > ? AND event_key = 'support_request'")->execute([$maxNotif]);
    @unlink($CAP);
});
foreach ($tables as $t) $c->exec("DELETE FROM $t");
setPlatformSetting('support_recipients', 'agent1@example.test, agent2@example.test');
setPlatformSetting('support_autoreply', 'Thanks — we usually reply within one business day.');

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$adminEmail = (string)$pdo->query("SELECT email FROM users WHERE user_id = $adminUid")->fetchColumn();
$pdo->exec("DELETE FROM users WHERE username = '__n_staff'");
$pdo->exec("DELETE FROM roles WHERE role_name = '__n_role'");
$pdo->exec("INSERT INTO roles (role_name, is_admin, description, created_at, updated_at) VALUES ('__n_role', 0, 'phase 6 test', NOW(), NOW())");
$role = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password, created_at) VALUES ('__n_staff', 'nstaff@example.test', 'Neema', 'Staff', ?, 1, 'x', NOW())")->execute([$role]);
$staffUid = (int)$pdo->lastInsertId();
register_shutdown_function(function () use ($pdo, $staffUid, $role) { $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$staffUid]); $pdo->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$role]); });
$U_ADMIN = ['tenant_id' => 0, 'user_id' => $adminUid, 'name' => 'Company Admin', 'email' => 'coadmin@example.test', 'role' => 'Admin', 'admin' => true];
$U_STAFF = ['tenant_id' => 0, 'user_id' => $staffUid, 'name' => 'Neema Staff', 'email' => 'nstaff@example.test', 'role' => 'Staff', 'admin' => false];
$TOKEN = bin2hex(random_bytes(16));
$S_STAFF = ['user_id' => $staffUid, 'username' => '__n_staff', 'is_admin' => false, 'role_id' => $role, 'role' => 'Staff', 'csrf_token' => $TOKEN, 'permissions' => []];
$good = ['subject' => 'Till drawer will not open', 'body' => 'It worked yesterday.', 'category' => 'problem', 'priority' => 'normal'];

// ─────────────────────────────────────────────────────────────────────────────
section('1. Lint + no hard-coded domain');
foreach (['core/support_notify.php', 'api/support_requests.php', 'actions/superadmin_support_inbox.php', 'api/run_background_jobs.php', 'core/support.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}
$hard = [];
foreach (glob("$root/{core,api,actions,app/superadmin,includes}/*support*.php", GLOB_BRACE) as $f) if (preg_match('/bjptechnologies|\.co\.tz/i', file_get_contents($f))) $hard[] = basename($f);
ok('no support file contains a hard-coded domain', !$hard, implode(', ', $hard));
$unsafe = [];
foreach (glob("$root/tests/test_support_center_phase*_cli.php") as $f) {
    $s = file_get_contents($f);
    if ((str_contains($s, 'api/support_requests.php') || str_contains($s, 'superadmin_support_inbox.php') || str_contains($s, 'run_background_jobs.php'))
        && !str_contains($s, "define('SUPPORT_MAIL_CAPTURE_FILE'")) $unsafe[] = basename($f);
}
ok('every support test that can trigger email captures it (no real mail from tests)', !$unsafe, implode(', ', $unsafe));

section('2. Staff asks their admin');
clearMails();
$r = supportRequestCreate($good, $U_STAFF, []);
supportOnCreated($r['id'], $good['body']);
$m = mails();
ok('company admins notified in BMS', count(inapp($adminUid)) >= 1 && str_contains(inapp($adminUid)[0]['title'], 'Neema Staff'));
ok('in-app link opens the request', inapp($adminUid)[0]['action_url'] === 'help?request=' . $r['id']);
ok('the requester is not notified about their own request', inapp($staffUid) === []);
ok('admins emailed through the company\'s own mail (not BJP\'s relay)', $adminEmail === '' || (count(array_filter($m, fn($x) => $x['to'] === $adminEmail && $x['platform'] === false)) === 1), json_encode($m));
ok('BJP is NOT told about a staff-to-admin request', !array_filter($m, fn($x) => str_ends_with($x['to'], '@example.test') && str_starts_with($x['to'], 'agent')));

section('3. Admin answers, staff replies');
clearMails();
supportRequestReply($r['id'], $U_ADMIN, 'Check the cable.');
supportOnTenantReply($r['id'], $adminUid, 'Company Admin', 'Check the cable.');
ok('staff member notified in BMS', count(inapp($staffUid)) === 1 && str_contains(inapp($staffUid)[0]['title'], 'answered'));
ok('staff member emailed', count(array_filter(mails(), fn($x) => $x['to'] === 'nstaff@example.test')) === 1);
clearMails();
$before = count(inapp($adminUid));
supportRequestReply($r['id'], $U_STAFF, 'Still stuck.');
supportOnTenantReply($r['id'], $staffUid, 'Neema Staff', 'Still stuck.');
ok('staff reply → admins notified again', count(inapp($adminUid)) === $before + 1);

section('4. Admin forwards to BJP');
clearMails();
supportRequestForward($r['id'], $U_ADMIN, 'Please help');
supportOnForwarded($r['id']);
$m = mails();
$toBjp = array_filter($m, fn($x) => in_array($x['to'], ['agent1@example.test', 'agent2@example.test'], true));
ok('both Support › Settings recipients emailed through BJP\'s relay', count($toBjp) === 2 && !array_filter($toBjp, fn($x) => !$x['platform']));
ok('BJP email subject carries the new SUP reference', (bool)array_filter($toBjp, fn($x) => str_contains($x['subject'], 'SUP-') && str_contains($x['subject'], 'Forwarded')));
ok('staff member told it went to the support team (no "BJP" wording)', str_contains(inapp($staffUid)[0]['title'], 'support team') && !str_contains(inapp($staffUid)[0]['title'], 'BJP'));

section('5. Admin asks BJP directly — auto-reply and safety');
clearMails();
$evil = supportRequestCreate(['subject' => "Bad \r\nBcc: victim@example.test", 'body' => '<script>alert(1)</script> & <b>bold</b>'] + $good, $U_ADMIN, []);
supportOnCreated($evil['id'], '<script>alert(1)</script> & <b>bold</b>');
$m = mails();
ok('no subject contains a line break (no header injection)', !array_filter($m, fn($x) => preg_match('/[\r\n]/', $x['subject'])));
ok('message body escaped in emails', !array_filter($m, fn($x) => str_contains($x['html'], '<script>')) && (bool)array_filter($m, fn($x) => str_contains($x['html'], '&lt;script&gt;')));
$auto = array_values(array_filter($m, fn($x) => $x['to'] === 'coadmin@example.test'));
ok('requester gets the auto-reply from BJP with the reference', count($auto) === 1 && $auto[0]['platform'] && str_contains($auto[0]['html'], 'we usually reply') && str_contains($auto[0]['subject'], 'SUP-'));
ok('invalid recipient addresses are skipped', supportSendMail('not-an-email', 'x', 'y', true) === false);

section('6. BJP replies / resolves → the person who asked');
clearMails();
$AG = ['id' => (int)$c->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetchColumn(), 'name' => 'Agent'];
supportInboxReply($r['id'], $AG, 'Please send a photo of the drawer.', false);
supportOnAgentUpdate($r['id'], 'reply', 'Please send a photo of the drawer.');
$m = mails();
ok('staff requester emailed through BJP\'s relay', count(array_filter($m, fn($x) => $x['to'] === 'nstaff@example.test' && $x['platform'])) === 1);
ok('staff requester notified in BMS as "Support" (never "BJP")', str_contains(inapp($staffUid)[0]['title'], 'Support replied') && !str_contains(inapp($staffUid)[0]['title'], 'BJP'));
ok('email quotes the reply', (bool)array_filter($m, fn($x) => str_contains($x['html'], 'photo of the drawer')));
clearMails();
supportOnAgentUpdate($r['id'], 'resolved');
ok('resolved → email says it reopens on reply', (bool)array_filter(mails(), fn($x) => str_contains($x['html'], 'reopen')));
clearMails();
supportOnAgentUpdate((int)supportRequestCreate(['subject' => 'stays inside'] + $good, $U_STAFF, [])['id'], 'reply', 'x');
ok('agent update on a request that never reached BJP sends nothing', mails() === []);
clearMails();
supportInboxReply($r['id'], $AG, 'internal only', true);
ok('internal notes trigger no email (the action never calls the notifier for them)', mails() === [] && str_contains(file_get_contents("$root/actions/superadmin_support_inbox.php"), "if (\$internal) si_out(200"));

section('7. Company replies on a BJP request → assigned agent');
clearMails();
$c->prepare('UPDATE support_tickets SET assigned_to = ? WHERE id = ?')->execute([$AG['id'], $r['id']]);
supportOnTenantReply($r['id'], $staffUid, 'Neema Staff', 'Here is the photo.');
$agentEmail = (string)$c->query('SELECT email FROM superadmins WHERE id = ' . $AG['id'])->fetchColumn();
ok('only the assigned agent is emailed', count(mails()) === 1 && mails()[0]['to'] === $agentEmail, json_encode(mails()));

section('8. Overdue first-reply reminders (once)');
clearMails();
$late = supportRequestCreate(['subject' => 'Urgent: cannot sell', 'priority' => 'urgent'] + $good, $U_ADMIN, []);
$c->prepare('UPDATE support_tickets SET created_at = NOW() - INTERVAL 10 DAY WHERE id = ?')->execute([$late['id']]);
$fresh = supportRequestCreate(['subject' => 'Not late yet', 'priority' => 'low'] + $good, $U_ADMIN, []);
$n1 = supportRunSlaReminders();
ok('reminder sent for the overdue request', $n1 >= 1 && (bool)array_filter(mails(), fn($x) => str_contains($x['subject'], 'First reply overdue') && str_contains($x['subject'], 'cannot sell')));
ok('no reminder for the request still within target', !array_filter(mails(), fn($x) => str_contains($x['subject'], 'Not late yet')));
clearMails();
ok('second run sends nothing (reminded once)', supportRunSlaReminders() === 0 && mails() === []);
ok('reminder time recorded', $c->query('SELECT sla_reminded_at FROM support_tickets WHERE id = ' . (int)$late['id'])->fetchColumn() !== null);

section('9. Auto-close resolved requests');
setPlatformSetting('support_autoclose_days', '5');
$old = supportRequestCreate(['subject' => 'Old resolved'] + $good, $U_STAFF, []);
$c->prepare("UPDATE support_tickets SET status='resolved', resolved_at = NOW() - INTERVAL 6 DAY WHERE id = ?")->execute([$old['id']]);
$newer = supportRequestCreate(['subject' => 'Recently resolved'] + $good, $U_STAFF, []);
$c->prepare("UPDATE support_tickets SET status='resolved', resolved_at = NOW() - INTERVAL 2 DAY WHERE id = ?")->execute([$newer['id']]);
$closedN = supportRunAutoClose();
ok('request resolved 6 days ago is closed', $closedN >= 1 && $c->query('SELECT status FROM support_tickets WHERE id = ' . (int)$old['id'])->fetchColumn() === 'closed');
ok('request resolved 2 days ago stays resolved', $c->query('SELECT status FROM support_tickets WHERE id = ' . (int)$newer['id'])->fetchColumn() === 'resolved');
ok('closing line added to the conversation', in_array('Closed automatically after 5 days with no reply.', array_column(supportRequestMessages((int)$old['id']), 'body'), true));
ok('requester told in BMS', str_contains(inapp($staffUid)[0]['title'], 'closed'));
ok('running again closes nothing new', supportRunAutoClose() === 0);

section('10. Through the real endpoint: answer first, then notify');
clearMails();
$api = call('api/support_requests.php', ['action' => 'create', 'subject' => 'Via API', 'body' => 'Endpoint body', 'category' => 'how_to', 'priority' => 'low', '_csrf' => $TOKEN], $S_STAFF);
ok('client receives the JSON answer', ($api['json']['success'] ?? false) === true && str_starts_with($api['json']['ref'] ?? '', 'HLP-'), substr($api['raw'], 0, 300));
ok('...and the notification still went out after it', $adminEmail === '' || (bool)array_filter(mails(), fn($x) => $x['to'] === $adminEmail && str_contains($x['subject'], 'Via API')));
$src = file_get_contents("$root/core/support_notify.php");
ok('response is closed before notifying (Content-Length + Connection: close + fastcgi_finish_request)', str_contains($src, "header('Connection: close')") && str_contains($src, 'fastcgi_finish_request'));

section('11. Background runner');
$bg = file_get_contents("$root/api/run_background_jobs.php");
ok('runner calls the support jobs, platform-throttled to 10 minutes, guarded', str_contains($bg, 'supportRunSlaReminders()') && str_contains($bg, 'supportRunAutoClose()') && str_contains($bg, "support_jobs_last_ts") && str_contains($bg, '>= 600'));
setPlatformSetting('support_jobs_last_ts', '0');
$run = call('api/run_background_jobs.php', [], $S_STAFF);
ok('runner reports support_jobs ran', str_contains($run['raw'], 'support_jobs'), substr($run['raw'], 0, 300));
$run2 = call('api/run_background_jobs.php', [], $S_STAFF);
ok('runner does not run them again within 10 minutes', !str_contains($run2['raw'], 'support_jobs'));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
