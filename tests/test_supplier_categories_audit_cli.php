<?php
/**
 * tests/test_supplier_categories_audit_cli.php
 *   php tests/test_supplier_categories_audit_cli.php
 *
 * 1. Supplier Categories APIs (add / update / status / soft delete):
 *    duplicate names refused, a category in use cannot be deleted, delete is a
 *    soft delete (row kept, status 'deleted'), and re-adding a deleted name
 *    brings that row back instead of failing on the unique key.
 * 2. Supplier audit trail: every supplier write logs audit_logs with
 *    entity_type 'supplier' + the supplier id — what the System Info tab on
 *    Supplier Details reads.
 *
 * Every API call runs in its own PHP process, as a logged-in admin with a
 * valid CSRF token. Test rows are removed at the end.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $ok, string $label, $detail = null): void {
    $ok ? pass($label) : fail($label . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail)) : ''));
}

$cleanup = ['categories' => [], 'suppliers' => []];
register_shutdown_function(function () use (&$cleanup) {
    global $pdo, $pass, $fail; static $done = false; if ($done) return; $done = true;
    if ($pdo instanceof PDO) {
        foreach ($cleanup['suppliers'] as $sid) {
            $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'supplier' AND entity_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([$sid]);
        }
        foreach ($cleanup['categories'] as $cid) {
            $pdo->prepare("DELETE FROM supplier_categories WHERE category_id = ?")->execute([$cid]);
        }
    }
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

echo "\n\033[1m═══ Supplier Categories + Supplier audit trail ═══\033[0m\n";
if (!($pdo instanceof PDO)) { echo "  (no database — skipped)\n"; exit; }
$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
if ($adminId <= 0) { echo "  (no admin user — skipped)\n"; exit; }

/** POST to one endpoint in its own process and return the decoded JSON body. */
function post(string $rel, array $data): ?array
{
    global $root, $adminId;
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = (int)$argv[3]; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
$_SESSION['csrf_token'] = 'cli-test-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'cli-test-token';
$_SERVER['SCRIPT_NAME'] = '/bms/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = [];
parse_str($argv[2] ?? '', $_POST);
$_POST['_csrf'] = 'cli-test-token';
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_supcat_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$root/$rel") . ' '
         . escapeshellarg(http_build_query($data)) . ' ' . (int)$adminId . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = (string)shell_exec($cmd);
    @unlink($tmp);
    $j = json_decode(substr($out, (int)strpos($out, '{')), true);
    return is_array($j) ? $j : null;
}

// ── 1. Supplier Categories ─────────────────────────────────────────────────
section('Supplier Categories APIs');
$tag  = 'CLI SupCat ' . getmypid();
$r = post('api/add_supplier_category.php', ['category_name' => $tag, 'description' => 'probe']);
$cid = (int)($r['id'] ?? 0);
check(!empty($r['success']) && $cid > 0, 'add creates the category', $r);
if ($cid) $cleanup['categories'][] = $cid;

$r = post('api/add_supplier_category.php', ['category_name' => strtoupper($tag)]);
check(empty($r['success']), 'add refuses a duplicate name (case-insensitive)', $r);

$r = post('api/add_supplier_category.php', ['category_name' => '']);
check(empty($r['success']), 'add refuses an empty name', $r);

$r = post('api/update_supplier_category.php', ['category_id' => $cid, 'category_name' => "$tag renamed", 'status' => 'active']);
$name = $pdo->query("SELECT category_name FROM supplier_categories WHERE category_id = $cid")->fetchColumn();
check(!empty($r['success']) && $name === "$tag renamed", 'update renames it', $r);

$r = post('api/update_supplier_category_status.php', ['category_id' => $cid, 'status' => 'inactive']);
$st = $pdo->query("SELECT status FROM supplier_categories WHERE category_id = $cid")->fetchColumn();
check(!empty($r['success']) && $st === 'inactive', 'status → inactive', $r);

$r = post('api/update_supplier_category_status.php', ['category_id' => $cid, 'status' => 'deleted']);
check(empty($r['success']), 'status endpoint refuses anything but active/inactive', $r);

