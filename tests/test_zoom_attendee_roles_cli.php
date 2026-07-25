<?php
/**
 * Zoom Integration — Attendee Role Picker CLI test.
 *   php tests/test_zoom_attendee_roles_cli.php
 *
 * Verifies api/zoom/get_attendee_roles.php:
 *   - a role appears ONLY if it has meetings view access AND >=1 active user
 *     (NO employee-record link required — dropped per follow-up: a Zoom
 *     attendee only needs a BMS login, not HR employee data)
 *   - a role with meetings access but no users is excluded
 *   - a role with users but no meetings access is excluded
 *   - a role flagged roles.is_admin=1 ("full system access") is included even
 *     with ZERO row in role_permissions for 'meetings' — the same bypass
 *     canView()/isAdmin() apply everywhere else in the app
 *   - each returned user carries the correct user_id (not employee_id)
 *   - non-admin without create/edit meetings permission is denied
 * All fixtures cleaned up in a finally block. Exit 0 = pass.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require "$root/api/zoom/get_attendee_roles.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

function callEndpoint(array $session): array {
    $cfg = ['session' => $session];
    $f = tempnam(sys_get_temp_dir(), 'zar');
    file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}

$fixtures = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();

    // Fixture A: role WITH meetings access (Admin, role_id=1) + a plain active user,
    // NO employee record at all -> should appear anyway.
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,first_name,last_name,is_active,created_at) VALUES ('__zar_admin','x','zara@example.local',0,1,'__ZAR','Eligible',1,NOW())");
    $userA = (int)$pdo->lastInsertId(); $fixtures[] = ['users', $userA];

    // Fixture B: role WITHOUT meetings access (Staff, role_id=4) + a plain active user -> must NOT appear.
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,first_name,last_name,is_active,created_at) VALUES ('__zar_staff','x','zarb@example.local',0,4,'__ZAR','NoAccess',1,NOW())");
    $userB = (int)$pdo->lastInsertId(); $fixtures[] = ['users', $userB];

    // Fixture C: a brand-new role flagged is_admin=1 (full access) with ZERO row in
    // role_permissions for anything, + a plain active user -> must appear anyway.
    $pdo->exec("INSERT INTO roles (role_name, description, is_admin, created_at) VALUES ('__ZAR Full Access Role', 'test fixture', 1, NOW())");
    $roleC = (int)$pdo->lastInsertId(); $fixtures[] = ['roles', $roleC];
    $pdo->prepare("INSERT INTO users (username,password,email,is_admin,role_id,first_name,last_name,is_active,created_at) VALUES ('__zar_full','x','zarc@example.local',0,?,'__ZAR','FullAccess',1,NOW())")->execute([$roleC]);
    $userC = (int)$pdo->lastInsertId(); $fixtures[] = ['users', $userC];

    section('1. Admin call — role filtering');
    $res = callEndpoint(['user_id'=>$admin_uid,'is_admin'=>true,'role_id'=>1]);
    ok(!empty($res['success']), 'endpoint responds successfully');
    $roleNames = array_column($res['roles'] ?? [], 'role_name');
    ok(in_array('Admin', $roleNames, true), "'Admin' (has meetings access + a user) is included");
    ok(!in_array('Staff', $roleNames, true), "'Staff' (no meetings access) is excluded even though it has a user");
    ok(in_array('__ZAR Full Access Role', $roleNames, true), "a role with roles.is_admin=1 is included even with NO role_permissions row at all");

    $adminRole = null;
    foreach (($res['roles'] ?? []) as $r) if ($r['role_name'] === 'Admin') $adminRole = $r;
    ok($adminRole !== null, 'Admin role entry found');
    $uids = array_column($adminRole['users'] ?? [], 'user_id');
    ok(in_array($userA, $uids, true), 'fixture user (no employee record at all) still appears, keyed by user_id');
    $namedUser = null;
    foreach (($adminRole['users'] ?? []) as $u) if ((int)$u['user_id'] === $userA) $namedUser = $u;
    ok($namedUser !== null && $namedUser['name'] === '__ZAR Eligible', 'user name resolved from the users table directly (no employee join needed)');

    section('2. Every returned role has at least one user (no dead-end roles)');
    $allNonEmpty = true;
    foreach (($res['roles'] ?? []) as $r) if (empty($r['users'])) $allNonEmpty = false;
    ok($allNonEmpty, 'no role in the response has an empty users array');

    section('3. Permission gating');
    $NOPERM = ['user_id'=>999970,'username'=>'noperm','is_admin'=>false,'role_id'=>999,
        'permissions'=>[], 'scope'=>['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $res2 = callEndpoint($NOPERM);
    ok(empty($res2['success']), 'a user without create/edit meetings permission is denied');

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
} finally {
    foreach (array_reverse($fixtures) as [$table, $id]) {
        $col = $table === 'users' ? 'user_id' : ($table === 'roles' ? 'role_id' : 'employee_id');
        $pdo->exec("DELETE FROM $table WHERE $col = $id");
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
