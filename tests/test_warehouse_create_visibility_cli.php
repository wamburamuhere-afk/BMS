<?php
/**
 * tests/test_warehouse_create_visibility_cli.php
 *
 * Regression cover for the 2026-09-16 fix to app/bms/stock/warehouses.php:
 * a non-admin who creates a warehouse must actually see it afterwards (not
 * just be told to "contact your admin"). Drives the REAL page over real
 * HTTP, as the real handler runs it (POST → header() redirect → GET),
 * because the bug only reproduced through that full path — reading the
 * source was not enough to prove it either broken or fixed.
 *
 * Run: php tests/test_warehouse_create_visibility_cli.php
 * Self-skips if the local server is not reachable (set BMS_TEST_URL to
 * override the default http://localhost/bms).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0; $skip = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)    { global $skip; $skip++; echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$base  = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$probe = "$root/_whvis_probe.php";
$gUid  = 0;
$createdWarehouseId = null;

$cleanup = function () use (&$gUid, &$createdWarehouseId, $pdo, $probe) {
    if ($createdWarehouseId) {
        $pdo->prepare("DELETE FROM locations WHERE warehouse_id = ?")->execute([$createdWarehouseId]);
        $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = ?")->execute([$createdWarehouseId]);
    }
    if ($gUid) {
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$gUid]);
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$gUid]);
    }
    if (is_file($probe)) @unlink($probe);
};
register_shutdown_function($cleanup);

try {
    // ── Reachability ────────────────────────────────────────────────────
    section('0. Local server reachability');
    $reachable = false;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;
    ok($reachable, "server reachable at $base");
    if (!$reachable) {
        skip('HTTP section skipped — set BMS_TEST_URL to override');
        exit(0);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────
    section('1. Fixtures — throwaway non-admin user with Warehouses view+create');

    $roleRow = $pdo->query("
        SELECT rp.role_id
        FROM role_permissions rp
        JOIN permissions p ON p.permission_id = rp.permission_id
        JOIN roles r ON r.role_id = rp.role_id AND r.is_admin = 0
        GROUP BY rp.role_id
        HAVING MAX(CASE WHEN p.page_key = 'warehouses' THEN rp.can_view END) = 1
           AND MAX(CASE WHEN p.page_key = 'warehouses' THEN rp.can_create END) = 1
        LIMIT 1
    ")->fetchColumn();
    ok($roleRow !== false, 'found a non-admin role with warehouses view+create (role_id=' . var_export($roleRow, true) . ')');
    if ($roleRow === false) { throw new RuntimeException('no suitable role — cannot continue'); }

    $pdo->prepare("DELETE FROM users WHERE username = '__wh_visibility_test'")->execute();
    $pdo->prepare("INSERT INTO users (username, role_id) VALUES ('__wh_visibility_test', ?)")->execute([(int)$roleRow]);
    $gUid = (int)$pdo->lastInsertId();
    ok($gUid > 0, "throwaway user created (user_id=$gUid, role_id=$roleRow)");

    // Confirm they start with NO warehouse grant/history at all — the exact
    // precondition that reproduces the bug.
    $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$gUid]);

    // Probe: lets us set $_SESSION['user_id'] + load real permissions for a
    // chosen PHPSESSID over HTTP, exactly like a real login would, without
    // needing this throwaway user's real password.
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
$act = $_GET['act'] ?? '';
if ($act === 'login') {
    $_SESSION['user_id'] = (int)$_GET['uid'];
    loadUserPermissions((int)$_GET['role_id']);
    echo 'ok';
} else {
    echo 'unknown act';
}
PHP);
    ok(is_file($probe), 'probe file written to webroot');

    $sid = 'whvis' . bin2hex(random_bytes(8));
    $get = function (string $path, string $sid, array $extraHeaders = []) use ($base) {
        $headers = array_merge(["Cookie: PHPSESSID=$sid"], $extraHeaders);
        $ctx = stream_context_create(['http' => [
            'timeout' => 20, 'ignore_errors' => true, 'header' => implode("\r\n", $headers),
        ]]);
        $body = @file_get_contents("$base/$path", false, $ctx);
        return [(string)$body, $http_response_header ?? []];
    };
    $post = function (string $path, string $sid, array $fields) use ($base) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'timeout' => 20, 'ignore_errors' => true,
            'header'  => "Cookie: PHPSESSID=$sid\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($fields),
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents("$base/$path", false, $ctx);
        return [(string)$body, $http_response_header ?? []];
    };

    [$loginBody] = $get('_whvis_probe.php?act=login&uid=' . $gUid . '&role_id=' . (int)$roleRow, $sid);
    ok(trim($loginBody) === 'ok', 'session established for throwaway user via probe');

    // ── Establish CSRF token + baseline page state ──────────────────────
    section('2. Baseline GET — establish CSRF token');

    [$html1] = $get('app/bms/stock/warehouses.php', $sid);
    ok(str_contains($html1, 'addWarehouseModal'), 'warehouses.php rendered (not redirected to unauthorized/login)');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html1, $m);
    $csrf = $m[1] ?? '';
    ok($csrf !== '', 'CSRF token extracted from the rendered Add-Warehouse form');

    // ── Create the warehouse as the non-admin ───────────────────────────
    section('3. POST add_warehouse as the non-admin throwaway user');

    $code = 'WHVIS' . substr(bin2hex(random_bytes(3)), 0, 5);
    $name = 'Visibility Test WH ' . date('His');
    [, $postHeaders] = $post('app/bms/stock/warehouses.php', $sid, [
        'csrf_token'     => $csrf,
        'add_warehouse'  => '1',
        'warehouse_name' => $name,
        'warehouse_code' => $code,
        'status'         => 'active',
        'pos_mode'       => 'retail',
    ]);
    $locationHeader = '';
    foreach ($postHeaders as $h) { if (stripos($h, 'Location:') === 0) $locationHeader = trim(substr($h, 9)); }
    ok(str_contains($locationHeader, 'warehouses.php'), "POST redirected back to warehouses.php (Location: $locationHeader)");

    $row = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_code = ?");
    $row->execute([$code]);
    $createdWarehouseId = (int)$row->fetchColumn();
    ok($createdWarehouseId > 0, "warehouse row actually created in DB (warehouse_id=$createdWarehouseId)");

    // ── The actual fix under test: auto-grant + genuine visibility ──────
    section('4. Auto-grant — creator now has a user_scope_overrides row for it');

    $grantRow = $pdo->prepare("SELECT COUNT(*) FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse' AND resource_id = ?");
    $grantRow->execute([$gUid, $createdWarehouseId]);
    ok((int)$grantRow->fetchColumn() === 1, 'user_scope_overrides row auto-inserted for creator → new warehouse');

    section('5. Follow-up GET — real page, real session, real render');

    [$html2] = $get('app/bms/stock/warehouses.php', $sid);
    ok(str_contains($html2, 'alert-success'), 'success alert (green) rendered on the follow-up page');
    ok(!str_contains($html2, 'alert-warning'), 'NO "contact your admin" warning alert rendered — creator does not need one');
    ok(str_contains($html2, htmlspecialchars($code)), "the new warehouse code ($code) appears in the rendered table HTML");
    ok(str_contains($html2, htmlspecialchars($name)), "the new warehouse name appears in the rendered table HTML");
    ok(str_contains($html2, "data-order=\"$createdWarehouseId\""), 'new row carries the numeric data-order attribute used for newest-first sort');
    ok(str_contains($html2, "order: [[1, 'desc']]"), "DataTable now defaults to newest-first (was Stock Value desc, which buried a 0-value new row)");

    // ── Regression: a second, DIFFERENT non-admin still doesn't see it ──
    section('6. Regression — an unrelated non-admin still does NOT see this warehouse');

    $pdo->prepare("DELETE FROM users WHERE username = '__wh_visibility_test_2'")->execute();
    $pdo->prepare("INSERT INTO users (username, role_id) VALUES ('__wh_visibility_test_2', ?)")->execute([(int)$roleRow]);
    $gUid2 = (int)$pdo->lastInsertId();
    $sid2 = 'whvis2' . bin2hex(random_bytes(8));
    $get('_whvis_probe.php?act=login&uid=' . $gUid2 . '&role_id=' . (int)$roleRow, $sid2);
    [$html3] = $get('app/bms/stock/warehouses.php', $sid2);
    ok(!str_contains($html3, htmlspecialchars($code)), 'a DIFFERENT non-admin (no grant on this warehouse) still cannot see it — auto-grant is scoped to the creator only, not everyone');
    $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$gUid2]);
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$gUid2]);

} catch (Throwable $e) {
    echo "\n\033[31mFATAL: {$e->getMessage()}\033[0m\n";
    $fail++;
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[32m✅ All $total checks passed" . ($skip ? " ($skip skipped)" : '') . ".\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail / $total check(s) failed.\033[0m\n\n";
    exit(1);
}
