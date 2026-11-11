<?php
/**
 * tests/test_pos_refund_on_receipt_cli.php — goods that come back show on the receipt itself (2026-11-11).
 *
 *   A. A real cash sale (3 Soda + 2 Bread) and a real return of 1 Soda (api/pos/create_return.php):
 *      refund number is sequential (PREFIX-RET-0001), stock comes back, the books get Sales return / Cash
 *      and Inventory / COGS for exactly what came back.
 *   B. The original receipt (api/pos/print_receipt.php): Soda shows 3 crossed out then 2, price 2 × unit;
 *      Bread unchanged; Sold total, Refunded (RET-…), NET PAID, PARTIALLY REFUNDED; no "not returnable".
 *      Everything returned → whole line crossed out, stamp REFUNDED.
 *   C. The refund receipt: REFUND RECEIPT, For receipt #, TOTAL REFUNDED, Paid back (Cash), reason.
 *   D. The sales list (api/pos/get_sales.php): the receipt carries its refunds + Refunded total; the refund
 *      row names its receipt.
 *   E. Credit sale not paid: the return comes off what the customer owes — no cash out, balance drops,
 *      ledger credits Accounts Receivable; part paid: the owed part first, the rest paid back.
 *   F. Network printer text, emailed receipt and the list page use the same rules.
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/pos_receipt_returns.php";
require_once "$root/core/escpos_printer.php";

$near = fn($a, $b) => abs((float)$a - (float)$b) < 0.01;

$pdo->startOuter();
try {
    require_once "$root/core/sales_posting.php";
    // (An ALTER would end the rolled-back transaction, so the migration must have run first.)
    ok(posReturnCreditReady($pdo), "pos_sale_payments accepts 'return_credit' (run migrations/2026_11_11_pos_return_credit_legacy_db.php if not)");

    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-RFR', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $shiftId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $mk = function (string $name, float $price, float $cost) use ($pdo, $W, $sfx) {
        $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                       VALUES (?, ?, 'pcs', ?, 0, ?, ?, 0, 'active', 20, 20)")
            ->execute(["$name $sfx", 'RFR-' . strtoupper(substr($name, 0, 3)) . "-$sfx", $price, $cost, $cost]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 20, 0)")->execute([$id, $W]);
        return $id;
    };
    $SODA = $mk('Soda', 1000, 600);
    $BREAD = $mk('Bread', 1500, 900);
    $stock = fn($pid) => (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$pid, $W]);
    $line = fn($pid, $q, $p) => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0];
    $sell = function (array $its, array $extra = []) use ($W) {
        $t = array_sum(array_map(fn($i) => $i['price'] * (float)$i['quantity'], $its));
        return call('api/pos/process_sale.php', 'POST', array_merge(['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t,
            'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t,
            'change_given' => 0, 'amount_paid' => $t], $extra), true);
    };
    $itemId = fn($sid, $pid) => (int)val("SELECT sale_item_id FROM pos_sale_items WHERE sale_id = ? AND product_id = ?", [$sid, $pid]);
    $giveBack = fn($sid, array $lines, string $method = 'cash') => call('api/pos/create_return.php', 'POST', [
        'original_sale_id' => $sid, 'reason' => 'Damaged', 'refund_method' => $method, '_csrf' => 'test-csrf',
        'items' => json_encode(array_map(fn($k, $q) => ['sale_item_id' => $k, 'return_qty' => $q], array_keys($lines), $lines))]);
    $gl = fn(string $type, int $id, string $side) => (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                                                           WHERE je.status = 'posted' AND je.entity_type = ? AND je.entity_id = ? AND jei.type = ?", [$type, $id, $side]);
    $glAcc = fn(string $type, int $id, ?int $acc, string $side) => $acc ? (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                                                           WHERE je.status = 'posted' AND je.entity_type = ? AND je.entity_id = ? AND jei.account_id = ? AND jei.type = ?", [$type, $id, $acc, $side]) : -1;

    // ───────────────────────────────────────────────────────────────────────────
    section('A. A cash sale of 3 Soda + 2 Bread, then 1 Soda comes back');
    $s = $sell([$line($SODA, 3, 1000), $line($BREAD, 2, 1500)]);
    ok(!empty($s['success']), 'sale of 6,000 goes through' . (empty($s['success']) ? ' — ' . ($s['message'] ?? '') : ''));
    $sid = (int)($s['sale_id'] ?? 0);
    $rcpt = (string)val("SELECT receipt_number FROM pos_sales WHERE sale_id = ?", [$sid]);
    $r1 = $giveBack($sid, [$itemId($sid, $SODA) => 1]);
    ok(!empty($r1['success']), 'return of 1 Soda accepted' . (empty($r1['success']) ? ' — ' . ($r1['message'] ?? '') : ''));
    $rid1 = (int)($r1['return_id'] ?? 0);
    $rno1 = (string)($r1['receipt_number'] ?? '');
    ok((bool)preg_match('/^[A-Z0-9]{1,10}-RET-\d{4,}$/', $rno1), "refund number is sequential and company-prefixed ($rno1)");
    ok($near($r1['refund_total'] ?? 0, 1000) && $near($r1['paid_back'] ?? 0, 1000) && $near($r1['off_balance'] ?? -1, 0), 'refund 1,000, all paid back (cash sale)');
    ok($near($stock($SODA), 18), 'Soda stock 20 → 17 → 18 (1 came back)');
    ok(val("SELECT sale_status FROM pos_sales WHERE sale_id = ?", [$sid]) === 'partially_refunded', 'original is Partially Refunded');
    ok($near(val("SELECT COALESCE(SUM(amount),0) FROM cash_register_transactions WHERE sale_id = ? AND transaction_type = 'refund'", [$rid1]), 1000), 'cash drawer: 1,000 refund on the shift');
    ok($near($gl('pos_return', $rid1, 'debit'), 1000) && $near($glAcc('pos_return', $rid1, posReceiptAccountId($pdo, 'cash'), 'credit'), 1000), 'books: Sales return 1,000 / Cash 1,000');
    ok($near($gl('pos_return_cogs', $rid1, 'debit'), 600), 'books: Inventory 600 / COGS 600 (1 × cost 600 — what the sale charged)');
    ok(!val("SELECT COUNT(*) FROM pos_sale_payments WHERE sale_id = ? AND payment_method = 'return_credit'", [$sid]), 'no "taken off balance" line for a paid cash sale');

    // A second refund number follows the first.
    $r1b = $giveBack($sid, [$itemId($sid, $BREAD) => 1]);
    $rno1b = (string)($r1b['receipt_number'] ?? '');
    $n = fn($no) => (int)preg_replace('/^.*-RET-/', '', $no);
    ok(!empty($r1b['success']) && $n($rno1b) === $n($rno1) + 1, "next refund number follows: $rno1 → $rno1b");

    // ───────────────────────────────────────────────────────────────────────────
    section('B. The original receipt shows what came back');
    $html = raw('api/pos/print_receipt.php', 'GET', ['id' => $sid]);
    ok(strpos($html, '<span class="qty-was">3</span>2') !== false, 'Soda: 3 crossed out, then 2 — same row');
    ok(strpos($html, '<span class="qty-was">2</span>1') !== false, 'Bread: 2 crossed out, then 1');
    ok(strpos($html, '<div class="item-price">2,000</div>') !== false, 'Soda price = what remains (2 × 1,000 = 2,000)');
    ok(strpos($html, '<div class="item-price">1,500</div>') !== false, 'Bread price = what remains (1 × 1,500 = 1,500)');
    ok(strpos($html, '@') !== false && preg_match('/1,000\s*x 2/', $html) === 1, 'sub-line reads "@ 1,000 x 2"');
    ok(strpos($html, 'Sold total:') !== false && strpos($html, '6,000') !== false, 'Sold total: 6,000');
    $short1 = posRefundShortNo($rno1);
    ok(strpos($html, "Refunded ($short1):") !== false && strpos($html, '-1,000') !== false, "Refunded ($short1): -1,000");
    ok(strpos($html, 'Refunded (' . posRefundShortNo($rno1b) . '):') !== false && strpos($html, '-1,500') !== false, 'second refund has its own line (-1,500)');
    ok(strpos($html, 'NET PAID:') !== false && strpos($html, '3,500') !== false, 'NET PAID: 3,500 (6,000 − 1,000 − 1,500)');
    ok(strpos($html, 'Payment (Cash):') !== false, 'Payment (Cash) line kept');
    ok(strpos($html, '<div class="refund-stamp">PARTIALLY REFUNDED</div>') !== false, 'stamp: PARTIALLY REFUNDED');
    ok(strpos($html, 'Subtotal:') === false && strpos($html, 'TOTAL:') === false, 'old Subtotal / TOTAL lines replaced by Sold total / NET PAID');
    ok(strpos($html, 'Goods sold are not returnable') === false, '"Goods sold are not returnable" is gone');

    $giveBack($sid, [$itemId($sid, $SODA) => 2, $itemId($sid, $BREAD) => 1]);
    $html = raw('api/pos/print_receipt.php', 'GET', ['id' => $sid]);
    ok(val("SELECT sale_status FROM pos_sales WHERE sale_id = ?", [$sid]) === 'refunded', 'everything back → original Refunded');
    ok(strpos($html, '<div class="refund-stamp">REFUNDED</div>') !== false, 'stamp: REFUNDED');
    ok(substr_count($html, 'item-name item-gone') === 2, 'both lines crossed out (nothing kept)');
    ok(strpos($html, '<span class="qty-was">3</span>0') !== false && preg_match('/NET PAID:<\/span>\s*<span>TZS 0<\/span>|NET PAID:<\/span>\s*<span>[A-Z]{3} 0<\/span>/', $html) === 1, 'Soda 3 → 0, NET PAID 0');

    $plainSale = $sell([$line($SODA, 1, 1000)]);
    $plain = raw('api/pos/print_receipt.php', 'GET', ['id' => (int)$plainSale['sale_id']]);
    ok(strpos($plain, '<span class="qty-was">') === false && strpos($plain, 'TOTAL:') !== false && strpos($plain, 'class="refund-stamp"') === false && strpos($plain, 'NET PAID') === false, 'a receipt with no return looks as before');

    // ───────────────────────────────────────────────────────────────────────────
    section('C. The refund receipt');
    $rh = raw('api/pos/print_receipt.php', 'GET', ['id' => $rid1]);
    ok(strpos($rh, 'REFUND RECEIPT') !== false, 'title REFUND RECEIPT');
    ok(strpos($rh, 'For receipt #:') !== false && strpos($rh, htmlspecialchars($rcpt)) !== false, "For receipt #: $rcpt");
    ok(strpos($rh, 'TOTAL REFUNDED:') !== false && strpos($rh, '1,000') !== false, 'TOTAL REFUNDED: 1,000');
    ok(strpos($rh, 'Paid back (Cash):') !== false, 'Paid back (Cash): 1,000');
    ok(strpos($rh, 'Reason:') !== false && strpos($rh, 'Damaged') !== false, 'reason shown');
    ok(strpos($rh, 'Taken off amount owed') === false, 'no "taken off amount owed" on a cash sale');

    // ───────────────────────────────────────────────────────────────────────────
    section('D. The sales list');
    $today = date('Y-m-d');
    $list = call('api/pos/get_sales.php', 'GET', ['start_date' => $today, 'end_date' => $today]);
    $byId = []; foreach (($list['data'] ?? []) as $r) $byId[(int)$r['sale_id']] = $r;
    $o = $byId[$sid] ?? [];
    ok(count($o['refunds'] ?? []) === 3, 'receipt carries its 3 refunds');
    ok($near($o['refunded_total'] ?? 0, 6000), 'Refunded total 6,000');
    ok(($o['refunds'][0]['receipt_number'] ?? '') === $rno1 && (int)($o['refunds'][0]['sale_id'] ?? 0) === $rid1, 'first refund: number + id to open its receipt');
    ok(($byId[$rid1]['original_receipt'] ?? '') === $rcpt && (int)($byId[$rid1]['is_return_sale'] ?? 0) === 1, 'refund row names its receipt (stays in the data for the app)');

    // ───────────────────────────────────────────────────────────────────────────
    section('E. Credit sales: the return comes off what is owed first');
    $cust = (int)val("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1");
    ok($cust > 0, 'a customer for the credit sale');
    $due = date('Y-m-d', strtotime('+7 days'));
    $c = $sell([$line($SODA, 5, 1000)], ['customer_id' => $cust, 'payment_method' => 'credit', 'amount_tendered' => 0, 'amount_paid' => 0, 'due_date' => $due]);
    ok(!empty($c['success']), 'credit sale of 5,000, nothing paid' . (empty($c['success']) ? ' — ' . ($c['message'] ?? '') : ''));
    $csid = (int)($c['sale_id'] ?? 0);
    $owed = fn($id) => (float)val("SELECT grand_total - COALESCE((SELECT SUM(amount) FROM pos_sale_payments WHERE sale_id = ?),0) FROM pos_sales WHERE sale_id = ?", [$id, $id]);
    ok($near($owed($csid), 5000), 'customer owes 5,000');
    $cr = $giveBack($csid, [$itemId($csid, $SODA) => 1]);
    $crid = (int)($cr['return_id'] ?? 0);
    ok(!empty($cr['success']) && $near($cr['off_balance'] ?? 0, 1000) && $near($cr['paid_back'] ?? -1, 0), '1,000 taken off what is owed, nothing paid back');
    ok($near($owed($csid), 4000), 'customer now owes 4,000');
    ok(!val("SELECT COUNT(*) FROM cash_register_transactions WHERE sale_id = ? AND transaction_type = 'refund'", [$crid]), 'no cash leaves the drawer');
    ok(val("SELECT payment_method FROM pos_sales WHERE sale_id = ?", [$crid]) === 'credit', 'the refund row says nothing was paid back (method credit)');
    ok($near($glAcc('pos_return', $crid, arAccountId($pdo), 'credit'), 1000) && $near($glAcc('pos_return', $crid, posReceiptAccountId($pdo, 'cash'), 'credit'), 0), 'books: Sales return 1,000 / Accounts Receivable 1,000 — no cash');
    require_once "$root/core/pos_credit_aging.php";
    $open = array_values(array_filter(posCreditOpenSales($pdo, $cust), fn($r) => (int)$r['sale_id'] === $csid));
    ok($open && $near($open[0]['balance_due'], 4000), 'Who Owes Me: this sale now owes 4,000');
    if (posSimpleModeEnabled()) {   // the detail window is a Simple POS screen (it exits otherwise)
        $det = call('api/pos/get_credit_sale_detail.php', 'GET', ['sale_id' => $csid]);
        $lbl = array_column($det['payments'] ?? [], 'method_label');
        ok(in_array('Goods returned (' . ($cr['receipt_number'] ?? '') . ')', $lbl, true), 'Who Owes Me history: "Goods returned (' . ($cr['receipt_number'] ?? '') . ')"');
    } else {
        $dsrc = file_get_contents("$root/api/pos/get_credit_sale_detail.php");
        ok(strpos($dsrc, "=== 'return_credit'") !== false && strpos($dsrc, "t('Goods returned (%s)')") !== false
           && strpos(file_get_contents("$root/assets/js/pos-credit-aging.js"), 'p.method_label ||') !== false, 'Who Owes Me history labels it "Goods returned (RET-…)" (Simple POS off here — checked in code)');
    }
    $crh = raw('api/pos/print_receipt.php', 'GET', ['id' => $crid]);
    ok(strpos($crh, 'Taken off amount owed:') !== false && strpos($crh, 'Paid back') === false, 'refund receipt: "Taken off amount owed: 1,000", no paid back');
    $items = call('api/pos/get_sale_items.php', 'GET', ['sale_id' => $csid]);
    ok($near($items['sale']['balance_due'] ?? 0, 4000), 'Return window knows the 4,000 still owed');

    // Part paid: 3,000 sale, 2,500 paid → owes 500; returning 1,000 → 500 off the balance, 500 paid back.
    $p = $sell([$line($BREAD, 2, 1500)], ['customer_id' => $cust, 'payment_method' => 'credit', 'amount_tendered' => 2500, 'amount_paid' => 2500, 'due_date' => $due]);
    $psid = (int)($p['sale_id'] ?? 0);
    ok(!empty($p['success']) && $near($owed($psid), 500), 'credit sale 3,000 with 2,500 paid — owes 500');
    $pr = $giveBack($psid, [$itemId($psid, $BREAD) => 1]);
    $prid = (int)($pr['return_id'] ?? 0);
    ok(!empty($pr['success']) && $near($pr['off_balance'] ?? 0, 500) && $near($pr['paid_back'] ?? 0, 1000), '1,500 back: 500 off the balance, 1,000 paid back');
    ok($near($owed($psid), 0) && val("SELECT payment_status FROM pos_sales WHERE sale_id = ?", [$psid]) === 'paid', 'nothing owed any more → sale marked paid');
    ok($near(val("SELECT COALESCE(SUM(amount),0) FROM cash_register_transactions WHERE sale_id = ? AND transaction_type = 'refund'", [$prid]), 1000), 'cash drawer: only the 1,000 really paid back');
    ok($near($glAcc('pos_return', $prid, arAccountId($pdo), 'credit'), 500) && $near($glAcc('pos_return', $prid, posReceiptAccountId($pdo, 'cash'), 'credit'), 1000), 'books: Accounts Receivable 500 + Cash 1,000');
    ok($near($gl('pos_return', $prid, 'debit'), $gl('pos_return', $prid, 'credit')), 'the return entry balances');

    // ───────────────────────────────────────────────────────────────────────────
    section('F. Network printer, email and the list page follow the same rules');
    $s2 = $sell([$line($SODA, 3, 1000)]);
    $sid2 = (int)$s2['sale_id'];
    $rr = $giveBack($sid2, [$itemId($sid2, $SODA) => 1]);
    $saleRow = $pdo->query("SELECT * FROM pos_sales WHERE sale_id = $sid2")->fetch(PDO::FETCH_ASSOC);
    $its = $pdo->query("SELECT * FROM pos_sale_items WHERE sale_id = $sid2")->fetchAll(PDO::FETCH_ASSOC);
    $view = posReceiptReturns($pdo, $saleRow, $its);
    ok($view['mode'] === 'returns' && $near($view['lines'][0]['kept'], 2) && $near($view['lines'][0]['kept_total'], 2000) && $near($view['net_paid'], 2000), 'shared view: kept 2, 2,000, NET PAID 2,000');
    $bytes = buildEscPosReceipt(['company_name' => 'X', 'receipt_number' => $saleRow['receipt_number'], 'items' => [
            ['product_name' => 'Soda', 'quantity' => 2, 'qty_was' => 3, 'unit_price' => 1000, 'line_total' => 2000]],
        'grand_total' => 3000, 'payment_method' => 'cash', 'amount_tendered' => 3000, 'change_given' => 0, 'currency' => 'TZS',
        'returns' => ['sold_total' => 3000, 'net_paid' => 2000, 'stamp' => 'PARTIALLY REFUNDED',
                      'refunds' => [['label' => 'Refunded (' . posRefundShortNo($rr['receipt_number']) . ')', 'amount' => 1000]]]], false);
    ok(strpos($bytes, '(was 3)') !== false && strpos($bytes, 'NET PAID:') !== false && strpos($bytes, '** PARTIALLY REFUNDED **') !== false, 'network printer: "(was 3)", NET PAID, stamp');
    $em = file_get_contents("$root/api/pos/email_receipt.php");
    ok(strpos($em, 'posReceiptReturns(') !== false && strpos($em, '<del>') !== false && strpos($em, "t('NET PAID')") !== false, 'emailed receipt uses the same view (crossed-out qty, NET PAID)');
    ok(strpos($em, "t('Goods sold are not returnable')") === false && strpos(file_get_contents("$root/api/pos/print_receipt.php"), "Goods sold are not returnable") === false, '"not returnable" removed from printed and emailed receipts');
    $pg = file_get_contents("$root/app/bms/pos/pos_dashboard.php");
    ok(strpos($pg, 'id="kindFilter"') !== false && strpos($pg, '!r.is_return_sale') !== false, 'list: Sales (default) hides refund rows; Returns shows them');
    ok(strpos($pg, 'T.viewRefundReceipt') !== false && strpos($pg, '(row.refunds || []).forEach') !== false, 'Actions: View Refund Receipt for each refund');
    ok(strpos($pg, "<th class=\"text-primary text-end\"><?= t('Refunded') ?></th>") !== false && strpos($pg, "data: 'refunded_total'") !== false, 'list: Refunded column');
} finally {
    $pdo->endOuter();
}