// In use by a supplier → delete refused.
$pdo->prepare("INSERT INTO suppliers (supplier_name, supplier_code, status, category_id, created_by, created_at)
               VALUES (?, ?, 'active', ?, ?, NOW())")->execute(["$tag supplier", 'CLI-' . getmypid(), $cid, $adminId]);
$usingSid = (int)$pdo->lastInsertId();
$cleanup['suppliers'][] = $usingSid;
$r = post('api/delete_supplier_category.php', ['category_id' => $cid]);
check(empty($r['success']) && stripos((string)($r['message'] ?? ''), 'used by') !== false, 'delete refused while a supplier uses it', $r);

$pdo->prepare("UPDATE suppliers SET category_id = NULL WHERE supplier_id = ?")->execute([$usingSid]);
$r = post('api/delete_supplier_category.php', ['category_id' => $cid]);
$st = $pdo->query("SELECT status FROM supplier_categories WHERE category_id = $cid")->fetchColumn();
check(!empty($r['success']) && $st === 'deleted', 'unused category is soft-deleted (row kept, status deleted)', ['resp' => $r, 'status' => $st]);

$r = post('api/delete_supplier_category.php', ['category_id' => $cid]);
check(empty($r['success']), 'deleting it again → not found', $r);

$r = post('api/add_supplier_category.php', ['category_name' => "$tag renamed"]);
check(!empty($r['success']) && (int)($r['id'] ?? 0) === $cid, 're-adding a deleted name restores that same row', $r);

// ── 2. Supplier audit trail ────────────────────────────────────────────────
section('Supplier writes log the supplier id (System Info → Audit Trail)');
$audit = function (int $sid): array {
    global $pdo;
    $st = $pdo->prepare("SELECT action FROM audit_logs WHERE entity_type = 'supplier' AND entity_id = ? ORDER BY id");
    $st->execute([$sid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$sname = "$tag audit supplier";
$r = post('api/add_supplier.php', ['supplier_name' => $sname, 'phone' => '0700000001', 'email' => 'cli' . getmypid() . '@example.com',
                                    'status' => 'active', 'country' => 'Tanzania', 'currency' => 'TZS']);
$sid = (int)($r['supplier_id'] ?? 0);
check(!empty($r['success']) && $sid > 0, 'add_supplier creates the supplier', $r);
if ($sid) $cleanup['suppliers'][] = $sid;
check(in_array('create_supplier', $audit($sid), true), 'create is in the audit trail with the supplier id');

$email = $pdo->query("SELECT email FROM suppliers WHERE supplier_id = $sid")->fetchColumn();
$r = post('api/update_supplier.php', ['supplier_id' => $sid, 'supplier_name' => $sname, 'phone' => '0700000999', 'email' => $email,
                                       'status' => 'active', 'country' => 'Tanzania', 'currency' => 'TZS']);
$row = $pdo->query("SELECT description, old_values, new_values FROM audit_logs WHERE entity_type = 'supplier' AND entity_id = $sid AND action = 'update_supplier' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(!empty($r['success']) && $row && strpos((string)$row['new_values'], '0700000999') !== false && strpos((string)$row['old_values'], '0700000001') !== false,
      'edit is audited with the changed field old → new', ['resp' => $r, 'row' => $row]);

$r = post('api/update_supplier_status.php', ['supplier_id' => $sid, 'status' => 'inactive']);
check(!empty($r['success']) && in_array('update_supplier_status', $audit($sid), true), 'status change is audited', $r);

$r = post('api/delete_supplier.php', ['supplier_id' => $sid]);
check(!empty($r['success']) && in_array('delete_supplier', $audit($sid), true), 'delete is audited', $r);

$r = post('api/mobile/suppliers/create.php', ['supplier_name' => "$tag mobile", 'phone' => '0722']);
$mid = (int)($r['supplier_id'] ?? 0);
if ($mid) $cleanup['suppliers'][] = $mid;
post('api/mobile/suppliers/update.php', ['supplier_id' => $mid, 'supplier_name' => "$tag mobile b"]);
post('api/mobile/suppliers/delete.php', ['supplier_id' => $mid]);
check($mid > 0 && $audit($mid) === ['create_supplier', 'update_supplier', 'delete_supplier'], 'mobile create / update / delete are audited', $audit($mid));

// Every web + mobile + import write site carries the supplier id.
foreach (['api/add_supplier.php', 'api/update_supplier.php', 'api/update_supplier_status.php', 'api/delete_supplier.php',
          'api/import_suppliers.php', 'api/mobile/suppliers/create.php', 'api/mobile/suppliers/update.php', 'api/mobile/suppliers/delete.php'] as $rel) {
    $src = (string)file_get_contents("$root/$rel");
    check(strpos($src, "'entity_type' => 'supplier', 'entity_id' =>") !== false, "$rel — logAudit carries entity_type 'supplier' + id");
}
