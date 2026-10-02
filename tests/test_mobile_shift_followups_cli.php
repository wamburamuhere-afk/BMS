<?php
/**
 * tests/test_mobile_shift_followups_cli.php — over real HTTP, like Insomnia.
 *
 *   php tests/test_mobile_shift_followups_cli.php
 *   BMS_API_BASE (default http://dev.bms.local)
 *
 *   1. notifications/list.php: a POS-only user (no cash_register permission)
 *      now gets their own "shift not closed" alert — and only their own;
 *      an admin still sees everyone's.
 *   2. me.php returns the user's NEWEST open shift.
 *
 * Fixtures (role, user, 2 tokens, shifts) are created here and removed in finally.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../roots.php';

$BASE = rtrim(getenv('BMS_API_BASE') ?: 'http://dev.bms.local', '/');
$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function http(string $path, string $token): array {
    global $BASE;
    $ch = curl_init("$BASE/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}
// groups is a list of {key, title, color, count, items}.
$shiftItems = function (?array $j): array {
    $out = [];
    foreach ($j['groups'] ?? [] as $g) {
        if (($g['key'] ?? '') !== 'cash_bank') continue;
        foreach ($g['items'] ?? [] as $i) if (($i['type'] ?? '') === 'cash_shift_open') $out[] = $i;
    }
    return $out;
};
$shiftIds = fn(?array $j) => array_map(fn($i) => (int)$i['id'], $shiftItems($j));

echo "\nBMS — mobile API shift follow-ups (HTTP: $BASE)\n";

$admin = $pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$posPerm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'pos'")->fetchColumn();
$tag = 'ZZTEST-MSF-' . substr(bin2hex(random_bytes(3)), 0, 6);
$roleId = $userId = null; $tokens = []; $shifts = [];

try {
    $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, 'test', NOW())")->execute([$tag]);
    $roleId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 0, 0)")
        ->execute([$roleId, $posPerm]);
    $pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password) VALUES (?, ?, 'Zz', 'Cashier', ?, 1, ?)")
        ->execute([strtolower($tag), strtolower($tag) . '@example.test', $roleId, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();

    $mkToken = function (int $uid) use ($pdo, &$tokens, $tag): string {
        $t = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO mobile_tokens (token, user_id, device_name, created_at) VALUES (?, ?, ?, NOW())")->execute([$t, $uid, $tag]);
        $tokens[] = (int)$pdo->lastInsertId();
        return $t;
    };
    $cashierTok = $mkToken($userId);
    $adminTok   = $mkToken((int)$admin);

    $mkShift = function (int $uid, string $start) use ($pdo, &$shifts, $tag): int {
        $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, start_time, starting_cash, status) VALUES (?, ?, ?, 0, 'active')")
            ->execute([$tag . '-' . count($shifts), $uid, $start]);
        return $shifts[] = (int)$pdo->lastInsertId();
    };
    $cashierOld   = $mkShift($userId, date('Y-m-d 08:00:00', strtotime('-3 days')));
    $cashierToday = $mkShift($userId, date('Y-m-d H:i:s', time() - 60));
    $adminOld     = $mkShift((int)$admin, date('Y-m-d 09:00:00', strtotime('-2 days')));

    echo "\n== 1. notifications/list.php ==\n";
    [$c, $j, $raw] = http('api/mobile/notifications/list.php', $cashierTok);
    ok('POS-only cashier: HTTP 200 JSON', $c === 200 && is_array($j), "$c " . substr($raw, 0, 200));
    $ids = $shiftIds($j);
    ok("POS-only cashier gets their own stale shift (#$cashierOld)", in_array($cashierOld, $ids, true), json_encode($ids));
    ok("…but not another user's (#$adminOld)", !in_array($adminOld, $ids, true), json_encode($ids));
    ok("…and not today's shift (#$cashierToday)", !in_array($cashierToday, $ids, true));
    $item = array_values(array_filter($shiftItems($j), fn($i) => (int)$i['id'] === $cashierOld))[0] ?? [];
    ok('alert carries start_time + days_open = 3', ($item['days_open'] ?? null) === 3 && !empty($item['start_time']), json_encode($item));
    ok('badge_count includes it', (int)($j['badge_count'] ?? 0) >= 1);

    [$c, $j] = http('api/mobile/notifications/list.php', $adminTok);
    $ids = $shiftIds($j);
    ok('admin: HTTP 200 and sees both users\' stale shifts', $c === 200 && in_array($cashierOld, $ids, true) && in_array($adminOld, $ids, true), json_encode($ids));

    echo "\n== 2. me.php ==\n";
    [$c, $j, $raw] = http('api/mobile/me.php', $cashierTok);
    $shift = $j['shift'] ?? ($j['data']['shift'] ?? ($j['user']['shift'] ?? null));
    ok('me.php: HTTP 200', $c === 200, "$c " . substr($raw, 0, 200));
    ok("me.php returns the NEWEST open shift (#$cashierToday, not #$cashierOld)", is_array($shift) && (int)$shift['shift_id'] === $cashierToday, json_encode($shift));

    [$c] = http('api/mobile/notifications/list.php', str_repeat('0', 64));
    ok('unknown token is refused (401)', $c === 401);
} finally {
    if ($shifts) $pdo->exec("DELETE FROM cash_register_shifts WHERE shift_id IN (" . implode(',', array_map('intval', $shifts)) . ")");
    if ($tokens) $pdo->exec("DELETE FROM mobile_tokens WHERE token_id IN (" . implode(',', array_map('intval', $tokens)) . ")");
    if ($userId) $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
    if ($roleId) { $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]); $pdo->prepare("DELETE FROM roles WHERE role_id = ?")->execute([$roleId]); }
}

echo "\n== 3. Clean-up ==\n";
ok('fixtures removed', (int)$pdo->query("SELECT COUNT(*) FROM mobile_tokens WHERE device_name LIKE 'ZZTEST-MSF-%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM cash_register_shifts WHERE shift_code LIKE 'ZZTEST-MSF-%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE role_name LIKE 'ZZTEST-MSF-%'")->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
