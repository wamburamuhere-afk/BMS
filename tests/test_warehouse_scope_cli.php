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
        // Project→warehouse narrowing (procurement + sales create/list/view — see analysis 2026-07-17).
        'app/bms/purchase/rfq.php'                        => ['warehousesForSelect('],
        'api/get_rfqs.php'                                => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'app/bms/purchase/rfq_view.php'                   => ["userCan('warehouse'"],
        'api/account/get_purchase_orders.php'             => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'api/account/get_purchase_order_details.php'      => ["userCan('warehouse'"],
        'app/bms/grn/grn.php'                             => ['warehousesForSelect(', "scopeFilterSqlNullable('warehouse'"],
        'api/get_grns.php'                                => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'app/bms/grn/grn_view.php'                         => ["userCan('warehouse'"],
        'app/bms/purchase/purchase_returns.php'           => ['warehousesForSelect('],
        'api/get_purchase_returns.php'                    => ["scopeFilterSqlNullable('warehouse'"],
        'app/bms/sales/quotations/quotations.php'         => ["scopeFilterSqlNullable('warehouse'"],
        'app/bms/sales/quotations/quotation_view.php'     => ["userCan('warehouse'"],
        'app/bms/sales/sales_orders.php'                  => ["scopeFilterSqlNullable('warehouse'"],
        'api/account/get_sales_orders.php'                => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'app/bms/sales/sales_order_view.php'              => ["userCan('warehouse'"],
        'api/customer/get_lpos_list.php'                  => ["scopeFilterSqlNullable('warehouse'"],
        'api/customer/get_lpo.php'                        => ["userCan('warehouse'"],
        'api/account/get_invoices.php'                    => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'app/bms/invoice/invoice_view.php'                => ["userCan('warehouse'"],
        'app/bms/grn/delivery_notes.php'                  => ['warehousesForSelect(', "scopeFilterSqlNullable('warehouse'"],
        'api/get_delivery_notes_list.php'                 => ["userCan('warehouse'", "scopeFilterSqlNullable('warehouse'"],
        'app/bms/grn/dn_view.php'                          => ["userCan('warehouse'"],
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

    // ── F. LIVE — project assignment no longer auto-grants every warehouse
    //      that project has ever transacted through; an explicit warehouse
    //      assignment narrows it (2026-07-17 analysis + fix) ─────────────────
    section('F. Live — project→warehouse narrowing (warehousesForSelect + userCan)');

    $projRow = $pdo->query("
        SELECT project_id, COUNT(DISTINCT warehouse_id) AS n
          FROM (
            SELECT project_id, warehouse_id FROM purchase_orders   WHERE warehouse_id IS NOT NULL AND project_id IS NOT NULL
            UNION SELECT project_id, warehouse_id FROM purchase_receipts WHERE warehouse_id IS NOT NULL AND project_id IS NOT NULL
            UNION SELECT project_id, warehouse_id FROM deliveries      WHERE warehouse_id IS NOT NULL AND project_id IS NOT NULL
            UNION SELECT project_id, warehouse_id FROM stock_movements WHERE warehouse_id IS NOT NULL AND project_id IS NOT NULL
          ) t GROUP BY project_id HAVING n >= 2 ORDER BY n DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$projRow) {
        ok(false, 'Need a project tied to 2+ warehouses (via PO/GRN/DN/movement history) to run test F — skipping');
    } else {
        $projId = (int)$projRow['project_id'];
        $projWarehouses = $pdo->prepare("
            SELECT DISTINCT warehouse_id FROM (
                SELECT warehouse_id, project_id FROM purchase_orders   WHERE warehouse_id IS NOT NULL
                UNION SELECT warehouse_id, project_id FROM purchase_receipts WHERE warehouse_id IS NOT NULL
                UNION SELECT warehouse_id, project_id FROM deliveries      WHERE warehouse_id IS NOT NULL
                UNION SELECT warehouse_id, project_id FROM stock_movements WHERE warehouse_id IS NOT NULL
            ) t WHERE project_id = ?
        ");
        $projWarehouses->execute([$projId]);
        $allProjWh = array_map('intval', $projWarehouses->fetchAll(PDO::FETCH_COLUMN));
        $narrowTo  = $allProjWh[0];

        $reset = function () { unset($_SESSION['scope'], $_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['user_id']); };
        $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([TEST_UID_GRANTED]);
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([TEST_UID_GRANTED]);
        $pdo->prepare("INSERT INTO user_projects (user_id, project_id, assigned_by, assigned_at) VALUES (?, ?, 1, NOW())")
            ->execute([TEST_UID_GRANTED, $projId]);

        // Legacy fallback: assigned to the project, zero warehouse overrides
        // → sees every warehouse that project has transacted through (unchanged
        // pre-existing behaviour, so nobody's access silently narrows).
        $reset(); $_SESSION['user_id'] = TEST_UID_GRANTED;
        loadUserScope(TEST_UID_GRANTED);
        $legacyList = array_column(warehousesForSelect($pdo), 'warehouse_id');
        $legacyOk = true;
        foreach ($allProjWh as $w) { if (!in_array($w, $legacyList, true)) $legacyOk = false; }
        ok($legacyOk, "Legacy (no warehouse override): warehousesForSelect() includes all of project $projId's transacted warehouses (" . implode(',', $allProjWh) . ')');

        // Explicit narrowing: same project, but admin has curated exactly one
        // warehouse for this user → dropdown AND userCan() both narrow to it,
        // even though the project spans more.
        $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, 1)")
            ->execute([TEST_UID_GRANTED, $narrowTo]);
        $reset(); $_SESSION['user_id'] = TEST_UID_GRANTED;
        loadUserScope(TEST_UID_GRANTED);
        $narrowList = array_column(warehousesForSelect($pdo), 'warehouse_id');
        ok($narrowList === [$narrowTo], "Explicit grant: warehousesForSelect() narrows to exactly [$narrowTo], not all of project $projId's warehouses");
        ok(userCan('warehouse', $narrowTo) === true, "userCan('warehouse', $narrowTo) TRUE (granted)");
        $otherProjWh = array_values(array_diff($allProjWh, [$narrowTo]));
        if (!empty($otherProjWh)) {
            ok(userCan('warehouse', $otherProjWh[0]) === false, "userCan('warehouse', {$otherProjWh[0]}) FALSE — same project, but not the granted warehouse");
        }

        $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([TEST_UID_GRANTED]);
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([TEST_UID_GRANTED]);
        $reset();
    }

} catch (Throwable $e) {
    echo "\n\033[31mFATAL: {$e->getMessage()}\033[0m\n";
    // Best-effort cleanup even on failure.
    try {
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id IN (?,?,?)")
            ->execute([TEST_UID_GRANTED, TEST_UID_GRANTALL, TEST_UID_NONE]);
        $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([TEST_UID_GRANTED]);
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
