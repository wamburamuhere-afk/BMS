<?php
/**
 * tests/test_support_center_phase4_cli.php — Help & Support Center, Phase 4
 * (support_center_plan.md): sending requests, staff → admin → BJP routing, the
 * request lifecycle, attachments, access isolation, API and pages.
 *
 *   php tests/test_support_center_phase4_cli.php
 *
 * CLI ONLY. Snapshots the support request tables and removes every file it stored.
 * SUPPORT_TEST_UPLOADS lets this CLI process use ordinary temp files in place of
 * real HTTP uploads (is_uploaded_file() is always false on the CLI); it is defined
 * only here, never by the application.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    // Never send real email from a test: capture it to a throwaway file.
    define('SUPPORT_MAIL_CAPTURE_FILE', tempnam(sys_get_temp_dir(), 'mail'));
    $cfg = json_decode(file_get_contents($argv[3]), true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    require_once "$root/roots.php";
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/{$argv[2]}";
    exit;
}
if (($argv[1] ?? '') === 'page') {
    $cfg = json_decode(file_get_contents($argv[2]), true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    require_once "$root/roots.php";
    $_GET = $cfg['get'] ?? [];
    $_SERVER['REQUEST_URI'] = getUrl('help') . ($_GET ? '?' . http_build_query($_GET) : '');
    require "$root/app/constant/settings/help.php";
    exit;
}

define('SUPPORT_TEST_UPLOADS', true);
define('SUPPORT_MAIL_CAPTURE_FILE', tempnam(sys_get_temp_dir(), 'mail'));
require_once "$root/roots.php";
require_once "$root/core/support_requests.php";
global $pdo;
$c = getControlPdo();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function call(string $file, array $post, array $session, string $method = 'POST', array $get = []): array {
    $cfg = ['session' => $session, 'method' => $method, 'post' => $post, 'get' => $get];
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode($cfg));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $s = strpos($o, '{');
    $j = $s === false ? null : json_decode(substr($o, $s), true);
    return ['raw' => $o, 'json' => is_array($j) ? $j : null];
}
function page(array $session, array $get = []): string {
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode(['session' => $session, 'get' => $get]));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' page ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return $o;
}
function noErr(string $h): bool { foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:', 'Deprecated:', 'SQLSTATE'] as $e) if (stripos($h, $e) !== false) return false; return true; }
function supTmpFile(string $bytes): string { $p = tempnam(sys_get_temp_dir(), 'att'); file_put_contents($p, $bytes); return $p; }
function filesArr(array $list): array {
    $f = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($list as [$name, $path]) { $f['name'][] = $name; $f['type'][] = 'image/png'; $f['tmp_name'][] = $path; $f['error'][] = UPLOAD_ERR_OK; $f['size'][] = filesize($path); }
    return $f;
}
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

echo "\nBMS — Help & Support Center, Phase 4\n";

// ── Snapshot + fixtures ──────────────────────────────────────────────────────
$tables = ['support_tickets', 'support_ticket_messages', 'support_attachments'];
$snaps = [];
foreach ($tables as $t) $snaps[$t] = $c->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
$settingSnap = $c->query("SELECT setting_value FROM platform_settings WHERE setting_key='support_attach_max_mb'")->fetchColumn();
register_shutdown_function(function () use ($c, $snaps, $settingSnap, $root) {
    foreach ($c->query('SELECT stored_path FROM support_attachments')->fetchAll(PDO::FETCH_COLUMN) as $p) {
        if (preg_match('~^\d+/[a-f0-9]{32}\.(jpg|png|pdf)$~', $p)) @unlink("$root/uploads/support/$p");
    }
    foreach ($snaps as $t => $rows) {
        $c->exec("DELETE FROM $t");
        foreach ($rows as $r) { $cols = array_keys($r); $c->prepare("INSERT INTO $t (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($r)); }
    }
    if ($settingSnap === false) $c->exec("DELETE FROM platform_settings WHERE setting_key='support_attach_max_mb'");
    else $c->prepare("UPDATE platform_settings SET setting_value=? WHERE setting_key='support_attach_max_mb'")->execute([$settingSnap]);
});
foreach ($tables as $t) $c->exec("DELETE FROM $t");

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
$pdo->exec("DELETE FROM users WHERE username IN ('__sup_s1','__sup_s2')");
$pdo->exec("DELETE FROM roles WHERE role_name = '__sup_test_role'");
$pdo->exec("INSERT INTO roles (role_name, is_admin, description, created_at, updated_at) VALUES ('__sup_test_role', 0, 'phase 4 test', NOW(), NOW())");
$role = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password, created_at) VALUES (?, ?, ?, 'Tester', ?, 1, 'x', NOW())");
$ins->execute(['__sup_s1', 's1@example.test', 'Asha', $role]); $s1 = (int)$pdo->lastInsertId();
$ins->execute(['__sup_s2', 's2@example.test', 'Baraka', $role]); $s2 = (int)$pdo->lastInsertId();
register_shutdown_function(function () use ($pdo, $s1, $s2, $role) {
    $pdo->prepare('DELETE FROM users WHERE user_id IN (?, ?)')->execute([$s1, $s2]);
    $pdo->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$role]);
});
$U_ADMIN = ['tenant_id' => 0, 'user_id' => $adminUid, 'name' => 'Admin Person', 'email' => 'a@example.test', 'role' => 'Admin', 'admin' => true];
$U_S1 = ['tenant_id' => 0, 'user_id' => $s1, 'name' => 'Asha Tester', 'email' => 's1@example.test', 'role' => 'Staff', 'admin' => false];
$U_S2 = ['tenant_id' => 0, 'user_id' => $s2, 'name' => 'Baraka Tester', 'email' => 's2@example.test', 'role' => 'Staff', 'admin' => false];
$U_OTHER_CO_ADMIN = ['tenant_id' => 987654, 'user_id' => $adminUid, 'name' => 'Other Co', 'email' => '', 'role' => 'Admin', 'admin' => true];
$TOKEN = bin2hex(random_bytes(16));
$S_ADMIN = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin', 'csrf_token' => $TOKEN];
$S_S1 = ['user_id' => $s1, 'username' => '__sup_s1', 'is_admin' => false, 'role_id' => $role, 'role' => 'Staff', 'csrf_token' => $TOKEN, 'permissions' => []];
$S_S2 = ['user_id' => $s2, 'username' => '__sup_s2', 'is_admin' => false, 'role_id' => $role, 'role' => 'Staff', 'csrf_token' => $TOKEN, 'permissions' => []];

// ─────────────────────────────────────────────────────────────────────────────
section('1. Lint');
foreach (['core/support_requests.php', 'core/support.php', 'api/support_requests.php', 'api/support_attachment.php', 'includes/help_panel.php',
          'app/constant/settings/help.php', 'lang/sw.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. Validation');
$good = ['subject' => 'Cannot print receipt', 'body' => 'The printer shows an error.', 'category' => 'problem', 'priority' => 'normal'];
ok('valid request accepted', supportValidateRequest($good, false)['ok']);
ok('short subject refused', isset(supportValidateRequest(['subject' => 'x'] + $good, false)['errors']['subject']));
ok('empty description refused', isset(supportValidateRequest(['body' => ' '] + $good, false)['errors']['body']));
ok('5,001-char description refused', isset(supportValidateRequest(['body' => str_repeat('a', 5001)] + $good, false)['errors']['body']));
ok('staff cannot choose Billing', isset(supportValidateRequest(['category' => 'billing'] + $good, false)['errors']['category']));
ok('admin can choose Billing', supportValidateRequest(['category' => 'billing'] + $good, true)['ok']);
ok('unknown category refused', isset(supportValidateRequest(['category' => "x'; DROP"] + $good, true)['errors']['category']));
ok('unknown priority refused', isset(supportValidateRequest(['priority' => 'asap'] + $good, true)['errors']['priority']));
ok('unknown page key is dropped, not stored', supportValidateRequest(['page_key' => '../../x'] + $good, false)['values']['page_key'] === '');

section('3. Attachments — checked by content, not by name');
ok('real PNG accepted', supportValidateUploads(filesArr([['a.png', supTmpFile($PNG)]]))['ok']);
ok('real PDF accepted', supportValidateUploads(filesArr([['a.pdf', supTmpFile($PDF)]]))['ok']);
$php = supportValidateUploads(filesArr([['shell.png', supTmpFile("<?php system(\$_GET['c']); ?>")]]));
ok('PHP code named .png refused', !$php['ok'] && str_contains((string)$php['error'], 'JPG, PNG or PDF'));
$html = supportValidateUploads(filesArr([['x.pdf', supTmpFile('<html><script>alert(1)</script></html>')]]));
ok('HTML named .pdf refused', !$html['ok']);
ok('four files refused (max 3)', !supportValidateUploads(filesArr([['1.png', supTmpFile($PNG)], ['2.png', supTmpFile($PNG)], ['3.png', supTmpFile($PNG)], ['4.png', supTmpFile($PNG)]]))['ok']);
setPlatformSetting('support_attach_max_mb', '1');
$big = supTmpFile($PNG . str_repeat("\0", 1024 * 1024 + 10));
ok('file over the limit refused', !supportValidateUploads(filesArr([['big.png', $big]]))['ok']);
setPlatformSetting('support_attach_max_mb', '5');
$errU = ['name' => ['x.png'], 'type' => ['image/png'], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_PARTIAL], 'size' => [0]];
ok('broken upload refused', !supportValidateUploads($errU)['ok']);
ok('no file is fine', supportValidateUploads(null)['ok'] && supportValidateUploads(['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]])['files'] === []);
$evilName = supportValidateUploads(filesArr([['../../evil<script>.png', supTmpFile($PNG)]]))['files'][0]['name'] ?? '';
ok('file name cleaned (no path, no markup)', !str_contains($evilName, '/') && !str_contains($evilName, '<'), $evilName);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Create — routing staff → company, admin → BJP');
$up = supportValidateUploads(filesArr([['screen.png', supTmpFile($PNG)]]));
$r1 = supportRequestCreate($good, $U_S1, $up['files'], ['page_url' => '/pos', 'browser' => 'TestUA', 'product_key' => 'bms_shop']);
ok('staff request created', $r1['ok'], json_encode($r1));
$t1 = $c->query('SELECT * FROM support_tickets WHERE id=' . (int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
ok('staff request goes to the company admin (audience company, HLP ref)', $t1['audience'] === 'company' && str_starts_with($t1['ref_no'], 'HLP-'), $t1['ref_no']);
ok('context captured (page, browser, product, requester)', $t1['page_url'] === '/pos' && $t1['browser'] === 'TestUA' && $t1['product_key'] === 'bms_shop' && $t1['user_name'] === 'Asha Tester');
$att = $c->query('SELECT * FROM support_attachments WHERE ticket_id=' . (int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
ok('attachment stored under a random name', $att && preg_match('~^0/[a-f0-9]{32}\.png$~', $att['stored_path']) && is_file("$root/uploads/support/" . $att['stored_path']));
ok('support folder denies direct web access', str_contains((string)@file_get_contents("$root/uploads/support/.htaccess"), 'Require all denied'));
$r2 = supportRequestCreate(['category' => 'billing'] + $good, $U_ADMIN, [], []);
$t2 = $c->query('SELECT * FROM support_tickets WHERE id=' . (int)$r2['id'])->fetch(PDO::FETCH_ASSOC);
ok('admin request goes to BJP (audience bjp, SUP ref)', $r2['ok'] && $t2['audience'] === 'bjp' && str_starts_with($t2['ref_no'], 'SUP-'));
ok('signed-out user cannot create', !supportRequestCreate($good, ['user_id' => 0] + $U_S1, [])['ok']);

section('5. Access isolation');
ok('requester sees own request', supportRequestGetForUser($r1['id'], $U_S1) !== null);
ok('another staff member cannot see it', supportRequestGetForUser($r1['id'], $U_S2) === null);
ok('company admin can see it', supportRequestGetForUser($r1['id'], $U_ADMIN) !== null);
ok('admin of ANOTHER company cannot see it', supportRequestGetForUser($r1['id'], $U_OTHER_CO_ADMIN) === null);
ok('staff list: own only', array_column(supportRequestList($U_S2, 'all'), 'id') === []);
ok('staff asking for "all" still gets own only', array_column(supportRequestList($U_S1, 'all'), 'id') === [$r1['id']]);
ok('admin "all" sees the whole company', count(supportRequestList($U_ADMIN, 'all')) === 2);
ok('admin "mine" sees own only', array_column(supportRequestList($U_ADMIN, 'mine'), 'id') === [$r2['id']]);
ok('other company list is empty', supportRequestList($U_OTHER_CO_ADMIN, 'all') === []);
ok('staff cannot reply to someone else\'s request', !supportRequestReply($r1['id'], $U_S2, 'hi')['ok']);
ok('staff cannot resolve someone else\'s request', !supportRequestResolve($r1['id'], $U_S2)['ok']);

section('6. Lifecycle');
ok('admin waiting count includes the unanswered staff request', supportRequestWaitingCount($U_ADMIN) === 1);
$rr = supportRequestReply($r1['id'], $U_ADMIN, 'Restart the printer, then try again.');
$t1 = supportRequestGetForUser($r1['id'], $U_S1);
ok('admin answer → "waiting on you" for the staff member, first response stamped', $rr['ok'] && $t1['status'] === 'waiting_on_customer' && $t1['first_response_at'] !== null);
ok('staff waiting count = 1', supportRequestWaitingCount($U_S1) === 1);
ok('status label for staff: Waiting on you', supportStatusLabel($t1, $U_S1)[0] === 'Waiting on you');
supportRequestReply($r1['id'], $U_S1, 'Still not working.');
ok('staff reply → back to Open (company)', supportRequestGetForUser($r1['id'], $U_S1)['status'] === 'open');
ok('label for admin: Needs your reply', supportStatusLabel(supportRequestGetForUser($r1['id'], $U_ADMIN), $U_ADMIN)[0] === 'Needs your reply');
ok('rating before resolved refused', !supportRequestRate($r1['id'], $U_S1, 5)['ok']);
ok('resolve', supportRequestResolve($r1['id'], $U_S1)['ok'] && supportRequestGetForUser($r1['id'], $U_S1)['status'] === 'resolved');
ok('resolve twice refused', !supportRequestResolve($r1['id'], $U_S1)['ok']);
supportRequestReply($r1['id'], $U_S1, 'It broke again.');
ok('reply on a resolved request reopens it', supportRequestGetForUser($r1['id'], $U_S1)['status'] === 'open' && supportRequestGetForUser($r1['id'], $U_S1)['resolved_at'] === null);
supportRequestResolve($r1['id'], $U_ADMIN);
ok('admin may resolve a staff request', supportRequestGetForUser($r1['id'], $U_S1)['status'] === 'resolved');
ok('only the requester rates', !supportRequestRate($r1['id'], $U_ADMIN, 4)['ok']);
ok('rating out of range refused', !supportRequestRate($r1['id'], $U_S1, 9)['ok']);
ok('rate 4 → closed', supportRequestRate($r1['id'], $U_S1, 4, 'Thanks')['ok'] && supportRequestGetForUser($r1['id'], $U_S1)['status'] === 'closed');
ok('closed request takes no replies', !supportRequestReply($r1['id'], $U_S1, 'one more')['ok']);
ok('reply over 5,000 chars refused', !supportRequestReply($r2['id'], $U_ADMIN, str_repeat('x', 5001))['ok']);

section('7. Forward to BJP');
$r3 = supportRequestCreate(['subject' => 'Stock count wrong'] + $good, $U_S2, []);
ok('staff cannot forward', !supportRequestForward($r3['id'], $U_S2)['ok']);
ok('other company admin cannot forward', !supportRequestForward($r3['id'], $U_OTHER_CO_ADMIN)['ok']);
$fw = supportRequestForward($r3['id'], $U_ADMIN, 'Please check the stock module.');
$t3 = supportRequestGetForUser($r3['id'], $U_S2);
ok('admin forwards: same request, now with BJP, SUP ref, origin kept', $fw['ok'] && $t3['audience'] === 'bjp' && $t3['ref_no'] === $fw['ref'] && str_starts_with($t3['forwarded_from'], 'HLP-'));
ok('the staff member still sees it', supportRequestGetForUser($r3['id'], $U_S2) !== null);
ok('forwarding twice refused', !supportRequestForward($r3['id'], $U_ADMIN)['ok']);
$msgs = supportRequestMessages($r3['id']);
ok('history kept: original message, admin note, system line', count($msgs) === 3 && $msgs[2]['author_type'] === 'system');
ok('admin may add detail to it without changing whose turn it is', supportRequestReply($r3['id'], $U_ADMIN, 'More detail')['ok'] && supportRequestGetForUser($r3['id'], $U_S2)['status'] === 'open');

section('8. BJP internal notes never reach the company');
$noteId = supportAddMessage($r3['id'], 'bjp', 1, 'Agent', 'INTERNAL: tenant has bad data', true);
supportStoreAttachments([['tmp' => supTmpFile($PNG), 'mime' => 'image/png', 'size' => strlen($PNG), 'name' => 'internal.png']], 0, $r3['id'], $noteId);
$intAtt = (int)$c->query("SELECT id FROM support_attachments WHERE message_id=$noteId")->fetchColumn();
ok('internal note hidden from tenant messages', !in_array('INTERNAL: tenant has bad data', array_column(supportRequestMessages($r3['id']), 'body'), true));
ok('internal note visible to BJP view', in_array('INTERNAL: tenant has bad data', array_column(supportRequestMessages($r3['id'], true), 'body'), true));
ok('internal attachment cannot be downloaded by the company', supportAttachmentForUser($intAtt, $U_ADMIN) === null);
ok('normal attachment downloadable by its requester', supportAttachmentForUser((int)$att['id'], $U_S1) !== null);
ok('...not by another staff member', supportAttachmentForUser((int)$att['id'], $U_S2) === null);
$c->prepare('UPDATE support_attachments SET stored_path = ? WHERE id = ?')->execute(['../../config.php', (int)$att['id']]);
ok('tampered stored path refused (no traversal)', supportAttachmentForUser((int)$att['id'], $U_S1) === null);
$c->prepare('UPDATE support_attachments SET stored_path = ? WHERE id = ?')->execute([$att['stored_path'], (int)$att['id']]);

section('9. Flood guard');
for ($i = 0; $i < 9; $i++) supportRequestCreate(['subject' => "Spam $i"] + $good, $U_S2, []);
ok('11th request in an hour refused', !supportRequestCreate(['subject' => 'one too many'] + $good, $U_S2, [])['ok']);

// ─────────────────────────────────────────────────────────────────────────────
section('10. API guards and actions');
$api = 'api/support_requests.php';
ok('signed-out → 401', str_contains(call($api, ['action' => 'list', '_csrf' => $TOKEN], [])['raw'], 'Unauthorized'));
ok('GET → 405', str_contains(call($api, ['action' => 'list', '_csrf' => $TOKEN], $S_S1, 'GET')['raw'], 'Method not allowed'));
ok('wrong CSRF refused', str_contains(call($api, ['action' => 'list', '_csrf' => 'bad'], $S_S1)['raw'], 'CSRF'));
ok('unknown action → 400', str_contains(call($api, ['action' => 'drop', '_csrf' => $TOKEN], $S_S1)['raw'], 'Unknown action'));
$r = call($api, ['action' => 'create', 'subject' => 'From the API', 'body' => 'Testing the API path.', 'category' => 'how_to', 'priority' => 'low', 'page_key' => 'pos', '_csrf' => $TOKEN], $S_S1);
ok('create via API', ($r['json']['success'] ?? false) === true && str_starts_with($r['json']['ref'] ?? '', 'HLP-'), substr($r['raw'], 0, 300));
$apiId = (int)($r['json']['id'] ?? 0);
ok('create logged to activity log', (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='support_request_create' AND user_id=$s1")->fetchColumn() >= 1);
ok('create refuses invalid input with a clear message', str_contains(call($api, ['action' => 'create', 'subject' => 'x', 'body' => 'y', '_csrf' => $TOKEN], $S_S1)['raw'], 'subject'));
$l = call($api, ['action' => 'list', 'scope' => 'all', '_csrf' => $TOKEN], $S_S2);
ok('list via API: staff gets only own (scope=all ignored)', !in_array($apiId, array_column($l['json']['requests'] ?? [], 'id'), true));
ok('reply via API by requester', (call($api, ['action' => 'reply', 'id' => $apiId, 'body' => 'More info', '_csrf' => $TOKEN], $S_S1)['json']['success'] ?? false) === true);
ok('reply via API by another staff refused', str_contains(call($api, ['action' => 'reply', 'id' => $apiId, 'body' => 'x', '_csrf' => $TOKEN], $S_S2)['raw'], 'not available'));
ok('forward via API by staff refused', str_contains(call($api, ['action' => 'forward', 'id' => $apiId, '_csrf' => $TOKEN], $S_S1)['raw'], 'company admin'));
ok('forward via API by admin', str_starts_with(call($api, ['action' => 'forward', 'id' => $apiId, '_csrf' => $TOKEN], $S_ADMIN)['json']['ref'] ?? '', 'SUP-'));
ok('resolve via API', (call($api, ['action' => 'resolve', 'id' => $apiId, '_csrf' => $TOKEN], $S_S1)['json']['success'] ?? false) === true);
ok('rate via API', (call($api, ['action' => 'rate', 'id' => $apiId, 'rating' => '5', '_csrf' => $TOKEN], $S_S1)['json']['success'] ?? false) === true);

section('11. Attachment download endpoint');
$dl = call('api/support_attachment.php', [], $S_S1, 'GET', ['id' => (string)$att['id']]);
ok('owner downloads the file bytes', str_contains($dl['raw'], "\x89PNG"), substr(bin2hex(substr($dl['raw'], 0, 8)), 0, 16));
ok('another staff member gets Not found', str_contains(call('api/support_attachment.php', [], $S_S2, 'GET', ['id' => (string)$att['id']])['raw'], 'Not found'));
ok('internal attachment: Not found', str_contains(call('api/support_attachment.php', [], $S_ADMIN, 'GET', ['id' => (string)$intAtt])['raw'], 'Not found'));
ok('signed-out: Unauthorized', str_contains(call('api/support_attachment.php', [], [], 'GET', ['id' => (string)$att['id']])['raw'], 'Unauthorized'));
$src = file_get_contents("$root/api/support_attachment.php");
ok('download sends nosniff + sandbox CSP + attachment disposition', str_contains($src, 'X-Content-Type-Options: nosniff') && str_contains($src, 'sandbox') && str_contains($src, "'attachment'"));

// ─────────────────────────────────────────────────────────────────────────────
section('12. Pages');
$xss = supportRequestCreate(['subject' => 'Sub <img src=x onerror=alert(3)>', 'body' => "<script>alert(3)</script>\nline 2"] + $good, $U_S1, []);
$hl = page($S_S1, ['tab' => 'requests']);
ok('requests tab renders for staff', noErr($hl) && str_contains($hl, 'My requests'), substr($hl, 0, 200));
ok('staff list escapes subjects', !str_contains($hl, '<img src=x onerror=alert(3)>') && str_contains($hl, '&lt;img src=x onerror=alert(3)&gt;'));
ok('staff list never mentions BJP', !str_contains($hl, 'BJP Support') && !str_contains($hl, 'Forward to BJP'));
$ht = page($S_S1, ['request' => (string)$xss['id']]);
ok('thread renders and escapes the message', noErr($ht) && !str_contains($ht, '<script>alert(3)') && str_contains($ht, '&lt;script&gt;alert(3)&lt;/script&gt;'));
ok('staff thread: no Forward button, no BJP wording', !str_contains($ht, 'id="btnForward"') && !str_contains($ht, 'BJP Support'));
ok('staff thread offers Mark as resolved', str_contains($ht, 'btnResolve'));
$ho = page($S_S2, ['request' => (string)$xss['id']]);
ok('another staff member opening the URL sees "not available"', noErr($ho) && str_contains($ho, 'This request is not available.') && !str_contains($ho, 'alert(3)'));
$ha = page($S_ADMIN, ['request' => (string)$xss['id'], 'scope' => 'all']);
ok('admin thread on a staff request shows Forward to BJP', noErr($ha) && str_contains($ha, 'id="btnForward"'));
foreach (['admin thread' => $ha, 'staff thread' => $ht] as $label => $htmlPage) {
    preg_match_all('~<script>(.*?)</script>~s', $htmlPage, $sm);
    $checked = 0; $bad = [];
    foreach ($sm[1] as $js) {
        if (!str_contains($js, 'support_requests.php') && !str_contains($js, 'bmsHelpPanel')) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'js') . '.js'; file_put_contents($tmp, $js);
        $o = []; exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc); @unlink($tmp);
        $checked++; if ($rc !== 0) $bad[] = implode(' ', $o);
    }
    ok("$label: panel + thread scripts are valid JavaScript ($checked checked)", $checked >= 2 && !$bad, implode(' | ', $bad));
}
$hall = page($S_ADMIN, ['tab' => 'requests', 'scope' => 'all']);
ok('admin "All company" lists staff requests', noErr($hall) && str_contains($hall, '&lt;img src=x onerror=alert(3)&gt;'));
ok('panel contains the contact form and the waiting dot', str_contains($hall, 'id="bhForm"') && str_contains($hall, 'bms-help-dot"'));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
