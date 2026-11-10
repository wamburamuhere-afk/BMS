<?php
/**
 * tests/test_user_roles_crud_logging_cli.php — User Roles & Permissions: every create / edit / delete / assign
 * is logged with who did what, the page refuses forged or broken requests, and a RETAIL company sees the
 * page exactly as stored (no restaurant rows, names or notes).
 *
 *   php tests/test_user_roles_crud_logging_cli.php
 *
 * Runs the REAL page (app/constant/settings/user_roles.php) and its Access Audit endpoint in a child PHP
 * process — the same code a browser request reaches — as the local admin.
 *
 *   1. pure: snapshot → changes → log text
 *   2. create role → role + rights saved, logged as CREATE (was logged as "Edit") with the rights given
 *   3. edit role → rename + rights added/removed, logged with old → new and every right changed
 *   4. save with no change → logged "no rights changed"
 *   5. forged request (no / wrong CSRF token) → nothing changes, nothing logged
 *   6. edit a role that does not exist → refused, no rights written for that id
 *   7. assign role → logged with the user's name and old → new role; same role again → no new log;
 *      a role or user that does not exist → refused, user unchanged
 *   8. delete → refused while a user holds it; the Admin role can never be deleted; then deleted with
 *      its rights listed in the log
 *   9. Access Audit tab endpoint → real JSON with who/what/when for the above
 *  10. ready-made restaurant roles: rights the system adds are logged
 *  11. RETAIL render (Restaurant POS off): every row shows its stored name and description, no restaurant
 *      tab, no restaurant note, no ready-made restaurant role
 *  12. RETAIL render (Restaurant POS on, every shop Retail): same as 11
 *  13. RESTAURANT render (every shop Restaurant): restaurant words; MIXED render: Restaurant tab + notes
 *
 * Everything it creates is removed at the end (roles, rights, test user, their log rows); shop modes
 * changed for 12/13 are restored in a finally block.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/role_permission_ui.php';
require_once __DIR__ . '/../core/restaurant_order_flow_schema.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$ROOT = realpath(__DIR__ . '/..');
$TMP  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz_user_roles_' . getmypid();
@mkdir($TMP);
$ADMIN = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
ok('an admin user exists to act as', $ADMIN > 0);

// The child: boots like a request, signs in as the admin, then runs the real page/endpoint.
$childFile = $TMP . DIRECTORY_SEPARATOR . 'child.php';
file_put_contents($childFile, '<?php
$spec = json_decode(base64_decode($argv[1]), true);
chdir($spec["root"]);
$_SERVER["REQUEST_METHOD"] = $spec["method"];
$_SERVER["PHP_SELF"] = "/user_roles"; $_SERVER["REQUEST_URI"] = "/user_roles";
$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REMOTE_ADDR"] = "127.0.0.9"; $_SERVER["HTTP_USER_AGENT"] = "zztest-user-roles";
require $spec["root"] . "/roots.php";
$_SESSION["user_id"] = $spec["admin"]; $_SESSION["username"] = "admin"; $_SESSION["role_id"] = 1; $_SESSION["is_admin"] = true;
$_SESSION["user_lang"] = $spec["lang"] ?? "en";
$_SESSION["csrf_token"] = "zz-good-token";
if (isset($spec["features"])) { $f = []; foreach (allFeatureKeys() as $k) $f[$k] = in_array($k, $spec["features"], true); $GLOBALS["__bms_features"] = $f; }
$_POST = $spec["post"] ?? []; $_GET = $spec["get"] ?? [];
register_shutdown_function(function () use ($spec) {
    file_put_contents($spec["msg"], json_encode(["ok" => $_SESSION["role_success_messages"] ?? [], "err" => $_SESSION["role_error_messages"] ?? []]));
});
require $spec["root"] . "/" . $spec["file"];
');

function runPage(string $method, array $post = [], array $extra = []): array {
    global $ROOT, $TMP, $ADMIN, $childFile;
    $msg = $TMP . DIRECTORY_SEPARATOR . 'msg.json';
    @unlink($msg);
    $spec = array_merge(['root' => $ROOT, 'admin' => $ADMIN, 'method' => $method, 'post' => $post,
                         'file' => 'app/constant/settings/user_roles.php', 'msg' => $msg], $extra);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childFile) . ' ' . escapeshellarg(base64_encode(json_encode($spec)));
    $out = shell_exec($cmd . ' 2>&1');
    $m = is_file($msg) ? json_decode((string)file_get_contents($msg), true) : ['ok' => [], 'err' => []];
    return ['html' => (string)$out, 'ok' => $m['ok'] ?? [], 'err' => $m['err'] ?? []];
}
function post(array $fields, bool $goodToken = true): array {
    if ($goodToken) $fields['_csrf'] = 'zz-good-token';
    return runPage('POST', $fields);
}
function lastAudit(string $action, int $entityId): ?array {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM audit_logs WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $s->execute([$action, $entityId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function countAudit(string $action, int $entityId): int {
    global $pdo;
    $s = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_id = ?");
    $s->execute([$action, $entityId]);
    return (int)$s->fetchColumn();
}
function roleId(string $name): int {
    global $pdo;
    $s = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ?");
    $s->execute([$name]);
    return (int)$s->fetchColumn();
}

$ids = [];
foreach ($pdo->query("SELECT permission_id, page_key, page_name, description FROM permissions") as $r) $ids[$r['page_key']] = (int)$r['permission_id'];
$tag = 'ZZTEST UR ' . substr(uniqid(), -6);
$created = ['roles' => [], 'users' => []];
$shopModes = null;

echo "\nBMS — User Roles & Permissions: CRUD, activity logs, retail safety\n";
try {
    // ─────────────────────────────────────────────────────────────────────────
    section('1. Pure: what changed');
    $ch = rolePermissionChanges([10 => ['view', 'create'], 11 => ['view']], [10 => ['view'], 12 => ['view', 'approve']], [10 => 'POS System', 11 => 'Customers', 12 => 'Restaurant Orders']);
    ok('three rows changed, sorted by name', array_column($ch, 'page') === ['Customers', 'POS System', 'Restaurant Orders']);
    ok('removed row lists its rights', $ch[0]['removed'] === ['view'] && $ch[0]['added'] === []);
    ok('narrowed row lists only the removed right', $ch[1]['removed'] === ['create'] && $ch[1]['added'] === []);
    ok('new row lists its rights', $ch[2]['added'] === ['view', 'approve']);
    ok('log text', rolePermissionChangesText($ch) === 'Customers: -view; POS System: -create; Restaurant Orders: +view, +approve');
    ok('no change → plain words', rolePermissionChangesText([]) === 'no rights changed');
    $many = array_map(fn($i) => ['page' => "P$i", 'added' => ['view'], 'removed' => []], range(1, 30));
    ok('long list cut with "… and N more"', str_ends_with(rolePermissionChangesText($many), '… and 5 more'));
    ok('Admin role protected (id 1)', rolePermissionIsProtectedRole(['role_id' => 1, 'is_admin' => 0]));
    ok('any is_admin role protected', rolePermissionIsProtectedRole(['role_id' => 77, 'is_admin' => 1]));
    ok('ordinary role not protected', !rolePermissionIsProtectedRole(['role_id' => 77, 'is_admin' => 0]));

    // ─────────────────────────────────────────────────────────────────────────
    section('2. Create role');
    $name = "$tag Cashier";
    $r = post(['save_role' => 1, 'role_id' => '', 'role_name' => $name, 'role_description' => 'test role',
               'perms' => [$ids['pos'] => ['view' => 'on', 'create' => 'on'], $ids['customers'] => ['view' => 'on']]]);
    $rid = roleId($name);
    if ($rid) $created['roles'][] = $rid;
    ok('role created', $rid > 0 && $r['err'] === []);
    ok('success message shown', in_array('Role created successfully', $r['ok'], true));
    $snap = rolePermissionSnapshot($pdo, $rid);
    ok('rights saved', ($snap[$ids['pos']] ?? []) === ['view', 'create'] && ($snap[$ids['customers']] ?? []) === ['view']);
    $a = lastAudit('create_role', $rid);
    ok('logged as CREATE (not as an edit)', $a !== null && countAudit('update_role', $rid) === 0);
    ok('log names the actor', $a && (int)$a['user_id'] === $ADMIN);
    ok('log says which rights were given', $a && str_contains($a['description'], 'Created role "' . $name . '"')
        && str_contains($a['description'], '+view, +create') && str_contains($a['description'], '+view'));
    $nv = $a ? json_decode((string)$a['new_values'], true) : [];
    ok('audit values hold every change', count($nv['changes'] ?? []) === 2 && ($nv['rights_held'] ?? 0) === 2);
    $s = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND action = 'Create role' AND description LIKE ?");
    $s->execute([$ADMIN, '%' . $name . '%']);
    ok('activity log line too (Activity Log page)', (int)$s->fetchColumn() === 1);

    // ─────────────────────────────────────────────────────────────────────────
    section('3. Edit role: rename + rights');
    $name2 = "$tag Head Cashier";
    $r = post(['save_role' => 1, 'role_id' => (string)$rid, 'role_name' => $name2, 'role_description' => 'changed',
               'perms' => [$ids['pos'] => ['view' => 'on'], $ids['sales_report'] => ['view' => 'on']]]);
    ok('saved without error', $r['err'] === [] && roleId($name2) === $rid);
    $a = lastAudit('update_role', $rid);
    ok('logged as EDIT', $a !== null);
    ok('log shows the rename', $a && str_contains($a['description'], "renamed \"$name\" → \"$name2\""));
    ok('log shows description changed', $a && str_contains($a['description'], 'description changed'));
    ok('log shows the removed right', $a && str_contains($a['description'], '-create'));
    ok('log shows the removed row', $a && str_contains($a['description'], '-view'));
    $ov = $a ? json_decode((string)$a['old_values'], true) : [];
    ok('old name kept in the audit values', ($ov['role_name'] ?? '') === $name);

    // ─────────────────────────────────────────────────────────────────────────
    section('4. Save with no change');
    $before = countAudit('update_role', $rid);
    post(['save_role' => 1, 'role_id' => (string)$rid, 'role_name' => $name2, 'role_description' => 'changed',
          'perms' => [$ids['pos'] => ['view' => 'on'], $ids['sales_report'] => ['view' => 'on']]]);
    $a = lastAudit('update_role', $rid);
    ok('still logged (who saved, when)', countAudit('update_role', $rid) === $before + 1);
    ok('says no rights changed', $a && str_contains($a['description'], 'rights: no rights changed'));

    // ─────────────────────────────────────────────────────────────────────────
    section('5. Forged request (CSRF)');
    $before = countAudit('update_role', $rid);
    $r = post(['save_role' => 1, 'role_id' => (string)$rid, 'role_name' => "$tag HACKED", 'role_description' => '',
               'perms' => [$ids['pos'] => ['view' => 'on', 'create' => 'on', 'edit' => 'on', 'delete' => 'on']]], false);
    ok('no token → refused with a message', $r['err'] !== [] && roleId("$tag HACKED") === 0);
    ok('rights untouched', (rolePermissionSnapshot($pdo, $rid)[$ids['pos']] ?? []) === ['view']);
    $r = runPage('POST', ['_csrf' => 'wrong', 'delete_role' => 1, 'role_id' => (string)$rid]);
    ok('wrong token → delete refused', roleId($name2) === $rid);
    ok('nothing logged for forged requests', countAudit('update_role', $rid) === $before && countAudit('delete_role', $rid) === 0);

    // ─────────────────────────────────────────────────────────────────────────
    section('6. Edit a role that does not exist');
    $ghost = (int)$pdo->query("SELECT COALESCE(MAX(role_id), 0) + 1000 FROM roles")->fetchColumn();
    $r = post(['save_role' => 1, 'role_id' => (string)$ghost, 'role_name' => "$tag Ghost", 'role_description' => '',
               'perms' => [$ids['pos'] => ['view' => 'on']]]);
    $s = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
    $s->execute([$ghost]);
    ok('refused', $r['err'] !== []);
    ok('no rights written for a missing role', (int)$s->fetchColumn() === 0);

    // ─────────────────────────────────────────────────────────────────────────
    section('7. Assign a role to a user');
    $uname = 'zztest_ur_' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, role_id, is_active) VALUES (?, ?, ?, 'Zz', 'Tester', 4, 1)")
        ->execute([$uname, "$uname@example.invalid", password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    $created['users'][] = $uid;
    $oldRole = (string)$pdo->query("SELECT role_name FROM roles WHERE role_id = 4")->fetchColumn();
    $r = post(['update_user_role' => 1, 'user_id' => (string)$uid, 'role_id' => (string)$rid]);
    ok('role changed', (int)$pdo->query("SELECT role_id FROM users WHERE user_id = $uid")->fetchColumn() === $rid && $r['err'] === []);
    $a = lastAudit('update_user_role', $uid);
    ok('logged with the user\'s name and old → new role', $a && str_contains($a['description'], "Zz Tester ($uname")
        && str_contains($a['description'], "from \"$oldRole\" to \"$name2\""));
    ok('log names the actor', $a && (int)$a['user_id'] === $ADMIN);
    $before = countAudit('update_user_role', $uid);
    post(['update_user_role' => 1, 'user_id' => (string)$uid, 'role_id' => (string)$rid]);
    ok('same role again → no new log line (nothing changed)', countAudit('update_user_role', $uid) === $before);
    $r = post(['update_user_role' => 1, 'user_id' => (string)$uid, 'role_id' => (string)$ghost]);
    ok('a role that does not exist → refused, user unchanged', $r['err'] !== []
        && (int)$pdo->query("SELECT role_id FROM users WHERE user_id = $uid")->fetchColumn() === $rid);
    $r = post(['update_user_role' => 1, 'user_id' => '999999999', 'role_id' => (string)$rid]);
    ok('a user that does not exist → refused', $r['err'] !== []);

    // ─────────────────────────────────────────────────────────────────────────
    section('8. Delete');
    $r = post(['delete_role' => 1, 'role_id' => (string)$rid]);
    ok('refused while a user holds it', $r['err'] !== [] && roleId($name2) === $rid);
    $r = post(['delete_role' => 1, 'role_id' => '1']);
    ok('Admin role can never be deleted', $r['err'] !== [] && (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE role_id = 1")->fetchColumn() === 1);
    ok('nothing logged for refused deletes', countAudit('delete_role', $rid) === 0 && countAudit('delete_role', 1) === 0);
    post(['update_user_role' => 1, 'user_id' => (string)$uid, 'role_id' => '4']);
    $r = post(['delete_role' => 1, 'role_id' => (string)$rid]);
    $s = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
    $s->execute([$rid]);
    ok('deleted with its rights', $r['err'] === [] && roleId($name2) === 0 && (int)$s->fetchColumn() === 0);
    $a = lastAudit('delete_role', $rid);
    ok('logged with every right it held', $a && str_contains($a['description'], "Deleted role \"$name2\"")
        && str_contains($a['description'], '2 right(s)') && str_contains($a['description'], ': view')
        && !str_contains($a['description'], '+'));
    $ov = $a ? json_decode((string)$a['old_values'], true) : [];
    ok('audit values keep the rights by name', count($ov['rights'] ?? []) === 2);
    $html = runPage('GET')['html'];
    ok('page: no delete button for the Admin role', !preg_match('/class="btn btn-sm btn-outline-danger delete-role"\s+data-role-id="1"/', $html));
    ok('page: every form carries the CSRF token', substr_count($html, 'name="_csrf"') === 3);

    // ─────────────────────────────────────────────────────────────────────────
    section('9. Access Audit tab');
    $j = json_decode(runPage('GET', [], ['file' => 'ajax/get_access_log.php'])['html'], true);
    ok('endpoint answers JSON success', is_array($j) && ($j['success'] ?? false) === true);
    $mine = array_values(array_filter($j['logs'] ?? [], fn($l) => str_contains((string)$l['resource'], $tag)));
    $acts = array_column($mine, 'action');
    ok('shows create, edit, assign and delete', in_array('Created role', $acts, true) && in_array('Edited role', $acts, true)
        && in_array('Changed user role', $acts, true) && in_array('Deleted role', $acts, true));
    ok('every line says who', $mine && !array_filter($mine, fn($l) => trim((string)$l['user_name']) === ''));
    ok('newest first', $mine && $mine[0]['action'] === 'Deleted role');
    ok('stats filled', isset($j['stats']['total_logs'], $j['stats']['today_activities'], $j['stats']['most_active_user']) && $j['stats']['today_activities'] >= count($mine));
    ok('page escapes what it shows (role names are typed by people)', str_contains(file_get_contents($ROOT . '/app/constant/settings/user_roles.php'), '${urEsc(log.resource)}'));
    ok('no button left pointing at files that do not exist', !preg_match('/clear_old_logs|generate_access_report/', $html));

    // ─────────────────────────────────────────────────────────────────────────
    section('10. Ready-made restaurant role rights are logged');
    $wid = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'Waiter' AND description LIKE 'Restaurant waiter:%' LIMIT 1")->fetchColumn();
    if ($wid && isset($ids['restaurant_pos'])) {
        $pdo->beginTransaction();
        try {
            $_SESSION['user_id'] = $ADMIN; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?")->execute([$wid, $ids['restaurant_pos']]);
            require_once $ROOT . '/core/restaurant_ready_roles.php';
            $done = restaurantReadyRolesSync($pdo, true);
            $s = $pdo->prepare("SELECT description FROM activity_logs WHERE action = 'Restaurant: ready-made role rights added' AND user_id = ? ORDER BY id DESC LIMIT 1");
            $s->execute([$ADMIN]);
            $line = (string)$s->fetchColumn();
            ok('the right the system put back is logged, under the person who had the page open', str_contains($line, 'Waiter: restaurant_pos'));
        } finally {
            $pdo->rollBack();
        }
    } else {
        ok('ready-made Waiter role present locally (skip if this DB has no restaurant)', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    $parseRows = function (string $html): array {
        libxml_use_internal_errors(true);
        $d = new DOMDocument();
        $d->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $x = new DOMXPath($d);
        $rows = [];
        foreach ($x->query('//tr[contains(@class,"perm-row")]') as $tr) {
            $cb = $x->query('.//input[contains(@class,"perm-check")]', $tr)->item(0);
            $divs = $x->query('./td[1]/div', $tr);
            $rows[(int)$cb->getAttribute('data-perm-id')] = [
                'name' => trim($divs->item(0)->textContent),
                'desc' => trim($divs->item(1)->textContent),
                'extra' => $divs->length > 2 ? trim($divs->item(2)->textContent) : '',
            ];
        }
        $tabs = [];
        foreach ($x->query('//ul[@id="moduleTabs"]//button') as $b) $tabs[] = trim(preg_replace('/\s+\d+$/', '', trim(preg_replace('/\s+/', ' ', $b->textContent))));
        $roles = [];
        foreach ($x->query('//div[@id="roles"]//h6[@class="mb-1"]') as $h) $roles[] = trim($h->textContent);
        return ['rows' => $rows, 'tabs' => $tabs, 'roles' => $roles];
    };
    $stored = [];
    foreach ($pdo->query("SELECT permission_id, page_key, page_name, description FROM permissions") as $p) $stored[(int)$p['permission_id']] = $p;
    $restKeys = rolePermissionRestaurantKeys();
    $readyNames = array_keys(rofsReadyRoles());
    $readyHeld = (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name IN ('Waiter','Cashier','Cook','Restaurant Manager')")->fetchColumn();

    $retailChecks = function (array $p, string $label) use ($stored, $restKeys, $readyNames, $readyHeld) {
        ok("$label: rows rendered", count($p['rows']) > 10);
        $wrong = [];
        foreach ($p['rows'] as $pid => $r) {
            $s = $stored[$pid] ?? null;
            if (!$s || $r['name'] !== (string)$s['page_name'] || $r['desc'] !== (string)$s['description'] || $r['extra'] !== '' && !str_starts_with($r['extra'], 'Review/Approve')) {
                $wrong[] = $s['page_key'] ?? "#$pid";
            }
        }
        ok("$label: every row shows its stored name and description, no extra note" . ($wrong ? ' — ' . implode(', ', $wrong) : ''), $wrong === []);
        $keys = array_map(fn($pid) => (string)($stored[$pid]['page_key'] ?? ''), array_keys($p['rows']));
        ok("$label: no restaurant row", array_intersect($keys, $restKeys) === []);
        ok("$label: no Mobile Money row", !array_filter($keys, fn($k) => str_starts_with($k, 'mm_')));
        ok("$label: no Restaurant tab", !in_array('Restaurant', $p['tabs'], true));
        ok("$label: POS rows still shown", in_array('pos', $keys, true) && in_array('pos_restock', $keys, true));
        if ($readyHeld === 0) ok("$label: no ready-made restaurant role in the list", array_intersect($p['roles'], $readyNames) === []);
    };

    section('11. Retail company (Restaurant POS off)');
    $retailFeatures = array_values(array_diff(allFeatureKeys(), ['restaurant_pos']));
    $p = $parseRows(runPage('GET', [], ['features' => $retailFeatures])['html']);
    $retailChecks($p, 'retail, module off');

    // Shop modes are changed for 12/13 (another process must see them) and restored in finally.
    $shopModes = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses")->fetchAll(PDO::FETCH_KEY_PAIR);

    section('12. Retail company (Restaurant POS on, every shop Retail)');
    $pdo->exec("UPDATE warehouses SET pos_mode = 'retail'");
    $p = $parseRows(runPage('GET', [], ['features' => allFeatureKeys()])['html']);
    $retailChecks($p, 'retail, module on');

    section('13. Restaurant and mixed companies');
    $pdo->exec("UPDATE warehouses SET pos_mode = 'restaurant'");
    $smart = get_setting('pos_simple_mode', '0') === '1';
    $p = $parseRows(runPage('GET', [], ['features' => allFeatureKeys()])['html']);
    $byKey = [];
    foreach ($p['rows'] as $pid => $r) $byKey[(string)$stored[$pid]['page_key']] = $r;
    ok('restaurant: Restaurant tab', in_array('Restaurant', $p['tabs'], true));
    ok('restaurant: restaurant rows shown', isset($byKey['restaurant_orders'], $byKey['kitchen_display'], $byKey['restaurant_pos']));
    if ($smart) {
        ok('restaurant: POS row in restaurant words', ($byKey['pos']['name'] ?? '') === 'Orders & Till' && str_contains($byKey['pos']['desc'] ?? '', 'waiter'));
        ok('restaurant: Restock row in restaurant words', ($byKey['pos_restock']['name'] ?? '') === 'Purchases & Stock');
        ok('restaurant: no retail note left', !array_filter($p['rows'], fn($r) => str_contains($r['extra'], 'In a restaurant shop')));
    }
    ok('restaurant: nothing a restaurant uses is hidden', isset($byKey['pos_price_override'], $byKey['pos_discount_override'], $byKey['pos_advanced'], $byKey['products']));
    $p2 = $parseRows(runPage('GET', [], ['features' => allFeatureKeys(), 'lang' => 'sw'])['html']);
    $sw = [];
    foreach ($p2['rows'] as $pid => $r) $sw[(string)$stored[$pid]['page_key']] = $r;
    if ($smart) ok('restaurant (Kiswahili): restaurant words translated', ($sw['pos']['name'] ?? '') === 'Oda na Kaunta');

    $first = (int)array_key_first($shopModes);
    $pdo->exec("UPDATE warehouses SET pos_mode = 'retail'");
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$first]);
    $p = $parseRows(runPage('GET', [], ['features' => allFeatureKeys()])['html']);
    $byKey = [];
    foreach ($p['rows'] as $pid => $r) $byKey[(string)$stored[$pid]['page_key']] = $r;
    ok('mixed: Restaurant tab', in_array('Restaurant', $p['tabs'], true));
    ok('mixed: POS row keeps its retail name and text', ($byKey['pos']['name'] ?? '') === (string)$stored[$ids['pos']]['page_name']
        && ($byKey['pos']['desc'] ?? '') === (string)$stored[$ids['pos']]['description']);
    ok('mixed: POS row gets the restaurant note', str_starts_with($byKey['pos']['extra'] ?? '', 'In a restaurant shop:'));
} finally {
    if (is_array($shopModes)) {
        $s = $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?");
        foreach ($shopModes as $id => $m) $s->execute([$m, $id]);
        $now = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses")->fetchAll(PDO::FETCH_KEY_PAIR);
        ok('shop modes restored exactly', $now == $shopModes);
    }
    // Remove everything this test made, including its own log rows.
    $roleIds = $pdo->query("SELECT role_id FROM roles WHERE role_name LIKE " . $pdo->quote("$tag%"))->fetchAll(PDO::FETCH_COLUMN);
    $roleIds = array_values(array_unique(array_merge(array_map('intval', $roleIds), $created['roles'])));
    foreach ($roleIds as $id) {
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM roles WHERE role_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'role' AND entity_id = ? AND action IN ('create_role','update_role','delete_role')")->execute([$id]);
    }
    foreach ($created['users'] as $id) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'user' AND entity_id = ? AND action = 'update_user_role'")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM activity_logs WHERE description LIKE ?")->execute(["%$tag%"]);
    $pdo->prepare("DELETE FROM activity_logs WHERE user_agent = 'zztest-user-roles'")->execute();
    $left = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE role_name LIKE " . $pdo->quote("$tag%"))->fetchColumn();
    ok('test roles removed', $left === 0);
    array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
    @rmdir($TMP);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
