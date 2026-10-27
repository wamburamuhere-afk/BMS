<?php
/**
 * Voided POS sales in stock summaries — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_stock_activity_voided_sales_cli.php
 *
 * Bug (live, 2026-10-27): after selling and then voiding every sale in a shop,
 * stock went back to normal and the sales disappeared, but Warehouses (30-day
 * "out" figure) and the shop's Recent Activity still showed the units as gone
 * out — they counted the sale's stock-out (and the void's stock-back as stock
 * received). core/stock_ledger.php::stockMovementNotVoidedSaleSql() now leaves
 * both rows out of summaries and recent-activity feeds.
 *
 * Verifies (rolled-back transaction, real local DB):
 *   1. Wiring: Warehouses (4 sums), shop Recent Activity, product page feed use
 *      the filter; void_sale tags combo reversals 'pos_void'.
 *   2. The filter hides a voided sale's 'pos_sale' out, its 'pos_void' back, an
 *      older combo 'return' reversal and an older '' void row.
 *   3. It keeps a completed sale, a genuine return and a stock adjustment.
 *   4. Warehouses and the shop page render with no PHP/SQL errors.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/stock_ledger.php";

$failures = 0; $passes = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

global $pdo;

// ─────────────────────────────────────────────────────────────────────────
section('1. Wiring');
// ─────────────────────────────────────────────────────────────────────────
$wh = file_get_contents("$root/app/bms/stock/warehouses.php");
ok(substr_count($wh, 'INTERVAL 30 DAY){$void_skip}) as') === 4 && strpos($wh, "\$void_skip = stockMovementNotVoidedSaleSql(\$pdo, 'sm');") !== false, 'Warehouses: all four 30-day sums skip voided sales');
ok(strpos(file_get_contents("$root/app/bms/stock/warehouse_view.php"), "WHERE sm.warehouse_id = ?\" . stockMovementNotVoidedSaleSql(\$pdo, 'sm')") !== false, 'shop page: Recent Activity skips voided sales');
ok(strpos(file_get_contents("$root/app/bms/product/product_view.php"), "WHERE sm.product_id = ?\" . stockMovementNotVoidedSaleSql(\$pdo, 'sm')") !== false, 'product page: recent movements skip voided sales');
ok(strpos(file_get_contents("$root/api/pos/void_sale.php"), "\$_SESSION['user_id'], 'pos_void');") !== false, 'void_sale tags combo reversals as pos_void');
ok(strpos(file_get_contents("$root/app/bms/stock/stock_movements.php"), 'stockMovementNotVoidedSaleSql') === false, 'the full stock movement ledger still keeps every row (audit trail)');

// ─────────────────────────────────────────────────────────────────────────
section('2–3. The filter (rolled back)');
// ─────────────────────────────────────────────────────────────────────────
$prod = (int)$pdo->query("SELECT product_id FROM products ORDER BY product_id LIMIT 1")->fetchColumn();
$whId = (int)$pdo->query("SELECT warehouse_id FROM warehouses ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$pdo->beginTransaction();
try {
    $mkSale = $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, payment_method, grand_total, sale_status, is_return_sale, sale_date)
                             VALUES (?, 0, 4, 'cash', 1000, ?, 0, NOW())");
    $mkSale->execute(['RCP-TESTVOID-1', 'voided']);    $voided = (int)$pdo->lastInsertId();
    $mkSale->execute(['RCP-TESTVOID-2', 'completed']); $kept   = (int)$pdo->lastInsertId();

    $mv = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_id, reference_type, reference_number, movement_date, warehouse_id, notes, created_by, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 4, NOW())");
    $tag = 'TESTVOIDFLT';
    $rows = [
        // hidden
        ['sale_out',  2, $voided, 'pos_sale', 'RCP-TESTVOID-1', "$tag POS Sale"],
        ['return_in', 2, $voided, 'pos_void', 'RCP-TESTVOID-1', "$tag Void of POS Sale #RCP-TESTVOID-1"],
        ['return_in', 1, $voided, 'return',   'RCP-TESTVOID-1', "Combo component reversal for RCP-TESTVOID-1"],
        ['return_in', 1, $voided, '',         'RCP-TESTVOID-1', "Void of POS Sale #RCP-TESTVOID-1 — test"],
        // kept
        ['sale_out',  3, $kept,   'pos_sale', 'RCP-TESTVOID-2', "$tag POS Sale kept"],
        ['return_in', 1, $voided, 'return',   'SR-TEST-1',      "$tag genuine sales return"],
        ['adjustment_in', 5, null, 'stock_adjustment', 'ADJ-TEST', "$tag adjustment"],
    ];
    foreach ($rows as $r) { $mv->execute([$prod, $r[0], $r[1], $r[2], $r[3] === '' ? null : $r[3], $r[4], $whId, $r[5]]); }

    $all = $pdo->prepare("SELECT COUNT(*) FROM stock_movements sm WHERE sm.product_id = ? AND sm.created_at >= NOW() - INTERVAL 1 MINUTE
                          AND (sm.notes LIKE '$tag%' OR sm.notes LIKE 'Combo component reversal for RCP-TESTVOID-1' OR sm.notes LIKE 'Void of POS Sale #RCP-TESTVOID-1%')");
    $all->execute([$prod]);
    ok((int)$all->fetchColumn() === 7, 'seven test movements written');

    $sql = "SELECT sm.notes, sm.movement_type, sm.quantity FROM stock_movements sm WHERE sm.product_id = ? AND sm.created_at >= NOW() - INTERVAL 1 MINUTE
              AND (sm.notes LIKE '$tag%' OR sm.notes LIKE 'Combo component reversal for RCP-TESTVOID-1' OR sm.notes LIKE 'Void of POS Sale #RCP-TESTVOID-1%')"
         . stockMovementNotVoidedSaleSql($pdo, 'sm');
    $st = $pdo->prepare($sql); $st->execute([$prod]);
    $left = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'notes');
    ok(!in_array("$tag POS Sale", $left, true), "the voided sale's stock-out is hidden");
    ok(!in_array("$tag Void of POS Sale #RCP-TESTVOID-1", $left, true), "the void's stock-back is hidden");
    ok(!in_array('Combo component reversal for RCP-TESTVOID-1', $left, true), 'an older combo void reversal is hidden');
    ok(!in_array('Void of POS Sale #RCP-TESTVOID-1 — test', $left, true), "an older void stored without a type is hidden");
    ok(in_array("$tag POS Sale kept", $left, true), 'a completed sale is kept');
    ok(in_array("$tag genuine sales return", $left, true), 'a genuine return is kept');
    ok(in_array("$tag adjustment", $left, true), 'a stock adjustment is kept');
    ok(count($left) === 3, 'exactly three rows remain');

    // The 30-day "out" figure the Warehouses page shows, before and after the filter.
    $out = "SELECT COALESCE(SUM(CASE WHEN sm.movement_type = 'sale_out' THEN sm.quantity ELSE 0 END), 0) FROM stock_movements sm
             WHERE sm.product_id = ? AND sm.notes LIKE '$tag%' AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $a = $pdo->prepare($out); $a->execute([$prod]);
    $b = $pdo->prepare($out . stockMovementNotVoidedSaleSql($pdo, 'sm')); $b->execute([$prod]);
    ok((float)$a->fetchColumn() == 5.0 && (float)$b->fetchColumn() == 3.0, 'units out: 5 before (voided 2 counted), 3 after (only the real sale)');
} finally {
    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Pages render');
// ─────────────────────────────────────────────────────────────────────────
function renderPage(string $page, string $query = ''): string {
    global $root;
    $h = tempnam(sys_get_temp_dir(), 'pg') . '.php';
    file_put_contents($h, '<?php
$_SERVER += ["HTTP_HOST"=>"dev.bms.local","SERVER_NAME"=>"dev.bms.local","REQUEST_METHOD"=>"GET","REQUEST_URI"=>"/x","SCRIPT_NAME"=>"/index.php","REMOTE_ADDR"=>"127.0.0.1","HTTPS"=>"off","HTTP_USER_AGENT"=>"cli-render"];
parse_str(' . var_export($query, true) . ', $_GET);
chdir(' . var_export($root, true) . ');
require_once "roots.php";
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["role"]="admin"; $_SESSION["role_id"]=1; $_SESSION["is_admin"]=true;
include ' . var_export($page, true) . ';');
    $o = []; exec('php -d display_errors=1 -d error_reporting=' . E_ALL . ' ' . escapeshellarg($h) . ' 2>&1', $o);
    @unlink($h);
    return implode("\n", $o);
}
$w = renderPage('app/bms/stock/warehouses.php');
ok(strlen($w) > 5000 && !preg_match('/(Warning|Notice|Deprecated|Fatal error): |SQLSTATE\[/', $w), 'Warehouses renders with no errors');
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$v = renderPage('app/bms/stock/warehouse_view.php', 'id=' . $wid);
ok(strlen($v) > 5000 && !preg_match('/(Warning|Notice|Deprecated|Fatal error): |SQLSTATE\[/', $v), 'the shop page renders with no errors');
