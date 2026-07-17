<?php
/**
 * BMS — Warehouse-Scope (Phase 6, pos_upgrade_plan.md) Regression Guard
 *
 *   php tests/test_warehouse_scope_cli.php
 *
 *   A. STATIC — core helper + migration + assignment-UI files exist.
 *   B. STATIC — every POS / report / stock-detail enforcement point still
 *      calls userCan('warehouse', ...) / scopeFilterSqlNullable('warehouse', ...)
 *      (regression guard: a future edit cannot silently remove the check).
 *   C. LIVE — real DB: user_scope_overrides + loadUserScope()/userCan()/
 *      scopeFilterSqlNullable() compose correctly for a specific-warehouse
 *      grant, a grant-all sentinel, and a no-grant user.
 *
 * Read-only except for two throwaway rows written to and deleted from
 * user_scope_overrides under fake, out-of-range user_ids (no FK constraint
 * on that table, so this never touches a real user). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/project_scope.php";
require_once "$root/core/warehouse_scope.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p) { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

// Fake, out-of-range user_ids — user_scope_overrides carries no FK, so these
// never touch a real users row.
const TEST_UID_GRANTED  = 900000001;
const TEST_UID_GRANTALL = 900000002;
const TEST_UID_NONE     = 900000003;

try {
    // ── A. Required files ─────────────────────────────────────────────────
    section('A. Required files');

    $required_files = [
        'core/warehouse_scope.php'                                    => 'Warehouse scope helpers',
        'migrations/2026_07_17_user_scope_overrides_unique_key.php'   => 'Unique-key migration (6a)',
        'app/constant/settings/user_projects.php'                     => 'Assignment UI (project + warehouse)',
    ];
    foreach ($required_files as $rel => $label) {
        ok(file_exists("$root/$rel"), "$label — $rel");
    }

    // ── B. Core helper signatures ─────────────────────────────────────────
    section('B. Core helper signatures');

    $wh_src = src("$root/core/warehouse_scope.php");
    ok(strpos($wh_src, 'function warehousesForSelect(')  !== false, 'warehousesForSelect() present');
    ok(strpos($wh_src, 'function hasAllWarehouseAccess(') !== false, 'hasAllWarehouseAccess() present');
    ok(strpos($wh_src, 'function renderWarehouseOptions(') !== false, 'renderWarehouseOptions() present');

    $scope_src = src("$root/core/project_scope.php");
    ok(strpos($scope_src, "'warehouse' => 'warehouses'") !== false, "_scope_list_key() maps 'warehouse' → 'warehouses'");
    ok(strpos($scope_src, "'warehouse' => 'warehouse_id'") !== false, "_scope_column() maps 'warehouse' → 'warehouse_id'");

    // ── C. Enforcement-point regression guard (static grep) ────────────────
    section('C. Enforcement points still wired (regression guard)');

    $must_contain = [
        'api/pos/process_sale.php'              => ["userCan('warehouse'"],
        'api/pos/create_return.php'             => ["userCan('warehouse'"],
        'api/pos/void_sale.php'                 => ["userCan('warehouse'"],
        'api/pos/receive_payment.php'           => ["userCan('warehouse'"],
        'api/pos/print_receipt.php'             => ["userCan('warehouse'", 'isAuthenticated()'],
        'api/pos/get_sale_items.php'            => ["userCan('warehouse'"],
        'api/pos/simple_products.php'           => ["userCan('warehouse'", 'hasAllWarehouseAccess()'],
        'api/pos/get_dashboard.php'             => ["scopeFilterSqlNullable('warehouse'"],
        'api/pos/get_sales.php'                 => ["scopeFilterSqlNullable('warehouse'"],
        'app/bms/pos/pos.php'                   => ["userCan('warehouse'", 'warehousesForSelect(', 'renderWarehouseOptions('],
        'api/account/get_sales_report.php'      => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/account/get_purchase_report.php'   => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/account/get_inventory_report.php'  => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/account/get_stock_movements.php'   => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/account/get_stock_transfers.php'   => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/account/get_stock_adjustments.php' => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/get_warehouse_stock_detail.php'    => ["userCan('warehouse'"],
        'api/get_product_warehouses.php'        => ["scopeFilterSqlNullable('warehouse'"],
        'app/bms/operations/warehouse_stock_view.php' => ["userCan('warehouse'"],
        'app/bms/stock/warehouse_view.php'      => ["userCan('warehouse'"],
        'app/dashboard.php'                     => ["scopeFilterSqlNullable('warehouse'"],
        'app/constant/reports/sales_report.php'      => ["scopeFilterSql('warehouse'"],
        'app/constant/reports/purchase_report.php'   => ["scopeFilterSql('warehouse'"],
        'app/constant/reports/inventory_report.php'  => ["scopeFilterSql('warehouse'"],
    ];
    foreach ($must_contain as $rel => $needles) {
        $s = src("$root/$rel");
        if ($s === '') { ok(false, "MISSING FILE $rel"); continue; }
        foreach ($needles as $needle) {
            ok(strpos($s, $needle) !== false, "$rel contains \"$needle\"");
        }
    }

    // ── D. Lint every touched file ──────────────────────────────────────────
    section('D. Lint (php -l) every enforcement file');
    foreach (array_keys($must_contain) as $rel) {
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $o, $rc);
        ok($rc === 0, "$rel lint-clean");
    }

    // ── E. LIVE — real DB composition ───────────────────────────────────────
    section('E. Live — user_scope_overrides + loadUserScope()/userCan()/scopeFilterSqlNullable()');

    $wh = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC LIMIT 2")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (count($wh) < 2) {
        ok(false, 'Need at least 2 active warehouses in this DB to run the live scope test — skipping E');
    } else {
        [$whA, $whB] = $wh;

        // Ensure no session/scope bleed-over between sub-cases.
        $reset = function () { unset($_SESSION['scope'], $_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['user_id']); };

        // Cleanup any leftovers from a previous aborted run, then insert fixtures.
        $del = $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id IN (?,?,?)");
        $del->execute([TEST_UID_GRANTED, TEST_UID_GRANTALL, TEST_UID_NONE]);

        $ins = $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)");
        $ins->execute([TEST_UID_GRANTED, $whA]);          // granted only warehouse A
        $ins->execute([TEST_UID_GRANTALL, null]);         // grant-all sentinel
        // TEST_UID_NONE gets no row at all.

        // ── Case 1: specific grant ──
        $reset(); $_SESSION['user_id'] = TEST_UID_GRANTED;
        loadUserScope(TEST_UID_GRANTED);
        ok(userCan('warehouse', $whA) === true,  "userCan('warehouse', A) TRUE for a user granted exactly warehouse A");
        ok(userCan('warehouse', $whB) === false, "userCan('warehouse', B) FALSE for the same user (not granted)");
        ok(hasAllWarehouseAccess() === false,    'hasAllWarehouseAccess() FALSE for a single-warehouse grant');
        $clause = scopeFilterSqlNullable('warehouse', 'w');
        ok(strpos($clause, (string)$whA) !== false, "scopeFilterSqlNullable('warehouse','w') references warehouse A's id");

        // ── Case 2: grant-all sentinel ──
        $reset(); $_SESSION['user_id'] = TEST_UID_GRANTALL;
        loadUserScope(TEST_UID_GRANTALL);
        ok(userCan('warehouse', $whA) === true, "userCan('warehouse', A) TRUE for a grant-all user");
        ok(userCan('warehouse', $whB) === true, "userCan('warehouse', B) TRUE for a grant-all user");
        ok(hasAllWarehouseAccess() === true,    'hasAllWarehouseAccess() TRUE for a grant-all user');
        ok(scopeFilterSqlNullable('warehouse', 'w') === '', "scopeFilterSqlNullable() returns '' (unrestricted) for a grant-all user");

        // ── Case 3: no grant at all ──
        $reset(); $_SESSION['user_id'] = TEST_UID_NONE;
        loadUserScope(TEST_UID_NONE);
        ok(userCan('warehouse', $whA) === false, "userCan('warehouse', A) FALSE for a user with no warehouse grant");
        ok(userCan('warehouse', $whB) === false, "userCan('warehouse', B) FALSE for the same user");
        ok(hasAllWarehouseAccess() === false,    'hasAllWarehouseAccess() FALSE for a user with no warehouse grant');

        // ── Case 4: admin bypasses everything ──
        $reset(); $_SESSION['user_id'] = TEST_UID_NONE; $_SESSION['is_admin'] = true;
        loadUserScope(TEST_UID_NONE);
        ok(userCan('warehouse', $whA) === true,          'Admin: userCan(warehouse) TRUE regardless of grants');
        ok(hasAllWarehouseAccess() === true,             'Admin: hasAllWarehouseAccess() TRUE');
        ok(scopeFilterSqlNullable('warehouse', 'w') === '', "Admin: scopeFilterSqlNullable() returns '' (unrestricted)");

        // Cleanup fixtures + session.
        $del->execute([TEST_UID_GRANTED, TEST_UID_GRANTALL, TEST_UID_NONE]);
        $reset();

        $left = $pdo->prepare("SELECT COUNT(*) FROM user_scope_overrides WHERE user_id IN (?,?,?)");
        $left->execute([TEST_UID_GRANTED, TEST_UID_GRANTALL, TEST_UID_NONE]);
        ok((int)$left->fetchColumn() === 0, 'Test fixtures fully cleaned up from user_scope_overrides');
    }

} catch (Throwable $e) {
    echo "\n\033[31mFATAL: {$e->getMessage()}\033[0m\n";
    // Best-effort cleanup even on failure.
    try {
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id IN (?,?,?)")
            ->execute([TEST_UID_GRANTED, TEST_UID_GRANTALL, TEST_UID_NONE]);
    } catch (Throwable $e2) {}
    $fail++;
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[32m✅ All $total checks passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail / $total check(s) failed.\033[0m\n\n";
    exit(1);
}
