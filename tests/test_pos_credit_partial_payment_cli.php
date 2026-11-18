<?php
/**
 * tests/test_pos_credit_partial_payment_cli.php — credit sale with a part paid now (2026-11-17).
 *
 * Before: choosing "Mkopo wa Mteja" on the till hid the amount box and sent 0, so a customer who
 * paid 20,000 of 53,800 was recorded as owing all 53,800; a deposit was always booked as cash;
 * the receipt printed "Payment (Credit): <whole total>, Change 0".
 *
 * Through the real endpoints:
 *   - credit sale 10,000 with 4,000 paid by mobile money → partial, balance 6,000; payment row
 *     4,000 mobile_money; books Dr mobile-money account 4,000 + Dr receivables 6,000; nothing
 *     in the cash drawer; listed in Who Owes Me with 6,000
 *   - 2,500 paid in cash → the cash drawer gets 2,500, books Dr cash
 *   - an older client (no deposit_method) → cash, as before
 *   - nothing paid → pending, no payment row
 *   - receipt: Paid (Mobile money) 4,000 / Balance due 6,000 / Pay by; a cash sale's receipt unchanged
 *   - Receive Payment 6,000 → paid; receipt Balance due 0; off Who Owes Me
 *   - network printer text carries the same lines; till screen wiring
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
// Test seam: helpers.php's get_setting() caches settings at its first call; read live instead.
function get_setting($key, $default = '') {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $default;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]); $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/sales_posting.php";
require_once "$root/core/gl_accounts.php";
require_once "$root/core/pos_credit_aging.php";
require_once "$root/core/escpos_printer.php";
require_once "$root/core/pos_credit_receipt.php";
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf';
$set = fn($k, $v) => $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
$ap = fn($a, $b) => abs((float)$a - (float)$b) < 0.01;

$pdo->startOuter();
try {
    $set('pos_simple_mode', '1');
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-CPP', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $shift = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $pdo->prepare("INSERT INTO customers (customer_name, phone, status) VALUES (?, ?, 'active')")->execute(["ZZ Mama Asha $sfx", '0700' . random_int(100000, 999999)]);
    $C = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE customers SET credit_limit = 0 WHERE customer_id = ?")->execute([$C]);
    $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                   VALUES (?, ?, 'pcs', 1000, 0, 600, 600, 0, 'active', 100, 100)")->execute(["ZZ CPP $sfx", "CPP-$sfx"]);
    $P = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 100, 0)")->execute([$P, $W]);
    $due = date('Y-m-d', strtotime('+14 days'));

    $sale = function (int $qty, string $method, ?float $paid, ?string $depositMethod) use ($W, $C, $P, $due) {
        $total = 1000 * $qty;
        $d = ['customer_id' => $C, 'warehouse_id' => $W, 'project_id' => null,
              'items' => [['product_id' => $P, 'product_name' => 'x', 'quantity' => $qty, 'price' => 1000, 'discounted_price' => 1000, 'tax_rate' => 0]],
              'subtotal' => $total, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $total,
              'payment_method' => $method, 'amount_tendered' => $total, 'change_given' => 0, 'due_date' => $method === 'credit' ? $due : null];
        if ($paid !== null) $d['amount_paid'] = $paid;
        if ($depositMethod !== null) $d['deposit_method'] = $depositMethod;
        return call('api/pos/process_sale.php', 'POST', $d, true);
    };
    $debitTo = function (int $sid, ?int $acc) {
        if (!$acc) return null;
        return (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                            WHERE je.entity_type = 'pos_sale' AND je.entity_id = ? AND je.status = 'posted' AND jei.type = 'debit' AND jei.account_id = ?", [$sid, $acc]);
    };
    $drawer = fn(int $sid) => (float)val("SELECT COALESCE(SUM(amount),0) FROM cash_register_transactions WHERE sale_id = ? AND transaction_type = 'sale'", [$sid]);

    section('1. Credit sale 10,000 — 4,000 paid now by mobile money');
    $r = $sale(10, 'credit', 4000, 'mobile_money');
    ok(!empty($r['success']), 'sale saved' . (!empty($r['success']) ? '' : ' — ' . ($r['message'] ?? '')));
    $s1 = (int)($r['sale_id'] ?? 0);
    ok(($r['payment_status'] ?? '') === 'partial' && $ap($r['balance_due'] ?? 0, 6000), 'status partial, balance due 6,000');
    $pay = $pdo->query("SELECT amount, payment_method, notes FROM pos_sale_payments WHERE sale_id = $s1")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($pay) === 1 && $ap($pay[0]['amount'], 4000) && $pay[0]['payment_method'] === 'mobile_money', 'one payment row: 4,000 mobile money');
    $mob = posReceiptAccountId($pdo, 'mobile_money'); $cashAcc = posReceiptAccountId($pdo, 'cash'); $ar = arAccountId($pdo);
    if ($mob && $ar && $mob !== $cashAcc) {
        ok($ap($debitTo($s1, $mob), 4000) && $ap($debitTo($s1, $ar), 6000), 'books: Dr mobile-money account 4,000 + Dr receivables 6,000');
        ok($ap($debitTo($s1, $cashAcc), 0), 'books: nothing to the cash drawer account');
    } else {
        ok(true, 'mobile-money account not separate on this database — books split checked in section 2 only');
    }
    ok($ap($drawer($s1), 0), 'cash drawer (shift): nothing for a mobile-money payment');
    $open = array_values(array_filter(posCreditOpenSales($pdo, $C), fn($x) => (int)$x['sale_id'] === $s1));
    ok($open && $ap($open[0]['balance_due'], 6000), 'Who Owes Me lists it with 6,000 owed');

    section('2. 2,500 paid now in cash');
    $r = $sale(5, 'credit', 2500, 'cash');
    $s2 = (int)($r['sale_id'] ?? 0);
    ok(!empty($r['success']) && ($r['payment_status'] ?? '') === 'partial' && $ap($r['balance_due'] ?? 0, 2500), 'partial, balance 2,500');
    ok($ap($drawer($s2), 2500), 'cash drawer (shift) gets 2,500');
    if ($cashAcc && $ar) ok($ap($debitTo($s2, $cashAcc), 2500) && $ap($debitTo($s2, $ar), 2500), 'books: Dr cash 2,500 + Dr receivables 2,500');

    section('3. Older client (no deposit_method) and nothing paid');
    $r = $sale(5, 'credit', 3000, null);
    $s3 = (int)($r['sale_id'] ?? 0);
    ok(!empty($r['success']) && val("SELECT payment_method FROM pos_sale_payments WHERE sale_id = ?", [$s3]) === 'cash' && $ap($drawer($s3), 3000), 'older client: deposit recorded as cash, as before');
    $r = $sale(3, 'credit', 0, 'mobile_money');
    $s4 = (int)($r['sale_id'] ?? 0);
    ok(!empty($r['success']) && ($r['payment_status'] ?? '') === 'pending' && $ap($r['balance_due'] ?? 0, 3000), 'nothing paid: pending, 3,000 owed');
    ok((int)val("SELECT COUNT(*) FROM pos_sale_payments WHERE sale_id = ?", [$s4]) === 0, 'no payment row');

    section('4. Receipt');
    $html = raw('api/pos/print_receipt.php', 'GET', ['id' => $s1]);
    $txt = preg_replace('/\s+/', ' ', strip_tags($html));
    ok(strpos($txt, 'Paid (Mobile money): 4,000') !== false, 'receipt: Paid (Mobile money): 4,000');
    ok(strpos($txt, 'Balance due:') !== false && strpos($txt, '6,000') !== false, 'receipt: Balance due 6,000');
    ok(strpos($txt, 'Pay by: ' . date('d/m/Y', strtotime($due))) !== false, 'receipt: Pay by ' . date('d/m/Y', strtotime($due)));
    ok(strpos($txt, 'Payment (Credit)') === false && strpos($txt, 'Change:') === false, 'receipt: no "Payment (Credit): <total>" and no Change line');
    $rc = $sale(2, 'cash', 2000, null);
    $txtCash = preg_replace('/\s+/', ' ', strip_tags(raw('api/pos/print_receipt.php', 'GET', ['id' => (int)($rc['sale_id'] ?? 0)])));
    ok(strpos($txtCash, 'Payment (Cash)') !== false && strpos($txtCash, 'Change:') !== false && strpos($txtCash, 'Balance due:') === false, 'a cash sale\'s receipt is unchanged');

    section('5. Receive Payment clears the balance');
    $rp = call('api/pos/receive_payment.php', 'POST', ['sale_id' => $s1, 'amount' => 6000, 'payment_method' => 'cash']);
    ok(!empty($rp['success']), 'Receive Payment 6,000 saved' . (!empty($rp['success']) ? '' : ' — ' . ($rp['message'] ?? '')));
    ok(val("SELECT payment_status FROM pos_sales WHERE sale_id = ?", [$s1]) === 'paid', 'sale now paid');
    $txt2 = preg_replace('/\s+/', ' ', strip_tags(raw('api/pos/print_receipt.php', 'GET', ['id' => $s1])));
    ok(preg_match('/Balance due: \S+ 0\b/', $txt2) === 1 && strpos($txt2, 'Pay by:') === false, 'reprint: Balance due 0, no Pay by');
    ok(strpos($txt2, '10,000') !== false && strpos($txt2, 'Mobile money, Cash') !== false, 'reprint: Paid (Mobile money, Cash) 10,000');
    ok(!array_filter(posCreditOpenSales($pdo, $C), fn($x) => (int)$x['sale_id'] === $s1), 'off Who Owes Me');

    section('6. Network printer and till screen');
    $info = posCreditReceiptInfo($pdo, $pdo->query("SELECT * FROM pos_sales WHERE sale_id = $s2")->fetch(PDO::FETCH_ASSOC));
    $bytes = buildEscPosReceipt(['receipt_number' => 'X', 'items' => [], 'grand_total' => 5000, 'payment_method' => 'credit', 'amount_tendered' => 5000, 'change_given' => 0,
                                 'currency' => 'TZS', 'credit' => $info + ['methods_label' => 'Cash']], false);
    ok(strpos($bytes, 'Balance due:') !== false && strpos($bytes, '2,500.00') !== false && strpos($bytes, 'Tendered:') === false, 'thermal receipt: Paid / Balance due, no Tendered');
    $pos = (string)file_get_contents("$root/app/bms/pos/pos.php");
    ok(strpos($pos, 'id="creditPaymentSection"') !== false && strpos($pos, 'id="creditPaidNow"') !== false && strpos($pos, 'name="creditPaidMethod"') !== false && strpos($pos, 'id="creditSumBalance"') !== false, 'till: amount paid now, paid by, balance due');
    $js = (string)file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
    ok(strpos($js, "$('#creditPaymentSection').toggle(method === 'credit')") !== false, 'till: section shows only for Mkopo');
    ok(strpos($js, "deposit_method: (paymentMethod === 'credit')") !== false && strpos($js, "Math.min(Math.max(0, parseFloat($('#creditPaidNow').val()) || 0), calculatedTotal)") !== false, 'till: sends the amount paid now and how');
    ok(strpos($js, 'paidNow >= total - 0.01 && total > 0') !== false, 'till: paid in full on Mkopo is stopped (choose Cash/Mobile/Bank)');
} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $pdo->endOuter();
}
ok(!$pdo->inTransaction(), 'everything rolled back');
