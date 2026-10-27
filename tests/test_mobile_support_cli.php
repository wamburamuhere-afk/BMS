<?php
/**
 * Mobile API upgrade — Phase 8: Help & Support in the Flutter app — CLI regression
 *   php tests/test_mobile_support_cli.php
 *
 * api/mobile/support.php runs the web Help & Support endpoints for a Bearer
 * caller (api/support_help.php, api/support_requests.php,
 * api/support_attachment.php) and adds `view` — one request and its
 * conversation with the same buttons the web Help page shows.
 *
 * Real HTTP on a throwaway company; mail is captured (SUPPORT_MAIL_CAPTURE_FILE
 * + a sendEmail stub in the test server), never sent; the temporary help article
 * and the company's requests are removed afterwards.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$c = getControlPdo();
$articleId = 0; $tid = 0;
$capture = __DIR__ . '/helpers/.mh_mail_' . bin2hex(random_bytes(4)) . '.jsonl';
register_shutdown_function(function () use (&$articleId, &$tid, $c, $capture) {
    if ($articleId) $c->prepare("DELETE FROM support_articles WHERE id = ?")->execute([$articleId]);
    if ($tid) {
        try {
            $ids = $c->prepare("SELECT id FROM support_tickets WHERE tenant_id = ?"); $ids->execute([$tid]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $t) {
                foreach (['support_attachments', 'support_ticket_messages'] as $tb) $c->prepare("DELETE FROM $tb WHERE ticket_id = ?")->execute([$t]);
            }
            $c->prepare("DELETE FROM support_tickets WHERE tenant_id = ?")->execute([$tid]);
        } catch (Throwable $e) {}
        @array_map('unlink', glob(dirname(__DIR__) . "/uploads/support/$tid/*") ?: []);
        @rmdir(dirname(__DIR__) . "/uploads/support/$tid");
    }
    @unlink($capture);
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    mhStopAll();   // before exit(1), which would skip mhStart's own cleanup
    if ($fail > 0) exit(1);
});

section('1. Static');
$o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/api/mobile/support.php") . ' 2>&1', $o, $rc);
check($rc === 0, 'lint api/mobile/support.php');
$src = file_get_contents("$root/api/mobile/support.php");
check(str_contains($src, "'/../support_help.php'") && str_contains($src, "'/../support_requests.php'") && str_contains($src, "/../support_attachment.php"),
    'runs the web endpoints (one copy of the rules)');

$c->prepare("INSERT INTO support_articles (title_en, title_sw, body_en, body_sw, status) VALUES (?, ?, ?, ?, 'published')")
  ->execute(['How to make a sale zzapp', 'Jinsi ya kuuza zzapp', 'Open Sell, tap the products, then Pay.', 'Fungua Uza, gusa bidhaa, kisha Lipa.']);
$articleId = (int)$c->lastInsertId();

$m = mhStart('artsup', ['capture_mail' => $capture]);
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$tid = $m['tid'];
$tok = mhLogin($m);
check((bool)$tok, 'owner signs in');
$sup = fn(array $data, ?string $t = null, string $method = 'POST') => mhCall($m, $method, '/api/mobile/support.php', $data, $t ?? $tok);

section('2. Help articles');
[$code, $j] = $sup(['action' => 'home']);
check($code === 200 && ($j['total'] ?? 0) >= 1, "home lists published articles (HTTP $code, total " . ($j['total'] ?? '?') . ')');
[$code, $j] = $sup(['action' => 'search', 'q' => 'zzapp']);
$found = array_column($j['results'] ?? $j['articles'] ?? [], 'id');
check($code === 200 && in_array($articleId, array_map('intval', $found), true), 'search finds the article');
[$code, $j] = $sup(['action' => 'article', 'id' => $articleId]);
check($code === 200 && str_contains((string)($j['article']['html'] ?? ''), 'Pay'), 'article opens with its body (html)');
[$code, $j] = $sup(['action' => 'article', 'id' => 99999999]);
check($code === 404, 'unknown article → 404');
[$code] = $sup(['action' => 'home'], null, 'GET');
check($code === 405, 'GET on a web action → 405, like the web');

section('3. A support request with an attachment');
$png = sys_get_temp_dir() . '/mh_' . bin2hex(random_bytes(3)) . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$ch = curl_init("http://127.0.0.1:{$m['port']}/api/mobile/support.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Host: {$m['host']}", "Authorization: Bearer $tok"],
    CURLOPT_POSTFIELDS => ['action' => 'create', 'subject' => 'Cannot print receipt', 'body' => 'The printer prints nothing after a sale.',
                           'category' => 'problem', 'attachments[0]' => new CURLFile($png, 'image/png', 'screen.png')]]);
$cj = json_decode((string)curl_exec($ch), true); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); @unlink($png);
$rid = (int)($cj['id'] ?? 0);
check($code === 200 && $rid > 0 && !empty($cj['ref']), "request sent with a screenshot (HTTP $code, " . ($cj['ref'] ?? $cj['message'] ?? '') . ')');

[$code, $j] = $sup(['action' => 'list']);
check($code === 200 && in_array($rid, array_column($j['requests'] ?? [], 'id'), true), 'it is in My requests');

[$code, $v] = $sup(['action' => 'view', 'id' => $rid], null, 'GET');
$att = $v['messages'][0]['attachments'][0] ?? null;
check($code === 200 && ($v['request']['subject'] ?? '') === 'Cannot print receipt' && ($v['messages'][0]['mine'] ?? null) === true, 'view: subject + my first message');
check($att && ($att['name'] ?? '') === 'screen.png', 'view: the attachment is listed');
check(($v['can_reply'] ?? null) === true && ($v['can_resolve'] ?? null) === true && ($v['can_rate'] ?? null) === false, 'view: buttons like the web (reply, resolve; no rate yet)');

$ch = curl_init("http://127.0.0.1:{$m['port']}" . ($att['url'] ?? '/'));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Host: {$m['host']}", "Authorization: Bearer $tok"]]);
$bin = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
check($code === 200 && str_starts_with($ct, 'image/png') && str_starts_with($bin, "\x89PNG"), 'attachment downloads as the PNG');

[$code] = $sup(['action' => 'reply', 'id' => $rid, 'body' => 'It is an Epson TM-T20.']);
[, $v] = $sup(['action' => 'view', 'id' => $rid], null, 'GET');
check($code === 200 && count(array_filter($v['messages'] ?? [], fn($x) => $x['author_type'] === 'tenant')) === 2, 'reply added to the conversation');
[$code] = $sup(['action' => 'resolve', 'id' => $rid]);
[, $v] = $sup(['action' => 'view', 'id' => $rid], null, 'GET');
check($code === 200 && ($v['request']['status'] ?? '') === 'resolved' && ($v['can_rate'] ?? null) === true, 'resolved → can rate');
[$code] = $sup(['action' => 'rate', 'id' => $rid, 'rating' => 5, 'comment' => 'Fast']);
[, $v] = $sup(['action' => 'view', 'id' => $rid], null, 'GET');
check($code === 200 && ($v['request']['rating'] ?? null) === 5, 'rated 5');
[$code, $j] = $sup(['action' => 'create', 'subject' => 'x', 'body' => 'y']);
check($code === 422, 'too-short request refused (422)');
check(is_file($capture), 'mail was captured, not sent (' . (is_file($capture) ? count(file($capture)) : 0) . ' messages)');

section('4. Staff');
$tp = mhTenantPdo($m);
$staffRole = (int)$tp->query("SELECT role_id FROM roles WHERE is_admin = 0 ORDER BY role_id LIMIT 1")->fetchColumn();
$tp->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id, can_view) SELECT $staffRole, permission_id, 1 FROM permissions WHERE page_key = 'help'");
mhCall($m, 'POST', '/api/mobile/users/create.php', ['username' => 'staff2', 'first_name' => 'Staff', 'last_name' => 'Two', 'email' => 'staff2@t.test',
    'role_id' => $staffRole, 'password' => 'Password1', 'confirm_password' => 'Password1'], $tok);
$stok = mhLogin($m, 'staff2');
[$code, $j] = $sup(['action' => 'list'], $stok);
check($code === 200 && !in_array($rid, array_column($j['requests'] ?? [], 'id'), true), "staff don't see the owner's request");
[$code] = $sup(['action' => 'view', 'id' => $rid], $stok, 'GET');
check($code === 404, "staff can't open it (404)");
[$code] = $sup(['action' => 'list'], '');
check($code === 401, 'no token → 401');
