<?php
/**
 * POS Returns / Refund + Void — Phase 1 guard
 *   php tests/test_pos_returns_cli.php
 *
 * Two layers:
 *   A. STATIC contract — the void + return endpoints exist, lint clean, are
 *      permission-gated, and use the correct stock/cash/status mechanics.
 *   B. LIVE data-model reconciliation (transaction-wrapped + ROLLED BACK) — proves
 *      the accounting model the endpoints implement:
 *        · void  → sale excluded from POS gross revenue; stock restored
 *        · partial return → contra row created; original → partially_refunded;
 *          net POS revenue = gross − returns; net POS COGS reduced by restocked cost
 *
 * No web server / HTTP needed. Read-only against real data except inside one
 * transaction that is always rolled back. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)   { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t)  { echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a,$b){ return abs((float)$a - (float)$b) < 0.01; }
function src($p)      { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () { global $pass, $fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n"; });

try {
    // ── A. Static endpoint contract ─────────────────────────────────────────
    section('A. Endpoint contract (void + return)');
    $void = "$root/api/pos/void_sale.php";
    $ret  = "$root/api/pos/create_return.php";
    foreach (['void_sale.php' => $void, 'create_return.php' => $ret,
              'get_sales.php' => "$root/api/pos/get_sales.php",
              'get_sale_items.php' => "$root/api/pos/get_sale_items.php"] as $name => $f) {
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        ok($rc === 0, "$name lint-clean");
    }
    $vs = src($void); $rs = src($ret);
    ok(strpos($vs, "canDelete('pos')") !== false, 'void gated on canDelete(pos)');
    ok(strpos($vs, 'csrf_check()') !== false,       'void CSRF-checked');
    ok(strpos($vs, "'voided'") !== false && strpos($vs, 'voided_at') !== false, 'void sets sale_status=voided + voided_at');
    ok(strpos($vs, "'return_in'") !== false,         'void restocks via return_in stock movement');
    ok(strpos($vs, "'refund'") !== false,            'void posts a cash refund transaction');
    ok(strpos($rs, "canCreate('pos')") !== false,    'return gated on canCreate(pos)');
    ok(strpos($rs, 'csrf_check()') !== false,        'return CSRF-checked');
    ok(strpos($rs, 'is_return_sale') !== false && strpos($rs, 'original_sale_id') !== false, 'return writes is_return_sale + original_sale_id');
    ok(strpos($rs, 'returned_quantity') !== false,   'return increments returned_quantity on the original lines');
    ok(strpos($rs, "'return_in'") !== false,         'return restocks via return_in stock movement');
    ok(preg_match('/partially_refunded|refunded/', $rs) === 1, 'return flips original status to partially_refunded/refunded');

    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) {
        ok(true, 'pos_sales absent on this server — live reconciliation skipped');
        exit($fail === 0 ? 0 : 1);
    }

    // ── B. Live data-model reconciliation (rolled back) ─────────────────────
    section('B. Live reconciliation (transaction rolled back)');
    $pdo->beginTransaction();

    // Anchors that satisfy NOT-NULL/FK columns, using real rows.
    $uid    = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
    $shift  = (int)($pdo->query("SELECT shift_id FROM cash_register_shifts ORDER BY shift_id LIMIT 1")->fetchColumn() ?: 0);
    $prod   = $pdo->query("SELECT product_id, COALESCE(cost_price,0) cost, COALESCE(selling_price,0) sell, COALESCE(stock_quantity,0) qty
                             FROM products WHERE COALESCE(cost_price,0) > 0 ORDER BY product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($prod !== false, 'found a product with a cost price for the fixture');
    $pid = (int)$prod['product_id']; $cost = (float)$prod['cost'];
    $unit = (float)$prod['sell'] > 0 ? (float)$prod['sell'] : 1000.0;
    $stock0 = (float)$pdo->query("SELECT COALESCE(stock_quantity,0) FROM products WHERE product_id=$pid")->fetchColumn();

    $period = [date('Y-m-01'), date('Y-m-t')];
    $mkSale = function (int $qty, string $status, int $isReturn = 0, ?int $orig = null) use ($pdo, $uid, $shift, $pid, $unit) {
        $net = $unit * $qty; $tax = round($net * 0.18, 2); $grand = round($net + $tax, 2);
        $rcpt = ($isReturn ? 'TRET-' : 'TST-') . uniqid();
        $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, warehouse_id, project_id,
                          subtotal, tax_amount, discount_amount, grand_total, payment_method, payment_status,
                          sale_status, is_return_sale, original_sale_id, sale_date, created_at)
                       VALUES (?,?,?,NULL,NULL,?,?,0,?, 'cash','paid', ?, ?, ?, NOW(), NOW())")
            ->execute([$rcpt, $shift, $uid, $net, $tax, $grand, $status, $isReturn, $orig]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, tax_rate, tax_amount, discount_amount, line_total)
                       VALUES (?,?,?,?,?,18,?,0,?)")
            ->execute([$sid, $pid, 'Fixture', $qty, $unit, $tax, $net]);
        return [$sid, $grand, $tax, $net];
    };

    // POS gross-revenue / returns SQL mirrors get_income_statement.php closures.
    $sumGross = function () use ($pdo, $period) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(grand_total-tax_amount),0) FROM pos_sales
                             WHERE sale_status IN ('completed','partially_refunded','refunded')
                               AND invoice_id IS NULL AND is_return_sale=0 AND DATE(sale_date) BETWEEN ? AND ?");
        $s->execute($period); return (float)$s->fetchColumn();
    };
    $sumReturns = function () use ($pdo, $period) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(grand_total-tax_amount),0) FROM pos_sales
                             WHERE is_return_sale=1 AND sale_status NOT IN ('voided','cancelled')
                               AND invoice_id IS NULL AND DATE(sale_date) BETWEEN ? AND ?");
        $s->execute($period); return (float)$s->fetchColumn();
    };
    $sumCogsNet = function () use ($pdo, $period) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN ps.is_return_sale=0 THEN psi.quantity*COALESCE(p.cost_price,0)
                                                     ELSE -psi.quantity*COALESCE(p.cost_price,0) END),0)
                              FROM pos_sales ps JOIN pos_sale_items psi ON psi.sale_id=ps.sale_id
                              JOIN products p ON p.product_id=psi.product_id
                             WHERE ps.invoice_id IS NULL
                               AND ((ps.is_return_sale=0 AND ps.sale_status IN ('completed','partially_refunded','refunded'))
                                 OR (ps.is_return_sale=1 AND ps.sale_status NOT IN ('voided','cancelled')))
                               AND DATE(ps.sale_date) BETWEEN ? AND ?");
        $s->execute($period); return (float)$s->fetchColumn();
    };

    $baseRev = $sumGross(); $baseRet = $sumReturns(); $baseCogs = $sumCogsNet();

    // 1) VOID — sale should NOT add to gross; stock restored.
    [$sid1, $g1, $t1, $n1] = $mkSale(5, 'completed');
    // simulate the sale's stock deduction so we can prove the void restores it
    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - 5 WHERE product_id=$pid")->execute();
    ok(approx($sumGross(), $baseRev + $n1), 'completed POS sale adds net to gross revenue');
    // apply void (mirror endpoint): restore stock + mark voided
    $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + 5 WHERE product_id=$pid")->execute();
    $pdo->prepare("UPDATE pos_sales SET sale_status='voided', voided_at=NOW(), voided_by=$uid WHERE sale_id=$sid1")->execute();
    ok(approx($sumGross(), $baseRev), 'voided sale removed from gross revenue');
    $stockNow = (float)$pdo->query("SELECT stock_quantity FROM products WHERE product_id=$pid")->fetchColumn();
    ok(approx($stockNow, $stock0), 'stock fully restored after void');

    // 2) PARTIAL RETURN — original keeps gross; contra subtracts; net reduces.
    [$sid2, $g2, $t2, $n2] = $mkSale(10, 'completed');         // 10 units sold
    $revAfterSale = $sumGross();
    ok(approx($revAfterSale, $baseRev + $n2), 'second completed sale recognised gross');
    // return 4 of 10 (mirror endpoint): contra row + bump returned_quantity + flip status
    $rNet = $unit * 4; $rTax = round($rNet * 0.18, 2); $rGrand = round($rNet + $rTax, 2);
    [$rid] = $mkSale(4, 'refunded', 1, $sid2);
    $pdo->prepare("UPDATE pos_sale_items SET returned_quantity = returned_quantity + 4 WHERE sale_id=$sid2")->execute();
    $remaining = (float)$pdo->query("SELECT SUM(quantity-returned_quantity) FROM pos_sale_items WHERE sale_id=$sid2")->fetchColumn();
    $pdo->prepare("UPDATE pos_sales SET sale_status = ? WHERE sale_id=$sid2")
        ->execute([$remaining <= 0.0001 ? 'refunded' : 'partially_refunded']);
    ok($remaining > 0, 'partial return leaves a remaining quantity (→ partially_refunded)');
    ok(approx($sumReturns(), $baseRet + $rNet), 'return row recognised as contra (net of VAT)');
    $netRev = $sumGross() - $sumReturns();
    ok(approx($netRev, $baseRev - $baseRet + $n2 - $rNet), 'net POS revenue = gross − returns (no double count)');
    // COGS: 10 sold − 4 returned = net 6 units of cost above baseline
    ok(approx($sumCogsNet(), $baseCogs + 6 * $cost), 'net POS COGS reduced by restocked cost (6 units net)');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'fixture rolled back — no test data persisted');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
