<?php
/**
 * POS Shift ↔ Shop (Warehouse) Scope — CLI test
 *   php tests/test_pos_shift_warehouse_scope_cli.php
 *
 * Context: pos_registers/cash_register_shifts had NO warehouse dimension at
 * all — any cashier with POS create permission could open a shift on ANY
 * register, even a shop they were never granted via Settings > Admin >
 * Project & Warehouse Access (user_scope_overrides, resource_type='warehouse').
 * That silently bypassed the exact access-control the tenant already
 * configured on that page, and let one shift's cash reconciliation span
 * multiple shops — not professional for a real multi-shop business
 * (2026-09-16 conversation).
 *
 * Fix: pos_registers.warehouse_id + cash_register_shifts.warehouse_id
 * (both nullable — NULL = legacy/shared till, unaffected). A register tied
 * to a shop:
 *   - is hidden from get_registers.php's Open Shift list for a cashier not
 *     granted that shop (userCan('warehouse', ...)) — but stays visible on
 *     the unrestricted admin management list;
 *   - can only be opened by a cashier who IS granted that shop
 *     (api/pos/open_shift.php server-side backstop);
 *   - stamps its shop onto the shift at Open Shift time;
 *   - locks the POS terminal's Shop dropdown for the whole shift
 *     (pos.php / pos_scripts_new.php);
 *   - is re-enforced at sale time (api/pos/process_sale.php) so a cashier
 *     granted several shops can't sell against a different one than the
 *     till they actually signed into.
 *
 * Sections:
 *   1. Migration files lint-clean + idempotent (run twice, no error).
 *   2. Live DB: pos_registers.warehouse_id / cash_register_shifts.warehouse_id
 *      columns + indexes actually exist.
 *   3. Source wiring: get_registers.php, save_register.php, open_shift.php,
 *      process_sale.php, pos.php, pos_scripts_new.php.
 *   4. Live behaviour: get_registers.php's active_only list hides/shows a
 *      shop-assigned register correctly for a scoped fake user.
 *   5. Live behaviour: open_shift.php rejects a cashier not granted the
 *      register's shop, accepts one who is, and stamps the shift's
 *      warehouse_id from the register.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/warehouse_scope.php";
global $pdo;

if (session_status() === PHP_SESSION_NONE) session_start();

$passes = 0; $failures = 0;
function pass(string $m): void   { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void   { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

function _pss_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'possc_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Migration files lint-clean + idempotent');
// ─────────────────────────────────────────────────────────────────────────
$migrations = [
    'migrations/tenant/2026_09_16_pos_registers_warehouse_id.php',
    'migrations/2026_09_16_pos_registers_warehouse_id_legacy_db.php',
    'migrations/tenant/2026_09_16_cash_register_shifts_warehouse_id.php',
    'migrations/2026_09_16_cash_register_shifts_warehouse_id_legacy_db.php',
];
foreach ($migrations as $m) {
    $path = "$root/$m";
    if (!file_exists($path)) { fail("$m missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$m lint-clean") : fail("$m lint failed: " . implode(' ', $o));
}
// Only the two LEGACY-DB migrations are runnable directly in this single-DB
// dev environment (the tenant/ pair needs core/tenant_migration_bootstrap.php's
// multi-tenant connection routing, exercised by the live tenant deploy
// instead) — running them twice proves the idempotency guard actually works.
foreach ([
    'migrations/2026_09_16_pos_registers_warehouse_id_legacy_db.php',
    'migrations/2026_09_16_cash_register_shifts_warehouse_id_legacy_db.php',
] as $m) {
    $rc = 0; $o = [];
    exec("php " . escapeshellarg("$root/$m") . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$m re-run (idempotent) exits 0") : fail("$m re-run failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Live DB — columns + indexes exist');
// ─────────────────────────────────────────────────────────────────────────
$hasRegCol = (bool)$pdo->query("SHOW COLUMNS FROM pos_registers LIKE 'warehouse_id'")->fetch();
$hasRegCol ? pass('pos_registers.warehouse_id exists') : fail('pos_registers.warehouse_id missing');

$hasRegIdx = (bool)$pdo->query("SHOW INDEX FROM pos_registers WHERE Key_name = 'ix_pos_registers_warehouse'")->fetch();
$hasRegIdx ? pass('ix_pos_registers_warehouse index exists') : fail('ix_pos_registers_warehouse index missing');

$hasShiftCol = (bool)$pdo->query("SHOW COLUMNS FROM cash_register_shifts LIKE 'warehouse_id'")->fetch();
$hasShiftCol ? pass('cash_register_shifts.warehouse_id exists') : fail('cash_register_shifts.warehouse_id missing');

$hasShiftIdx = (bool)$pdo->query("SHOW INDEX FROM cash_register_shifts WHERE Key_name = 'ix_shifts_warehouse'")->fetch();
$hasShiftIdx ? pass('ix_shifts_warehouse index exists') : fail('ix_shifts_warehouse index missing');

if (!$hasRegCol || !$hasShiftCol) {
    echo "\n\033[31mMissing columns — skipping remaining sections (would only cascade-fail).\033[0m\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Source wiring');
// ─────────────────────────────────────────────────────────────────────────
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 90) . "`"); }

$getRegSrc   = src($root, 'api/pos/get_registers.php');
$saveRegSrc  = src($root, 'api/pos/save_register.php');
$openSrc     = src($root, 'api/pos/open_shift.php');
$saleSrc     = src($root, 'api/pos/process_sale.php');
$posSrc      = src($root, 'app/bms/pos/pos.php');
$posJsSrc    = src($root, 'app/bms/pos/pos_scripts_new.php');

has($getRegSrc,  "warehouseIdsForUser(",                       'get_registers.php scopes active_only list via warehouseIdsForUser()');
has($getRegSrc,  "r.warehouse_id IS NULL",                     'get_registers.php always allows still-unassigned (legacy) registers');
has($saveRegSrc, "userCan('warehouse', \$warehouse_id)",       'save_register.php validates the admin\'s own grant on the chosen warehouse');
has($openSrc,    "userCan('warehouse', \$register_warehouse_id)", 'open_shift.php rejects a cashier not granted the register\'s shop');
has($openSrc,    "shift_code, user_id, register_id, warehouse_id, start_time", 'open_shift.php stamps warehouse_id onto the new shift');
has($saleSrc,    "(int)\$shift['warehouse_id'] !== (int)\$warehouse_id",  'process_sale.php re-enforces the shift\'s shop lock at sale time');
has($posSrc,     'POS_SHIFT_WAREHOUSE_ID',                     'pos.php emits POS_SHIFT_WAREHOUSE_ID for the JS lock');
has($posJsSrc,   'POS_SHIFT_WAREHOUSE_ID',                     'pos_scripts_new.php reads POS_SHIFT_WAREHOUSE_ID to lock the Shop dropdown');

// ─────────────────────────────────────────────────────────────────────────
section('4. Live behaviour — get_registers.php active_only list respects shop scope');
// ─────────────────────────────────────────────────────────────────────────
// Fake, out-of-range user_ids — user_scope_overrides carries no FK, so these
// never need a real `users` row (same convention as test_warehouse_scope_cli.php).
const T_UID_SCOPED_A = 9990016;
const T_UID_SCOPED_B = 9990017;

$pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id IN (?, ?)")->execute([T_UID_SCOPED_A, T_UID_SCOPED_B]);
$pdo->prepare("DELETE FROM pos_registers WHERE register_code LIKE 'TESTSHOP-%'")->execute();
$pdo->prepare("DELETE FROM warehouses WHERE warehouse_code LIKE 'TESTSHOP-%'")->execute();

$pdo->prepare("INSERT INTO warehouses (warehouse_code, warehouse_name, status) VALUES ('TESTSHOP-A', 'Test Shop A', 'active')")->execute();
$whA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO warehouses (warehouse_code, warehouse_name, status) VALUES ('TESTSHOP-B', 'Test Shop B', 'active')")->execute();
$whB = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO pos_registers (register_name, register_code, warehouse_id, status) VALUES ('Test Till A', 'TESTSHOP-REG-A', ?, 'active')")->execute([$whA]);
$regA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO pos_registers (register_name, register_code, warehouse_id, status) VALUES ('Test Till Legacy', 'TESTSHOP-REG-LEGACY', NULL, 'active')")->execute();
$regLegacy = (int)$pdo->lastInsertId();

// User A: granted only Shop A.
$pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)")
    ->execute([T_UID_SCOPED_A, $whA]);
// User B: granted only Shop B (no access to Shop A's register at all).
$pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)")
    ->execute([T_UID_SCOPED_B, $whB]);

// Grants ONLY canView/canCreate('pos') directly via the session permissions
// map — bypassing role_permissions entirely — so these fixtures isolate the
// warehouse-SCOPE layer under test from the separate RBAC-verb layer (already
// covered by its own tests elsewhere). is_admin stays false throughout so the
// warehouse-scope checks are never bypassed by the admin fast path.
const T_PSS_SESSION_PERMS = "\$_SESSION['permissions'] = ['pos' => ['view' => true, 'create' => true, 'edit' => true]];";

function _pss_get_registers(string $root, int $uid, bool $activeOnly): array {
    $flag = $activeOnly ? '1' : '';
    $out = _pss_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_GET['active_only'] = " . var_export($flag, true) . ";
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 0; \$_SESSION['is_admin'] = false;
        " . T_PSS_SESSION_PERMS . "
        ob_start();
        include '$root/api/pos/get_registers.php';
        \$out = ob_get_clean();
        echo \$out;
    ");
    $res = json_decode($out, true);
    return is_array($res) && !empty($res['success']) ? $res['data'] : [];
}

$regsForUserB = _pss_get_registers($root, T_UID_SCOPED_B, true);
$codesForB = array_column($regsForUserB, 'register_code');
!in_array('TESTSHOP-REG-A', $codesForB, true)
    ? pass('User B (granted only Shop B) does NOT see Shop A\'s register in the Open Shift list')
    : fail('User B unexpectedly sees Shop A\'s register — shop scope not applied');
in_array('TESTSHOP-REG-LEGACY', $codesForB, true)
    ? pass('User B still sees the legacy (no-shop) register — unaffected by shop scope')
    : fail('User B lost visibility of the legacy register — should always be visible');

$regsForUserA = _pss_get_registers($root, T_UID_SCOPED_A, true);
$codesForA = array_column($regsForUserA, 'register_code');
in_array('TESTSHOP-REG-A', $codesForA, true)
    ? pass('User A (granted Shop A) DOES see Shop A\'s register in the Open Shift list')
    : fail('User A cannot see their own granted shop\'s register');

// ─────────────────────────────────────────────────────────────────────────
section('5. Live behaviour — open_shift.php enforces + stamps shop scope');
// ─────────────────────────────────────────────────────────────────────────
function _pss_open_shift(string $root, int $uid, int $registerId): array {
    $out = _pss_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 0; \$_SESSION['is_admin'] = false;
        \$_SESSION['csrf_token'] = 'test';
        " . T_PSS_SESSION_PERMS . "
        \$_POST = ['register_id' => '$registerId', 'opening_cash' => '0', '_csrf' => 'test'];
        ob_start();
        include '$root/api/pos/open_shift.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $res = json_decode($out, true);
    return is_array($res) ? $res : ['success' => false, 'message' => "invalid JSON: $out"];
}

// Clean up any stray active shifts from a previous failed run of this test.
$pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE user_id IN (?, ?) AND status = 'active'")
    ->execute([T_UID_SCOPED_A, T_UID_SCOPED_B]);

$deniedRes = _pss_open_shift($root, T_UID_SCOPED_B, $regA);
(empty($deniedRes['success']))
    ? pass('User B (no grant on Shop A) is DENIED opening a shift on Shop A\'s register')
    : fail('User B was allowed to open a shift on a shop they are not granted: ' . json_encode($deniedRes));

$allowedRes = _pss_open_shift($root, T_UID_SCOPED_A, $regA);
if (empty($allowedRes['success'])) {
    fail('User A (granted Shop A) was denied opening a shift on their own shop: ' . json_encode($allowedRes));
} else {
    pass('User A (granted Shop A) can open a shift on Shop A\'s register');
    $shiftId = (int)($allowedRes['shift_id'] ?? 0);
    $shiftRow = $pdo->prepare("SELECT warehouse_id, register_id FROM cash_register_shifts WHERE shift_id = ?");
    $shiftRow->execute([$shiftId]);
    $row = $shiftRow->fetch(PDO::FETCH_ASSOC);
    ((int)($row['warehouse_id'] ?? 0) === $whA)
        ? pass('The new shift\'s warehouse_id was stamped from the register\'s own shop')
        : fail('Shift warehouse_id mismatch: expected ' . $whA . ', got ' . var_export($row['warehouse_id'] ?? null, true));

    // Close it so the legacy-register open-shift check below isn't blocked by
    // "you already have an active shift".
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE shift_id = ?")->execute([$shiftId]);
}

$legacyRes = _pss_open_shift($root, T_UID_SCOPED_B, $regLegacy);
(!empty($legacyRes['success']))
    ? pass('Any cashier can still open a shift on a still-unassigned (legacy) register')
    : fail('Legacy (no-shop) register unexpectedly blocked: ' . json_encode($legacyRes));
if (!empty($legacyRes['shift_id'])) {
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed' WHERE shift_id = ?")->execute([(int)$legacyRes['shift_id']]);
}

// ─────────────────────────────────────────────────────────────────────────
section('Cleanup');
// ─────────────────────────────────────────────────────────────────────────
$pdo->prepare("DELETE FROM cash_register_shifts WHERE user_id IN (?, ?)")->execute([T_UID_SCOPED_A, T_UID_SCOPED_B]);
$pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id IN (?, ?)")->execute([T_UID_SCOPED_A, T_UID_SCOPED_B]);
$pdo->prepare("DELETE FROM pos_registers WHERE register_code LIKE 'TESTSHOP-%'")->execute();
$pdo->prepare("DELETE FROM warehouses WHERE warehouse_code LIKE 'TESTSHOP-%'")->execute();

$leftReg = (int)$pdo->query("SELECT COUNT(*) FROM pos_registers WHERE register_code LIKE 'TESTSHOP-%'")->fetchColumn();
$leftWh  = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE warehouse_code LIKE 'TESTSHOP-%'")->fetchColumn();
$leftOv  = (int)$pdo->query("SELECT COUNT(*) FROM user_scope_overrides WHERE user_id IN (" . T_UID_SCOPED_A . "," . T_UID_SCOPED_B . ")")->fetchColumn();
($leftReg === 0 && $leftWh === 0 && $leftOv === 0)
    ? pass('All test fixtures fully cleaned up')
    : fail("Fixtures left behind: registers=$leftReg warehouses=$leftWh overrides=$leftOv");
