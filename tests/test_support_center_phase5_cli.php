<?php
/**
 * tests/test_support_center_phase5_cli.php — Help & Support Center, Phase 5
 * (support_center_plan.md): BJP's support inbox — business-hours reply targets,
 * inbox isolation, agent reply / internal note / status / assign / priority,
 * saved replies, the company context, guards and pages.
 *
 *   php tests/test_support_center_phase5_cli.php
 *
 * CLI ONLY. Snapshots the support request tables and saved replies; removes stored files.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

if (($argv[1] ?? '') === '--endpoint') {
    // Never send real email from a test: capture it to a throwaway file.
    define('SUPPORT_MAIL_CAPTURE_FILE', tempnam(sys_get_temp_dir(), 'mail'));
    $file = (string)$argv[2];
    $post = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = (string)($argv[4] ?? 'POST');
    $_SERVER['HTTP_HOST']      = (string)($argv[5] ?? 'localhost');
    $_SERVER['REQUEST_URI']    = '/' . $file;
    $_GET = json_decode((string)base64_decode((string)($argv[7] ?? '')), true) ?: [];
    if ((string)($argv[6] ?? '0') === '1') {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }
    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST'] = (string)$argv[2];
    $uri = (string)$argv[3];
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING'] = (string)parse_url($uri, PHP_URL_QUERY);
    parse_str($_SERVER['QUERY_STRING'], $_GET);
    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

define('SUPPORT_TEST_UPLOADS', true);
define('SUPPORT_MAIL_CAPTURE_FILE', tempnam(sys_get_temp_dir(), 'mail'));
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/support_inbox.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
$c = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);
function endpoint(string $file, array $post, array $o = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint ' . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($o['method'] ?? 'POST') . ' ' . escapeshellarg($o['host'] ?? SA_HOST) . ' ' . escapeshellarg(!empty($o['auth']) ? '1' : '0') . ' '
         . escapeshellarg(base64_encode(json_encode($o['get'] ?? [])));
    $out = []; exec($cmd . ' 2>&1', $out);
    $raw = implode("\n", $out);
    if (str_contains($raw, 'Fatal error') || str_contains($raw, 'Parse error')) $raw = 'WORKER_CRASHED: ' . $raw;
    $j = json_decode($raw, true);
    if (!is_array($j) && ($s = strpos($raw, '{')) !== false && ($e = strpos($raw, '}', $s)) !== false) $j = json_decode(substr($raw, $s, $e - $s + 1), true);
    return ['raw' => $raw, 'json' => is_array($j) ? $j : null];
}
function refused(array $r, string $expect = ''): bool { if (str_contains($r['raw'], 'WORKER_CRASHED') || str_contains($r['raw'], '"success":true')) return false; return $expect === '' || str_contains($r['raw'], $expect); }
function route(string $uri): string { $out = []; exec('php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg(SA_HOST) . ' ' . escapeshellarg($uri) . ' 2>&1', $out); return implode("\n", $out); }
function supTmp(string $b): string { $p = tempnam(sys_get_temp_dir(), 'att'); file_put_contents($p, $b); return $p; }
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

echo "\nBMS — Help & Support Center, Phase 5\n";
$tables = ['support_tickets', 'support_ticket_messages', 'support_attachments', 'support_canned_replies'];
$snaps = [];
foreach ($tables as $t) $snaps[$t] = $c->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
register_shutdown_function(function () use ($c, $snaps, $root) {
    foreach ($c->query('SELECT stored_path FROM support_attachments')->fetchAll(PDO::FETCH_COLUMN) as $p) if (preg_match('~^\d+/[a-f0-9]{32}\.(jpg|png|pdf)$~', $p)) @unlink("$root/uploads/support/$p");
    foreach ($snaps as $t => $rows) {
        $c->exec("DELETE FROM $t");
        foreach ($rows as $r) { $cols = array_keys($r); $c->prepare("INSERT INTO $t (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($r)); }
    }
});
foreach ($tables as $t) $c->exec("DELETE FROM $t");
$tenantId = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$agentId = (int)$c->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetchColumn();
$AGENT = ['id' => $agentId, 'name' => 'Agent Test'];
$U_ADMIN = ['tenant_id' => $tenantId, 'user_id' => 1, 'name' => 'Co Admin', 'email' => 'a@example.test', 'role' => 'Admin', 'admin' => true];
$U_STAFF = ['tenant_id' => $tenantId, 'user_id' => 2, 'name' => 'Co Staff', 'email' => 's@example.test', 'role' => 'Staff', 'admin' => false];
$good = ['subject' => 'POS will not print', 'body' => 'Receipts fail since this morning.', 'category' => 'problem', 'priority' => 'high'];

// ─────────────────────────────────────────────────────────────────────────────
section('1. Lint');
foreach (['core/support_inbox.php', 'app/superadmin/support.php', 'app/superadmin/support_request.php', 'actions/superadmin_support_inbox.php',
          'actions/superadmin_support_attachment.php', 'core/superadmin_ui.php', 'core/superadmin_auth.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. Business-hours reply targets (Mon–Fri 08:00–17:00 EAT)');
$tz = new DateTimeZone('Africa/Dar_es_Salaam');
$d = fn(string $s) => new DateTimeImmutable($s, $tz);
ok('Mon 09:00 + 2h = Mon 11:00', supportBusinessDue($d('2026-10-26 09:00'), 2)->format('D H:i') === 'Mon 11:00');
ok('Mon 16:00 + 4h = Tue 11:00 (overnight skipped)', supportBusinessDue($d('2026-10-26 16:00'), 4)->format('D H:i') === 'Tue 11:00');
ok('Fri 16:00 + 2h = Mon 09:00 (weekend skipped)', supportBusinessDue($d('2026-10-30 16:00'), 2)->format('D H:i') === 'Mon 09:00');
ok('Sat 10:00 + 1h = Mon 09:00', supportBusinessDue($d('2026-10-31 10:00'), 1)->format('D H:i') === 'Mon 09:00');
ok('Tue 06:00 (before opening) + 8h = Tue 16:00', supportBusinessDue($d('2026-10-27 06:00'), 8)->format('D H:i') === 'Tue 16:00');
ok('Wed 20:00 (after closing) + 1h = Thu 09:00', supportBusinessDue($d('2026-10-28 20:00'), 1)->format('D H:i') === 'Thu 09:00');
ok('18h from Mon 08:00 = Tue 17:00 (two full 9-hour days)', supportBusinessDue($d('2026-10-26 08:00'), 18)->format('D H:i') === 'Tue 17:00');
$sla = supportSlaInfo(['created_at' => '2026-10-26 09:00:00', 'priority' => 'urgent', 'first_response_at' => null, 'status' => 'open'], $d('2026-10-26 12:00'));
ok('urgent created 09:00, unanswered at 12:00 → overdue', $sla['overdue'] && str_starts_with(supportSlaLabel($sla, $d('2026-10-26 12:00')), 'Overdue'));
$sla2 = supportSlaInfo(['created_at' => '2026-10-26 09:00:00', 'priority' => 'normal', 'first_response_at' => '2026-10-26 10:00:00', 'status' => 'waiting_on_customer']);
ok('answered within target → "Answered in time"', supportSlaLabel($sla2) === 'Answered in time');

section('3. The inbox only ever shows requests that reached BJP');
$staffReq = supportRequestCreate($good, $U_STAFF, []);
$adminReq = supportRequestCreate(['subject' => 'Invoice numbering', 'priority' => 'normal'] + $good, $U_ADMIN, []);
$all = array_column(supportInboxList(['view' => 'all'], $agentId), 'id');
ok('admin request is in the inbox', in_array($adminReq['id'], $all, true));
ok('staff request to their own admin is NOT in the inbox', !in_array($staffReq['id'], $all, true));
ok('...and cannot be opened by id', supportInboxGet($staffReq['id']) === null);
ok('needs-reply count = 1', supportInboxNeedsReplyCount() === 1);
supportRequestForward($staffReq['id'], $U_ADMIN, 'Please help.');
ok('after the admin forwards it, it appears', in_array($staffReq['id'], array_column(supportInboxList(['view' => 'needs_reply'], $agentId), 'id'), true));
ok('needs-reply count = 2', supportInboxNeedsReplyCount() === 2);
$list = supportInboxList(['view' => 'needs_reply'], $agentId);
ok('sorted by priority / time left (high before normal)', $list[0]['priority'] === 'high');
ok('company name joined in', $list[0]['company_name'] !== null || $tenantId === 0);
ok('search by ref works', array_column(supportInboxList(['view' => 'all', 'q' => $adminReq['ref']], $agentId), 'id') === [$adminReq['id']]);
ok('search by the original HLP ref finds the forwarded request', in_array($staffReq['id'], array_column(supportInboxList(['view' => 'all', 'q' => 'HLP-'], $agentId), 'id'), true));
ok('LIKE wildcards are literal', supportInboxList(['view' => 'all', 'q' => '%_%'], $agentId) === []);
ok('filter by priority', array_column(supportInboxList(['view' => 'all', 'priority' => 'normal'], $agentId), 'id') === [$adminReq['id']]);
ok('bad filter values are ignored, not injected', count(supportInboxList(['view' => "all' OR 1=1", 'priority' => 'x', 'product' => 'a;b', 'tenant_id' => '1 OR 1'], $agentId)) === 2);

section('4. Agent reply, internal note, status, assign, priority');
$rid = (int)$adminReq['id'];
$r = supportInboxReply($rid, $AGENT, 'INTERNAL ONLY: check their data', true);
$t = supportInboxGet($rid);
ok('internal note saved, status unchanged, not a first response', $r['ok'] && $t['status'] === 'open' && $t['first_response_at'] === null);
ok('internal note never shown to the company admin', !in_array('INTERNAL ONLY: check their data', array_column(supportRequestMessages($rid), 'body'), true));
$noteMid = (int)$r['message_id'];
$r = supportInboxReply($rid, $AGENT, 'Please send a screenshot of the error.', false, [['tmp' => supTmp($PNG), 'mime' => 'image/png', 'size' => strlen($PNG), 'name' => 'guide.png']]);
$t = supportInboxGet($rid);
ok('public reply → waiting on company, first response stamped, auto-assigned', $r['ok'] && $t['status'] === 'waiting_on_customer' && $t['first_response_at'] !== null && (int)$t['assigned_to'] === $agentId);
ok('company admin now sees "Waiting on you"', supportStatusLabel(supportRequestGetForUser($rid, $U_ADMIN), $U_ADMIN)[0] === 'Waiting on you');
ok('needs-reply count dropped to 1', supportInboxNeedsReplyCount() === 1);
$attId = (int)$c->query("SELECT id FROM support_attachments WHERE ticket_id = $rid")->fetchColumn();
ok('company can download BJP\'s attachment', supportAttachmentForUser($attId, $U_ADMIN) !== null);
supportRequestReply($rid, $U_ADMIN, 'Here it is.');
ok('company reply → back in "needs reply"', supportInboxNeedsReplyCount() === 2);
ok('empty reply refused', !supportInboxReply($rid, $AGENT, '  ', false)['ok']);
ok('reply on a non-BJP request refused', !supportInboxReply(99999999, $AGENT, 'x', false)['ok']);
ok('set status resolved', supportInboxSetStatus($rid, 'resolved', $AGENT)['ok'] && supportInboxGet($rid)['status'] === 'resolved');
ok('resolution line visible to the company', in_array('Marked as resolved by BJP Support.', array_column(supportRequestMessages($rid), 'body'), true));
ok('unknown status refused', !supportInboxSetStatus($rid, 'deleted', $AGENT)['ok']);
ok('public reply on a closed request refused', supportInboxSetStatus($rid, 'closed', $AGENT)['ok'] && !supportInboxReply($rid, $AGENT, 'late', false)['ok']);
ok('internal note on a closed request still allowed', supportInboxReply($rid, $AGENT, 'note after close', true)['ok']);
ok('reopen', supportInboxSetStatus($rid, 'open', $AGENT)['ok'] && supportInboxGet($rid)['closed_at'] === null);
ok('assign to nobody / to agent / to a missing agent', supportInboxAssign($rid, null)['ok'] && supportInboxAssign($rid, $agentId)['ok'] && !supportInboxAssign($rid, 99999999)['ok']);
ok('priority change + invalid priority refused', supportInboxSetPriority($rid, 'urgent')['ok'] && !supportInboxSetPriority($rid, 'now')['ok']);
ok('agent can download an internal-note attachment', (function () use ($rid, $noteMid, $c, $PNG) {
    supportStoreAttachments([['tmp' => supTmp($PNG), 'mime' => 'image/png', 'size' => strlen($PNG), 'name' => 'secret.png']], (int)supportInboxGet($rid)['tenant_id'], $rid, $noteMid);
    $aid = (int)$c->query("SELECT id FROM support_attachments WHERE message_id = $noteMid")->fetchColumn();
    return supportAttachmentForAgent($aid) !== null && supportAttachmentForUser($aid, $GLOBALS['U_ADMIN']) === null;
})());
ok('agent cannot download files of a request that never reached BJP', (function () use ($c, $U_STAFF, $PNG) {
    $x = supportRequestCreate(['subject' => 'internal only'] + $GLOBALS['good'], $U_STAFF, [['tmp' => supTmp($PNG), 'mime' => 'image/png', 'size' => strlen($PNG), 'name' => 'a.png']]);
    $aid = (int)$c->query('SELECT id FROM support_attachments WHERE ticket_id = ' . (int)$x['id'])->fetchColumn();
    return $aid > 0 && supportAttachmentForAgent($aid) === null;
})());

section('5. Saved replies');
$cr = supportCannedSave(['title' => 'Restart printer', 'body' => 'Please switch the printer off and on.'], null, $agentId);
ok('saved reply created', $cr['ok']);
ok('too-short name refused', !supportCannedSave(['title' => 'x', 'body' => 'ok body'], null, $agentId)['ok']);
ok('retire = soft delete', supportCannedSetActive($cr['id'], false) && supportCannedList(true) === [] && count(supportCannedList(false)) === 1);
ok('retire a missing reply → false', supportCannedSetActive(99999999, true) === false);
supportCannedSetActive($cr['id'], true);

section('6. Company context');
$ctx = supportTenantContext($tenantId);
ok('company context returns the tenant row and recent requests', $tenantId === 0 || ($ctx['tenant'] !== null && count($ctx['recent']) >= 1));
ok('stats compute without error', is_array(supportInboxStats()) && array_key_exists('overdue', supportInboxStats()));

// ─────────────────────────────────────────────────────────────────────────────
section('7. Actions — guards and behaviour');
$A = 'actions/superadmin_support_inbox.php';
ok('refuses without session', refused(endpoint($A, ['action' => 'reply', 'id' => $rid, 'body' => 'x']), 'session has ended'));
ok('refuses GET', refused(endpoint($A, ['action' => 'reply', 'id' => $rid, 'body' => 'x'], ['method' => 'GET', 'auth' => true]), 'Method not allowed'));
ok('refuses wrong CSRF', refused(endpoint($A, ['action' => 'reply', 'id' => $rid, 'body' => 'x', '_csrf' => 'bad'], ['auth' => true])));
ok('refuses on a tenant host', refused(endpoint($A, ['action' => 'reply', 'id' => $rid, 'body' => 'x'], ['host' => 'sometenant.' . $BASE, 'auth' => true])));
ok('refuses unknown action', refused(endpoint($A, ['action' => 'drop', 'id' => $rid], ['auth' => true]), 'Unknown action'));
$r = endpoint($A, ['action' => 'reply', 'id' => $rid, 'body' => 'From the endpoint', 'internal' => '0', 'after' => 'in_progress'], ['auth' => true]);
ok('reply via action', ($r['json']['success'] ?? false) === true && supportInboxGet($rid)['status'] === 'in_progress', $r['raw']);
ok('reply on a staff-only request refused', refused(endpoint($A, ['action' => 'reply', 'id' => (int)$c->query("SELECT id FROM support_tickets WHERE audience='company' LIMIT 1")->fetchColumn(), 'body' => 'x'], ['auth' => true]), 'not with BJP'));
ok('set_status via action', (endpoint($A, ['action' => 'set_status', 'id' => $rid, 'status' => 'resolved'], ['auth' => true])['json']['success'] ?? false) === true);
ok('set_priority via action', (endpoint($A, ['action' => 'set_priority', 'id' => $rid, 'priority' => 'low'], ['auth' => true])['json']['success'] ?? false) === true);
ok('assign via action', (endpoint($A, ['action' => 'assign', 'id' => $rid, 'agent_id' => (string)$agentId], ['auth' => true])['json']['success'] ?? false) === true);
ok('save_canned via action', (endpoint($A, ['action' => 'save_canned', 'title' => 'Endpoint reply', 'body' => 'Hello from the endpoint'], ['auth' => true])['json']['success'] ?? false) === true);
$dl = endpoint('actions/superadmin_support_attachment.php', [], ['method' => 'GET', 'auth' => true, 'get' => ['id' => (string)$attId]]);
ok('agent download sends the file', str_contains($dl['raw'], "\x89PNG"));
ok('agent download refused without session', str_contains(endpoint('actions/superadmin_support_attachment.php', [], ['method' => 'GET', 'get' => ['id' => (string)$attId]])['raw'], 'session has ended'));
ok('agent download refused on a tenant host', !str_contains(endpoint('actions/superadmin_support_attachment.php', [], ['method' => 'GET', 'auth' => true, 'host' => 'sometenant.' . $BASE, 'get' => ['id' => (string)$attId]])['raw'], "\x89PNG"));

// ─────────────────────────────────────────────────────────────────────────────
section('8. Pages');
$xss = supportRequestCreate(['subject' => 'Sub <script>alert(5)</script>', 'body' => '<img src=x onerror=alert(5)>'] + $good, $U_ADMIN, []);
$inboxHtml = route('/support?tab=inbox&view=all');
ok('Inbox tab renders (default tab)', str_contains($inboxHtml, 'Need a reply') && !str_contains($inboxHtml, 'Fatal error'), substr($inboxHtml, 0, 200));
ok('Inbox escapes subjects', !str_contains($inboxHtml, '<script>alert(5)') && str_contains($inboxHtml, '&lt;script&gt;alert(5)'));
ok('Support menu item shows the needs-reply badge', (bool)preg_match('~bi-headset me-1"></i>Support\s*<span class="badge rounded-pill bg-danger~', $inboxHtml));
$reqHtml = route('/support/request?id=' . (int)$xss['id']);
ok('request view renders', str_contains($reqHtml, 'Who asked') && str_contains($reqHtml, 'Internal note') && !str_contains($reqHtml, 'Fatal error'), substr($reqHtml, 0, 200));
ok('request view escapes the message', !str_contains($reqHtml, '<img src=x onerror') && str_contains($reqHtml, '&lt;img src=x onerror=alert(5)&gt;'));
$staffOnly = (int)$c->query("SELECT id FROM support_tickets WHERE audience='company' LIMIT 1")->fetchColumn();
$soHtml = route('/support/request?id=' . $staffOnly);
ok('a staff-only request cannot be opened by URL', str_contains($soHtml, 'does not exist') && !str_contains($soHtml, 'Who asked'));
$repHtml = route('/support?tab=replies');
ok('Saved replies tab renders', str_contains($repHtml, 'Add saved reply') && str_contains($repHtml, 'Restart printer'));
foreach (['inbox' => $inboxHtml, 'request' => $reqHtml, 'replies' => $repHtml] as $label => $html) {
    preg_match_all('~<script>(.*?)</script>~s', $html, $sm);
    $bad = [];
    foreach ($sm[1] as $js) { $tmp = tempnam(sys_get_temp_dir(), 'js') . '.js'; file_put_contents($tmp, $js); $o = []; exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc); @unlink($tmp); if ($rc !== 0) $bad[] = implode(' ', $o); }
    ok("$label page scripts are valid JavaScript", !$bad, implode(' | ', $bad));
}
ok('route map has support/request', isset(superadminRouteMap()['support/request']));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
