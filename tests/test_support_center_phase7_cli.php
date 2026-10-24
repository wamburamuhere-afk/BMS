<?php
/**
 * tests/test_support_center_phase7_cli.php — Help & Support Center, Phase 7 + END-TO-END
 * (support_center_plan.md): notices, login-page help, blocked-account contacts, the AI
 * assistant limited to what each user may open, and the whole request journey through
 * the real endpoints (staff → admin → forward → BJP → resolve → rate).
 *
 *   php tests/test_support_center_phase7_cli.php
 *
 * CLI ONLY. Email is captured (SUPPORT_MAIL_CAPTURE_FILE), never sent. Snapshots and
 * restores everything it touches.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

// Tenant-side worker (company user session).
if (($argv[1] ?? '') === 'tworker') {
    $cfg = json_decode(file_get_contents($argv[3]), true);
    define('SUPPORT_MAIL_CAPTURE_FILE', $cfg['capture']);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    require_once "$root/roots.php";
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/{$argv[2]}";
    exit;
}
// Superadmin worker.
if (($argv[1] ?? '') === 'sworker') {
    $cfg = json_decode(file_get_contents($argv[3]), true);
    define('SUPPORT_MAIL_CAPTURE_FILE', $cfg['capture']);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = $cfg['host'];
    $_SERVER['REQUEST_URI'] = '/' . $argv[2];
    require_once "$root/core/superadmin_auth.php";
    require_once "$root/helpers.php";
    superadminSessionReady();
    require_once "$root/core/control_db.php";
    $_SESSION['superadmin_id'] = (int)$cfg['agent_id'];
    $post = $cfg['post'];
    if (!isset($post['_csrf'])) $post['_csrf'] = csrf_token();
    $_POST = $post;
    require "$root/{$argv[2]}";
    exit;
}
// Superadmin page render.
if (($argv[1] ?? '') === 'sroute') {
    $_SERVER['HTTP_HOST'] = (string)$argv[2];
    $uri = (string)$argv[3];
    $_SERVER['REQUEST_URI'] = $uri; $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING'] = (string)parse_url($uri, PHP_URL_QUERY);
    parse_str($_SERVER['QUERY_STRING'], $_GET);
    require_once "$root/roots.php";
    require_once "$root/core/superadmin_auth.php";
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    ob_start(); handleRoute(); fwrite(STDOUT, ob_get_clean());
    exit;
}
// Blocked-account page.
if (($argv[1] ?? '') === 'halt') {
    require_once "$root/includes/config.php";
    require_once "$root/core/tenant_bootstrap.php";
    bmsTenantHalt((int)$argv[2], 'Account suspended', 'This account is currently suspended. <b>x</b>');
    exit;
}
// Login page.
if (($argv[1] ?? '') === 'login') {
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/login';
    chdir($root);
    require_once "$root/roots.php";   // the router loads this before every page, login included
    require "$root/login.php";
    exit;
}

$CAP = tempnam(sys_get_temp_dir(), 'mail');
define('SUPPORT_MAIL_CAPTURE_FILE', $CAP);
define('SUPPORT_TEST_UPLOADS', true);
require_once "$root/roots.php";
require_once "$root/core/support_notices.php";
require_once "$root/core/support_notify.php";
require_once "$root/core/support_inbox.php";
require_once "$root/core/ai_insights.php";
global $pdo;
$c = getControlPdo();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function mails(): array { global $CAP; $o = []; foreach (file($CAP, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) $o[] = json_decode($l, true); return $o; }
function clearMails(): void { global $CAP; file_put_contents($CAP, ''); }
function json1(string $o): ?array { $s = strpos($o, '{'); if ($s === false) return null; $e = strpos($o, '}', $s); $j = json_decode(substr($o, $s, ($e === false ? strlen($o) : $e + 1) - $s), true); if (!is_array($j)) { $j = json_decode(substr($o, $s), true); } return is_array($j) ? $j : null; }
function tcall(string $file, array $post, array $session, string $method = 'POST'): array {
    global $CAP;
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode(['session' => $session, 'post' => $post, 'method' => $method, 'capture' => $CAP]));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' tworker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return ['raw' => $o, 'json' => json1($o)];
}
function scall(string $file, array $post, int $agentId, ?string $host = null): array {
    global $CAP, $SA_HOST;
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode(['post' => $post, 'agent_id' => $agentId, 'host' => $host ?? $SA_HOST, 'capture' => $CAP]));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' sworker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return ['raw' => $o, 'json' => json1($o)];
}
function sroute(string $uri): string { global $SA_HOST; return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' sroute ' . escapeshellarg($SA_HOST) . ' ' . escapeshellarg($uri) . ' 2>&1'); }

echo "\nBMS — Help & Support Center, Phase 7 + end-to-end\n";
require_once "$root/core/superadmin_auth.php";
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$SA_HOST = superadminHostLabel() . '.' . $BASE;

// ── Snapshot + fixtures ──────────────────────────────────────────────────────
$tables = ['support_tickets', 'support_ticket_messages', 'support_attachments', 'support_notices', 'support_notice_dismissals', 'support_articles', 'support_categories'];
$snaps = [];
foreach ($tables as $t) $snaps[$t] = $c->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
$setKeys = ['support_email', 'support_phone', 'support_whatsapp', 'support_hours', 'support_login_help', 'support_recipients'];
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
foreach (['support_tickets', 'support_ticket_messages', 'support_attachments', 'support_notices', 'support_notice_dismissals'] as $t) $c->exec("DELETE FROM $t");

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$pdo->exec("DELETE FROM users WHERE username = '__e2e_staff'");
$pdo->exec("DELETE FROM roles WHERE role_name = '__e2e_role'");
$pdo->exec("INSERT INTO roles (role_name, is_admin, description, created_at, updated_at) VALUES ('__e2e_role', 0, 'phase 7 test', NOW(), NOW())");
$role = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password, created_at) VALUES ('__e2e_staff', 'e2estaff@example.test', 'Juma', 'Staff', ?, 1, 'x', NOW())")->execute([$role]);
$staffUid = (int)$pdo->lastInsertId();
register_shutdown_function(function () use ($pdo, $staffUid, $role) { $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$staffUid]); $pdo->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$role]); });
$agentId = (int)$c->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetchColumn();
$agentEmail = (string)$c->query("SELECT email FROM superadmins WHERE id = $agentId")->fetchColumn();
$TOKEN = bin2hex(random_bytes(16));
$S_ADMIN = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin', 'csrf_token' => $TOKEN];
$S_STAFF = ['user_id' => $staffUid, 'username' => '__e2e_staff', 'is_admin' => false, 'role_id' => $role, 'role' => 'Staff', 'csrf_token' => $TOKEN, 'permissions' => []];

// ─────────────────────────────────────────────────────────────────────────────
section('1. Lint');
foreach (['core/support_notices.php', 'core/support.php', 'core/tenant_bootstrap.php', 'core/ai_insights.php', 'login.php', 'header.php',
          'includes/help_panel.php', 'api/support_help.php', 'app/superadmin/support.php', 'actions/superadmin_support_inbox.php', 'lang/sw.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. Notices — validation');
$good = ['title' => 'Maintenance tonight', 'body' => 'BMS may be unavailable for 15 minutes.', 'level' => 'maintenance', 'target' => 'all', 'starts_at' => date('Y-m-d\TH:i', time() - 60), 'ends_at' => ''];
ok('valid notice accepted', supportValidateNotice($good, $c)['ok'], json_encode(supportValidateNotice($good, $c)['errors']));
ok('missing title refused', isset(supportValidateNotice(['title' => ''] + $good, $c)['errors']['title']));
ok('unknown level refused', isset(supportValidateNotice(['level' => 'panic'] + $good, $c)['errors']['level']));
ok('unknown target refused', isset(supportValidateNotice(['target' => 'everyone'] + $good, $c)['errors']['target']));
ok('product that does not exist refused', isset(supportValidateNotice(['target' => 'product', 'target_value' => 'nope'] + $good, $c)['errors']['target_value']));
ok('company that does not exist refused', isset(supportValidateNotice(['target' => 'tenant', 'target_value' => '99999999'] + $good, $c)['errors']['target_value']));
ok('end before start refused', isset(supportValidateNotice(['ends_at' => date('Y-m-d\TH:i', time() - 3600)] + $good, $c)['errors']['ends_at']));
ok('bad date refused', isset(supportValidateNotice(['starts_at' => 'tomorrow-ish'] + $good, $c)['errors']['starts_at']));
ok('"all" stores no target value', supportValidateNotice(['target_value' => 'x'] + $good, $c)['values']['target_value'] === null);

section('3. Notices — who sees them, dismiss, end');
$n1 = supportNoticeSave(supportValidateNotice($good, $c)['values'], null, $agentId);
$prod = (string)$c->query('SELECT product_key FROM platform_products LIMIT 1')->fetchColumn();
$n2 = supportNoticeSave(supportValidateNotice(['title' => 'Only for one product', 'target' => 'product', 'target_value' => $prod] + $good, $c)['values'], null, $agentId);
$future = supportNoticeSave(supportValidateNotice(['title' => 'Scheduled later', 'starts_at' => date('Y-m-d\TH:i', time() + 86400)] + $good, $c)['values'], null, $agentId);
$ids = fn(array $l) => array_map('intval', array_column($l, 'id'));
ok('"all" notice is live for any company', in_array($n1, $ids(supportActiveNotices(0, $staffUid, null, null)), true));
ok('product notice shows only for that product', in_array($n2, $ids(supportActiveNotices(0, $staffUid, $prod, null)), true) && !in_array($n2, $ids(supportActiveNotices(0, $staffUid, 'other_product', null)), true));
ok('scheduled notice not shown before it starts', !in_array($future, $ids(supportActiveNotices(0, $staffUid, $prod, null)), true));
ok('dismiss hides it for that user', supportDismissNotice($n1, 0, $staffUid) && !in_array($n1, $ids(supportActiveNotices(0, $staffUid, null, null)), true));
ok('...but not for another user', in_array($n1, $ids(supportActiveNotices(0, $adminUid, null, null)), true));
ok('dismissing twice is harmless; missing notice → false', supportDismissNotice($n1, 0, $staffUid) && !supportDismissNotice(99999999, 0, $staffUid));
ok('"End now" hides it for everyone, keeps the row', supportNoticeEnd($n2) && !in_array($n2, $ids(supportActiveNotices(0, $adminUid, $prod, null)), true) && (int)$c->query("SELECT COUNT(*) FROM support_notices WHERE id=$n2")->fetchColumn() === 1);
$xn = supportNoticeSave(supportValidateNotice(['title' => 'T <script>alert(4)</script>', 'body' => '<img src=x onerror=alert(4)>'] + $good, $c)['values'], null, $agentId);
$_SESSION = ['user_id' => $adminUid];
$banner = supportNoticeBanner();
ok('banner escapes title and message', $banner !== '' && !str_contains($banner, '<script>') && !str_contains($banner, '<img') && str_contains($banner, '&lt;script&gt;'));
$_SESSION = [];
ok('no banner when signed out', supportNoticeBanner() === '');
$r = tcall('api/support_help.php', ['action' => 'dismiss_notice', 'id' => $xn, '_csrf' => $TOKEN], $S_ADMIN);
ok('dismiss via the panel API (CSRF + session) works', ($r['json']['success'] ?? false) === true, substr($r['raw'], 0, 200));
ok('dismiss via the API without CSRF refused', str_contains(tcall('api/support_help.php', ['action' => 'dismiss_notice', 'id' => $xn, '_csrf' => 'bad'], $S_ADMIN)['raw'], 'CSRF'));
$sa = scall('actions/superadmin_support_inbox.php', ['action' => 'save_notice'] + $good, $agentId);
ok('superadmin save_notice action', ($sa['json']['success'] ?? false) === true, substr($sa['raw'], 0, 300));
ok('superadmin end_notice action', (scall('actions/superadmin_support_inbox.php', ['action' => 'end_notice', 'id' => (int)($sa['json']['id'] ?? 0)], $agentId)['json']['success'] ?? false) === true);
ok('save_notice refused on a tenant host', !str_contains(scall('actions/superadmin_support_inbox.php', ['action' => 'save_notice'] + $good, $agentId, 'sometenant.' . $BASE)['raw'], '"success":true'));
$nh = sroute('/support?tab=notices');
ok('Notices tab renders and escapes', str_contains($nh, 'New notice') && !str_contains($nh, '<script>alert(4)') && !str_contains($nh, 'Fatal error'));

section('4. Login page help');
setPlatformSetting('support_email', 'help@example.test');
setPlatformSetting('support_phone', '+255 700 000 001');
setPlatformSetting('support_login_help', 'Call us <b>any time</b>.');
$lp = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' login 2>&1');
ok('login page shows "Need help signing in?"', str_contains($lp, 'Need help signing in?') || str_contains($lp, 'Unahitaji msaada wa kuingia?'), substr($lp, 0, 200));
ok('who resets passwords is explained', str_contains($lp, 'company admin') || str_contains($lp, 'msimamizi wa kampuni'));
ok('BJP contacts shown', str_contains($lp, 'help@example.test') && str_contains($lp, '+255 700 000 001'));
ok('login help text escaped', !str_contains($lp, '<b>any time</b>') && str_contains($lp, '&lt;b&gt;any time&lt;/b&gt;'));
ok('footer "Help" link is no longer a dead #', !preg_match('~<a href="#">(Help Center|Msaada)</a>~', $lp));

section('5. Blocked-account page shows support contacts');
$h403 = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' halt 403 2>&1');
ok('403 page lists BJP contacts', str_contains($h403, 'Need help?') && str_contains($h403, 'help@example.test') && str_contains($h403, 'tel:+255700000001'));
ok('403 page still escapes its own message', str_contains($h403, '&lt;b&gt;x&lt;/b&gt;'));
$h404 = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' halt 404 2>&1');
ok('404 (no such company) page has no contacts', !str_contains($h404, 'Need help?'));

section('6. AI assistant limited to what the user may open');
$_SESSION = ['user_id' => $staffUid, 'role_id' => $role, 'is_admin' => false, 'permissions' => []];
$names = array_column(aiInsightCatalog(), 'name');
ok('user with no page rights: AI is offered only the help lookup', $names === ['search_help'], implode(',', $names));
$pay = aiRunInsight('payroll_summary');
ok('...and running payroll figures is refused in code', $pay['ok'] === false && str_contains((string)$pay['error'], 'not available'));
ok('...pending approvals is admin-only', !aiInsightAllowed('pending_approvals'));
ok('unknown function never allowed', !aiInsightAllowed('drop_everything'));
$_SESSION = ['user_id' => $adminUid, 'role_id' => 1, 'is_admin' => true, 'role' => 'admin'];
ok('admin: every function available', count(aiInsightCatalog()) === count(aiInsightRegistry()));
$c->exec("UPDATE support_articles SET status='published' WHERE title_en = 'Add a new {shop}'");
unset($GLOBALS['__support_published']);
$sh = aiRunInsight('search_help', ['query' => 'how do I add a new warehouse']);
ok('AI "how do I" answers from the published article, in this company\'s words', $sh['ok'] && !empty($sh['data']['help_articles']) && str_contains(json_encode($sh['data']), 'Add a new'), json_encode($sh));
$c->exec("UPDATE support_articles SET status='draft'");
unset($GLOBALS['__support_published']);
$_SESSION = [];
$panel = file_get_contents("$root/includes/help_panel.php");
ok('panel shows "Ask BMS" only when AI is configured AND the user may use it', str_contains($panel, "canView('ai_assistant') && function_exists('aiConfigured') && aiConfigured()"));

// ─────────────────────────────────────────────────────────────────────────────
section('7. END-TO-END — the whole journey through the real endpoints');
setPlatformSetting('support_recipients', '');
clearMails();
$api = 'api/support_requests.php';
// 1) Staff asks.
$r = tcall($api, ['action' => 'create', 'subject' => 'Cannot print receipts', 'body' => 'The printer shows an error since morning.', 'category' => 'problem', 'priority' => 'high', 'page_key' => 'pos', '_csrf' => $TOKEN], $S_STAFF);
$id = (int)($r['json']['id'] ?? 0);
ok('1. staff sends a request → HLP reference', $id > 0 && str_starts_with($r['json']['ref'] ?? '', 'HLP-'), substr($r['raw'], 0, 300));
$t = $c->query("SELECT * FROM support_tickets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
ok('   it is with the company admin, not BJP', $t['audience'] === 'company' && supportInboxGet($id) === null);
ok('   company admin notified in BMS', (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $adminUid AND event_key = 'support_request' AND action_url = 'help?request=$id'")->fetchColumn() >= 1);
// 2) Admin answers.
clearMails();
$r = tcall($api, ['action' => 'reply', 'id' => $id, 'body' => 'Restart the printer please.', '_csrf' => $TOKEN], $S_ADMIN);
ok('2. admin answers → waiting on the staff member', ($r['json']['success'] ?? false) && $c->query("SELECT status FROM support_tickets WHERE id = $id")->fetchColumn() === 'waiting_on_customer');
ok('   staff member emailed', (bool)array_filter(mails(), fn($m) => $m['to'] === 'e2estaff@example.test'));
// 3) Staff replies.
$r = tcall($api, ['action' => 'reply', 'id' => $id, 'body' => 'Still failing after restart.', '_csrf' => $TOKEN], $S_STAFF);
ok('3. staff replies → back to the admin', ($r['json']['success'] ?? false) && $c->query("SELECT status FROM support_tickets WHERE id = $id")->fetchColumn() === 'open');
// 4) Admin forwards.
clearMails();
$r = tcall($api, ['action' => 'forward', 'id' => $id, '_csrf' => $TOKEN], $S_ADMIN);
$sup = (string)($r['json']['ref'] ?? '');
ok('4. admin forwards → same request, SUP reference, now with BJP', str_starts_with($sup, 'SUP-') && supportInboxGet($id) !== null);
ok('   BJP emailed (every superadmin, as no recipients are set)', $agentEmail === '' || (bool)array_filter(mails(), fn($m) => $m['to'] === $agentEmail && str_contains($m['subject'], $sup)));
// 5) BJP internal note, then reply.
$r = scall('actions/superadmin_support_inbox.php', ['action' => 'reply', 'id' => $id, 'body' => 'INTERNAL: printer driver bug', 'internal' => '1'], $agentId);
ok('5a. BJP internal note saved', ($r['json']['success'] ?? false) === true, substr($r['raw'], 0, 200));
clearMails();
$r = scall('actions/superadmin_support_inbox.php', ['action' => 'reply', 'id' => $id, 'body' => 'Please update the printer driver from Settings.', 'internal' => '0', 'after' => 'waiting_on_customer'], $agentId);
ok('5b. BJP replies → waiting on the company, first reply stamped', ($r['json']['success'] ?? false) && supportInboxGet($id)['status'] === 'waiting_on_customer' && supportInboxGet($id)['first_response_at'] !== null);
ok('   the staff member who asked is emailed from BJP\'s relay', (bool)array_filter(mails(), fn($m) => $m['to'] === 'e2estaff@example.test' && $m['platform'] && str_contains($m['html'], 'printer driver')));
$staffView = supportRequestMessages($id);
ok('   the internal note is NOT in what the company sees', !in_array('INTERNAL: printer driver bug', array_column($staffView, 'body'), true));
// 6) Staff replies to BJP.
clearMails();
$r = tcall($api, ['action' => 'reply', 'id' => $id, 'body' => 'Updated, works now. Thank you!', '_csrf' => $TOKEN], $S_STAFF);
ok('6. staff replies → BJP owes the next step again', ($r['json']['success'] ?? false) && supportInboxGet($id)['status'] === 'in_progress');
ok('   the assigned agent is emailed', $agentEmail === '' || (bool)array_filter(mails(), fn($m) => $m['to'] === $agentEmail));
// 7) BJP resolves.
clearMails();
$r = scall('actions/superadmin_support_inbox.php', ['action' => 'set_status', 'id' => $id, 'status' => 'resolved'], $agentId);
ok('7. BJP resolves', ($r['json']['success'] ?? false) && supportInboxGet($id)['status'] === 'resolved');
ok('   the staff member is told it is resolved', (bool)array_filter(mails(), fn($m) => $m['to'] === 'e2estaff@example.test' && str_contains($m['subject'], 'Resolved')));
// 8) Staff rates.
$r = tcall($api, ['action' => 'rate', 'id' => $id, 'rating' => '5', 'comment' => 'Fast help', '_csrf' => $TOKEN], $S_STAFF);
$final = $c->query("SELECT * FROM support_tickets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
ok('8. staff rates 5 → closed, rating kept', ($r['json']['success'] ?? false) && $final['status'] === 'closed' && (int)$final['rating'] === 5);
ok('   whole history kept in order (7 public entries + 1 internal note)', count(supportRequestMessages($id)) >= 7 && count(supportRequestMessages($id, true)) === count(supportRequestMessages($id)) + 1);
ok('   a closed request takes no more replies', str_contains(tcall($api, ['action' => 'reply', 'id' => $id, 'body' => 'one more', '_csrf' => $TOKEN], $S_STAFF)['raw'], 'closed'));
ok('   nobody else in the company can open it', supportRequestGetForUser($id, ['tenant_id' => 0, 'user_id' => 999999, 'admin' => false]) === null);
ok('   every step is in the activity log', (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'support_request_%' AND description LIKE '%$id%'")->fetchColumn() >= 3);

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
