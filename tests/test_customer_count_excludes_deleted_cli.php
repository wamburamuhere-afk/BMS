<?php
/**
 * Customer counts exclude soft-deleted customers — CLI regression
 *   php tests/test_customer_count_excludes_deleted_cli.php
 *
 * Bug (2026-10-27): customers.php showed no customers, but the dashboard
 * "Customer Overview" card still said 1. Deleting a customer is a soft delete
 * (status = 'deleted'); customers.php filters those rows out, but the
 * dashboard stats query counted every row. Same gap on the Customer Groups
 * pages (total customers + group member counts) and the superadmin tenant
 * snapshot.
 *
 *   A. STATIC — every touched file lints and carries the deleted filter.
 *   B. LIVE   — renders the real dashboard + customer groups pages as an
 *               admin: create a customer (in a temp group) → counts go up by
 *               one; soft-delete it exactly as api/delete_customer.php does →
 *               counts drop back to the baseline. Fixture rows are removed.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 90) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('A. Static');
$files = ['app/dashboard.php', 'app/bms/customer/customer_groups.php', 'app/bms/customer/customer_group_details.php',
          'app/bms/customer/customer_group_members.php', 'core/tenant_admin.php'];
foreach ($files as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass("lint $f") : fail("php -l $f: " . implode(' ', $out));
}
$dash = src($root, 'app/dashboard.php');
preg_match("~FROM customers c\s+WHERE c\.status != 'deleted'\s+\{\\\$custScope\}~", $dash)
    ? pass('dashboard customer stats exclude deleted') : fail('dashboard customer stats query lacks the deleted filter');
$memberJoin = "LEFT JOIN customers gc ON gc.customer_id = cgc.customer_id AND gc.status != 'deleted'";
foreach (['customer_groups.php', 'customer_group_details.php', 'customer_group_members.php'] as $f) {
    $s = src($root, "app/bms/customer/$f");
    has($s, 'COUNT(gc.customer_id) as customer_count', "$f member count uses non-deleted customers");
    has($s, $memberJoin, "$f joins customers with deleted filter");
}
foreach (['customer_groups.php', 'customer_group_details.php'] as $f) {
    has(src($root, "app/bms/customer/$f"), "SELECT COUNT(*) as total FROM customers WHERE status != 'deleted'", "$f total excludes deleted");
}
has(src($root, 'core/tenant_admin.php'), "SELECT COUNT(*) FROM customers WHERE status != 'deleted'", 'tenant snapshot customer count excludes deleted');

// ─────────────────────────────────────────────────────────────────────────
section('B. Live render (create → soft-delete)');
global $pdo;
$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
if (!$adminUid) { fail('no admin user to render pages as'); exit; }

// Each render runs in its own PHP process so a page can be included more than once.
$probe = tempnam(sys_get_temp_dir(), 'custcnt') . '.php';
file_put_contents($probe, '<?php
$_SERVER["HTTP_HOST"] = "dev.bms.local"; $_SERVER["REQUEST_URI"] = "/" . $argv[1]; $_SERVER["REQUEST_METHOD"] = "GET";
require_once ' . var_export("$root/roots.php", true) . ';
$_SESSION["user_id"] = ' . $adminUid . '; $_SESSION["role_id"] = 1; $_SESSION["is_admin"] = true; loadUserPermissions(1);
ob_start(); include ' . var_export("$root/", true) . ' . $argv[2]; echo ob_get_clean();
');
$render = function (string $route, string $file) use ($probe): string {
    return (string)shell_exec('php ' . escapeshellarg($probe) . ' ' . escapeshellarg($route) . ' ' . escapeshellarg($file) . ' 2>&1');
};
// Dashboard "Customer Overview" card: first <h3 class="fw-bold">N</h3> after the card's people icon.
$dashTotal = function () use ($render): ?int {
    $h = $render('dashboard', 'app/dashboard.php');
    $p = strpos($h, 'bi bi-people text-success me-2');
    return ($p !== false && preg_match('~<h3 class="fw-bold">(\d+)</h3>~', $h, $m, 0, $p)) ? (int)$m[1] : null;
};
// Customer Groups: "Total Customers" stat + the fixture group's member badge.
$groupsStats = function (string $groupName) use ($render): array {
    $h = $render('customers/groups', 'app/bms/customer/customer_groups.php');
    $total = preg_match('~<h4 class="mb-0">(\d+)</h4>\s*<p class="mb-0">Total Customers</p>~', $h, $m) ? (int)$m[1] : null;
    $members = null;
    if (($p = stripos($h, $groupName)) !== false && preg_match('~badge[^>]*>\s*(\d+)~', $h, $mm, 0, $p)) $members = (int)$mm[1];
    return [$total, $members];
};

$tag = 'ZZTEST-CNT-' . bin2hex(random_bytes(3));
$custId = 0; $groupId = 0;
try {
    $pdo->prepare("INSERT INTO customer_groups (group_name, created_at) VALUES (?, NOW())")->execute([$tag]);
    $groupId = (int)$pdo->lastInsertId();

    $dash0 = $dashTotal();
    [$grp0, $mem0] = $groupsStats($tag);
    $dash0 !== null ? pass("baseline dashboard total = $dash0") : fail('could not read dashboard customer total');
    $grp0 !== null ? pass("baseline groups-page total = $grp0") : fail('could not read groups-page total');
    $mem0 === 0 ? pass('fixture group starts with 0 members') : fail('fixture group member count = ' . var_export($mem0, true));

    $pdo->prepare("INSERT INTO customers (customer_name, status, created_at) VALUES (?, 'active', NOW())")->execute([$tag]);
    $custId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customer_group_customers (group_id, customer_id) VALUES (?, ?)")->execute([$groupId, $custId]);

    $dash1 = $dashTotal();
    [$grp1, $mem1] = $groupsStats($tag);
    $dash1 === $dash0 + 1 ? pass('dashboard counts the new customer') : fail("dashboard after create = $dash1, expected " . ($dash0 + 1));
    $grp1 === $grp0 + 1 ? pass('groups page counts the new customer') : fail("groups total after create = $grp1, expected " . ($grp0 + 1));
    $mem1 === 1 ? pass('group shows 1 member') : fail('group members after create = ' . var_export($mem1, true));

    // Same soft delete as api/delete_customer.php
    $pdo->prepare("UPDATE customers SET status = 'deleted' WHERE customer_id = ?")->execute([$custId]);

    $dash2 = $dashTotal();
    [$grp2, $mem2] = $groupsStats($tag);
    $dash2 === $dash0 ? pass('dashboard no longer counts the deleted customer') : fail("dashboard after delete = $dash2, expected $dash0");
    $grp2 === $grp0 ? pass('groups page no longer counts the deleted customer') : fail("groups total after delete = $grp2, expected $grp0");
    $mem2 === 0 ? pass('group member count drops the deleted customer') : fail('group members after delete = ' . var_export($mem2, true));
} finally {
    if ($groupId) $pdo->prepare("DELETE FROM customer_group_customers WHERE group_id = ?")->execute([$groupId]);
    if ($groupId) $pdo->prepare("DELETE FROM customer_groups WHERE group_id = ?")->execute([$groupId]);
    if ($custId)  $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$custId]);
    @unlink($probe);
}
