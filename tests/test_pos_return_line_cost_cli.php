<?php
/**
 * POS line cost fixed at sale time + returns taken off Cost — CLI test
 *   php tests/test_pos_return_line_cost_cli.php
 *
 * Reproduces the Piga Kazi Shop case (2026-11-14): a product whose cost_price holds the
 * CRATE price (11,400) while its batch holds the per-bottle cost (475), sold at 800.
 * Before the fix a return was taken off "sold" but not off "bought", and a fully returned
 * line lost its batch link and counted 0 cost — one week too much profit, the next a loss.
 *
 * Runs the same functions the endpoints call (consumeFefoBatches, posStampSaleLineCosts,
 * posLineUnitCostOrNull, reverseFefoBatchConsumption, posSimpleBuySellSeries, posSaleCogs,
 * posLineCostBackfill) inside ONE transaction that is always rolled back. Dates are in 2001
 * so real data never mixes in. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_batch_consumption.php";
require_once "$root/core/pos_line_cost.php";
require_once "$root/core/sales_posting.php";
require_once "$root/core/pos_dashboard_metrics.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)    { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t)   { echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b){ return abs((float)$a - (float)$b) < 0.01; }
register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    section('A. Wiring');
    $ps = file_get_contents("$root/api/pos/process_sale.php");
    $cr = file_get_contents("$root/api/pos/create_return.php");
    $dm = file_get_contents("$root/core/pos_dashboard_metrics.php");
    $stampAt = strpos($ps, 'posStampSaleLineCosts($pdo');
    $postAt  = strpos($ps, '$glPost = postPosSale(');
    ok($stampAt !== false && $postAt !== false && $stampAt < $postAt, 'process_sale fixes line costs after FEFO and before posting');
    ok(strpos($ps, "core/pos_line_cost.php") !== false, 'process_sale loads core/pos_line_cost.php');
    $costAt = strpos($cr, '$origUnitCost = posLineUnitCostOrNull(');
    $revAt  = strpos($cr, 'reverseFefoBatchConsumption($pdo, $iid');
    ok($costAt !== false && $revAt !== false && $costAt < $revAt, 'create_return reads the original cost BEFORE reversing the batch links');
    ok(strpos($cr, 'UPDATE pos_sale_items SET unit_cost = ?') !== false, 'create_return stores the cost on the return line');
    ok(strpos($dm, 'si.unit_cost IS NOT NULL THEN si.quantity * si.unit_cost') !== false, 'dashboard series reads the fixed line cost first');
    ok(is_file("$root/migrations/tenant/2026_11_14_pos_line_unit_cost.php") && is_file("$root/migrations/2026_11_14_pos_line_unit_cost_legacy_db.php"), 'tenant + legacy migrations present');
    $mig = file_get_contents("$root/migrations/tenant/2026_11_14_pos_line_unit_cost.php");
    ok(strpos($mig, 'roots.php') === false && strpos($mig, 'notify.php') === false, 'tenant migration does not load roots.php / notify.php');
    ok(posLineCostReady($pdo), 'pos_sale_items.unit_cost exists on this database (migration ran)');
    if (!posLineCostReady($pdo)) exit(1);

    section('B. Fixture (rolled back)');
    $pdo->beginTransaction();
    $uid   = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
    $shift = (int)($pdo->query("SELECT shift_id FROM cash_register_shifts ORDER BY shift_id LIMIT 1")->fetchColumn() ?: 0);
    $wid   = (int)$pdo->query("SELECT warehouse_id FROM warehouses ORDER BY warehouse_id LIMIT 1")->fetchColumn();
    $pid   = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 0 ORDER BY product_id LIMIT 1")->fetchColumn();
    ok($uid > 0 && $wid > 0 && $pid > 0, "fixture anchors found (user $uid, shop $wid, product $pid)");
    // The client's data: crate price in cost_price, per-bottle selling price.
    $pdo->prepare("UPDATE products SET cost_price = 11400, selling_price = 800, is_service = 0, is_combo = 0 WHERE product_id = ?")->execute([$pid]);
    // Isolate this product's batches in this shop to the fixture batch.
    $pdo->prepare("UPDATE product_batches SET quantity_remaining = 0 WHERE product_id = ? AND warehouse_id = ?")->execute([$pid, $wid]);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, 'TEST-LINECOST', 100, 100, 475, 800, '2001-01-01 08:00:00')")->execute([$pid, $wid]);
    $batchId = (int)$pdo->lastInsertId();

    $mkSale = function (string $date, float $qty, int $isReturn = 0, ?int $orig = null, string $status = 'completed') use ($pdo, $uid, $shift, $wid, $pid) {
        $net = 800 * $qty;
        $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, warehouse_id, project_id, subtotal, tax_amount, discount_amount, grand_total,
                          payment_method, payment_status, sale_status, is_return_sale, original_sale_id, sale_date, created_at)
                       VALUES (?, ?, ?, ?, NULL, ?, 0, 0, ?, 'cash', 'paid', ?, ?, ?, ?, ?)")
            ->execute(['TLC-' . uniqid(), $shift, $uid, $wid, $net, $net, $status, $isReturn, $orig, $date, $date]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, tax_rate, tax_amount, discount_rate, discount_amount, line_total)
                       VALUES (?, ?, 'Pepsi (test)', ?, 800, 0, 0, 0, 0, ?)")->execute([$sid, $pid, $qty, $net]);
        return [$sid, (int)$pdo->lastInsertId()];
    };
    // What process_sale does per line, then once per sale.
    $sell = function (string $date, float $qty) use ($pdo, $mkSale, $pid, $wid) {
        [$sid, $iid] = $mkSale($date, $qty);
        consumeFefoBatches($pdo, $pid, $wid, $qty, $iid);
        posStampSaleLineCosts($pdo, $sid);
        return [$sid, $iid];
    };
    // What create_return does per returned line.
    $giveBack = function (string $date, int $origSid, int $origIid, float $qty) use ($pdo, $mkSale) {
        $origUnitCost = posLineUnitCostOrNull($pdo, $origIid);
        [$rid, $riid] = $mkSale($date, $qty, 1, $origSid, 'refunded');
        if ($origUnitCost !== null) $pdo->prepare("UPDATE pos_sale_items SET unit_cost = ? WHERE sale_item_id = ?")->execute([$origUnitCost, $riid]);
        $pdo->prepare("UPDATE pos_sale_items SET returned_quantity = returned_quantity + ? WHERE sale_item_id = ?")->execute([$qty, $origIid]);
        reverseFefoBatchConsumption($pdo, $origIid, $qty);
        return [$rid, $riid];
    };
    $series = function (string $from, string $to) use ($pdo) {
        $r = posSimpleBuySellSeries($pdo, $from, $to, 'yearly', '');
        return ['sold' => array_sum(array_column($r, 'sold')), 'bought' => array_sum(array_column($r, 'bought')), 'profit' => array_sum(array_column($r, 'profit'))];
    };
    $lineCost = fn(int $iid) => $pdo->query("SELECT unit_cost FROM pos_sale_items WHERE sale_item_id = $iid")->fetchColumn();

    section('C. Sale fixes its cost on the line');
    [$sA, $iA] = $sell('2001-01-03 10:00:00', 30);
    ok(approx($lineCost($iA), 475), 'sale A line cost = batch cost 475 (not the crate price 11,400)');

    section('D. Returns come off Cost too (week with returns no longer shows a false loss)');
    [$sB, $iB] = $sell('2001-01-10 10:00:00', 10);
    [$r1, $ri1] = $giveBack('2001-01-10 12:00:00', $sA, $iA, 24);
    [$r2, $ri2] = $giveBack('2001-01-10 12:05:00', $sA, $iA, 6);   // now fully returned: batch links gone
    ok(approx($lineCost($ri1), 475) && approx($lineCost($ri2), 475), 'both return lines carry the original cost 475');
    $linksLeft = (int)$pdo->query("SELECT COUNT(*) FROM pos_sale_item_batches WHERE sale_item_id = $iA")->fetchColumn();
    ok($linksLeft === 0, 'fully returned line has no batch links left (the case that used to cost 0)');
    ok(approx($lineCost($iA), 475), 'sale A line still remembers cost 475');
    $w1 = $series('2001-01-01', '2001-01-07');
    ok(approx($w1['sold'], 24000) && approx($w1['bought'], 14250), 'week 1: sold 24,000, bought 14,250 (was 0 before the fix)');
    $w2 = $series('2001-01-08', '2001-01-14');
    ok(approx($w2['sold'], -16000), 'week 2: sold = 8,000 − 24,000 returned = −16,000');
    ok(approx($w2['bought'], -9500), 'week 2: bought = 4,750 − 14,250 returned = −9,500 (returns now come off Cost)');
    ok(approx($w2['profit'], -6500), 'week 2 profit −6,500 = sale B profit 3,250 − returned profit 9,750 (old code: −20,750)');
    $all = $series('2001-01-01', '2001-01-14');
    ok(approx($all['profit'], 3250), 'two weeks together: profit 3,250 = only sale B (sale A fully returned)');
    ok(approx(posSaleCogs($pdo, $sA), 14250), 'posSaleCogs(sale A) still 14,250 after the full return');
    ok(approx(posSaleLineUnitCost($pdo, $iA), 475), 'posSaleLineUnitCost(sale A line) = 475 (feeds the return\'s COGS reversal)');

    section('E. Batch edits: past sales keep their cost, the next sale uses the new one');
    $pdo->prepare("UPDATE product_batches SET unit_cost = 500 WHERE batch_id = ?")->execute([$batchId]);
    ok(approx(posSaleCogs($pdo, $sB), 4750), 'sale B cost unchanged (4,750) after editing the batch to 500');
    [$sC, $iC] = $sell('2001-01-11 10:00:00', 2);
    ok(approx($lineCost($iC), 500), 'new sale from the edited batch uses 500');
    // Void → edit the batch → sell again (the owner's correction workflow).
    reverseFefoBatchConsumption($pdo, $iC);
    $pdo->prepare("UPDATE pos_sales SET sale_status = 'voided' WHERE sale_id = ?")->execute([$sC]);
    $pdo->prepare("UPDATE product_batches SET unit_cost = 450 WHERE batch_id = ?")->execute([$batchId]);
    [$sD, $iD] = $sell('2001-01-11 11:00:00', 2);
    ok(approx($lineCost($iD), 450), 'void → edit batch to 450 → sell again: the new sale uses 450');
    $restored = (float)$pdo->query("SELECT quantity_remaining FROM product_batches WHERE batch_id = $batchId")->fetchColumn();
    ok(approx($restored, 100 - 10 - 2), 'batch stock right: 100 − 30 sold + 30 returned − 10 − 2 (C voided)');

    section('F. Lines that keep the old rule (unit_cost stays NULL)');
    $pid2 = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 0 AND product_id <> $pid ORDER BY product_id LIMIT 1")->fetchColumn();
    if ($pid2 > 0) {
        $pdo->prepare("UPDATE products SET cost_price = 9000, selling_price = 1000 WHERE product_id = ?")->execute([$pid2]);
        $pdo->prepare("UPDATE product_batches SET quantity_remaining = 0 WHERE product_id = ? AND warehouse_id = ?")->execute([$pid2, $wid]);
        [$sE] = $mkSale('2001-01-12 10:00:00', 1);
        $pdo->prepare("UPDATE pos_sale_items SET product_id = ? WHERE sale_id = ?")->execute([$pid2, $sE]);
        posStampSaleLineCosts($pdo, $sE);
        $v = $pdo->query("SELECT unit_cost FROM pos_sale_items WHERE sale_id = $sE")->fetchColumn();
        ok($v === null, 'non-batch line with cost_price > selling price stays NULL (old guard, heals when fixed)');
        $pdo->prepare("UPDATE products SET cost_price = 600 WHERE product_id = ?")->execute([$pid2]);
        [$sF] = $mkSale('2001-01-12 11:00:00', 1);
        $pdo->prepare("UPDATE pos_sale_items SET product_id = ? WHERE sale_id = ?")->execute([$pid2, $sF]);
        posStampSaleLineCosts($pdo, $sF);
        ok(approx($pdo->query("SELECT unit_cost FROM pos_sale_items WHERE sale_id = $sF")->fetchColumn(), 600), 'non-batch line with a sane cost_price fixes that cost (600)');
    } else {
        ok(true, 'only one product on this database — NULL-rule checks skipped');
    }

    section('G. Backfill of sales recorded before the column existed');
    // Rewind the fixture to the old state: no fixed costs anywhere on it.
    $pdo->prepare("UPDATE pos_sale_items SET unit_cost = NULL WHERE sale_id IN (?, ?, ?, ?, ?)")->execute([$sA, $sB, $r1, $r2, $sD]);
    $pdo->prepare("UPDATE product_batches SET unit_cost = 475 WHERE batch_id = ?")->execute([$batchId]);
    $n = posLineCostBackfill($pdo);
    ok($n['batch'] >= 2, "lines with batch links filled from batches ({$n['batch']})");
    ok(approx($lineCost($iB), 475) && approx($lineCost($iD), 475), 'sale B / D filled with their batch cost');
    ok(approx($lineCost($iA), 475), 'fully returned sale A (no links left) filled from the shop\'s batch history');
    ok(approx($lineCost($ri1), 475) && approx($lineCost($ri2), 475), 'return lines filled from the line they returned');
    $again = posLineCostBackfill($pdo);
    ok($again['returned'] === 0 && $again['return_lines'] === 0, 'backfill re-run changes nothing on the fixture (idempotent)');
    $w2b = $series('2001-01-08', '2001-01-14');
    // Section F's two sales are in the same week: F fixed 600; E (left NULL) now heals to the
    // corrected cost_price 600 through the old rule.
    $fExtra = $pid2 > 0 ? 1200 : 0;
    ok(approx($w2b['bought'], 4750 + 950 + $fExtra - 14250), 'after backfill week 2 bought = 4,750 + 950 (D) + 1,200 (E+F) − 14,250 returned');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'fixture rolled back — nothing persisted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
exit($fail === 0 ? 0 : 1);
