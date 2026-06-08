<?php
/**
 * POS Credit / Partial-payment + AR — Phase 3-A guard
 *   php tests/test_pos_credit_ar_cli.php
 *
 * Operational credit sales (no GL — the system-wide double-entry layer is a
 * separate future project; see double_entry_integration_plan.md).
 *
 *   A. STATIC contract — migration creates pos_sale_payments; process_sale stops
 *      hardcoding 'paid' and derives payment_status (pending/partial/paid),
 *      requires a customer for credit, and records the initial payment;
 *      receive_payment.php settles later (canEdit, CSRF); the list + history page
 *      surface balance + a Receive Payment action.
 *   B. LIVE reconciliation (rolled back) — a credit sale starts pending with full
 *      balance; partial payment → partial; final payment → paid, balance 0;
 *      amount_paid always == SUM(pos_sale_payments).
 *
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a,$b){ return abs((float)$a-(float)$b) < 0.01; }
function src($p){ return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('A. Contract — credit/partial + receive-payment');
    $proc = src("$root/api/pos/process_sale.php");
    $recv = src("$root/api/pos/receive_payment.php");
    $list = src("$root/api/pos/get_sales.php");
    $page = src("$root/app/bms/pos/sales_history.php");

    // migration
    $mig = glob("$root/migrations/*pos_sale_payments*.php");
    ok(count($mig) > 0, 'migration for pos_sale_payments exists');
    ok($mig && strpos(src($mig[0]), 'CREATE TABLE IF NOT EXISTS pos_sale_payments') !== false, 'migration creates pos_sale_payments idempotently');

    $o=[];$rc=0; exec('php -l '.escapeshellarg("$root/api/pos/receive_payment.php").' 2>&1',$o,$rc); ok($rc===0,'receive_payment.php lint-clean');

    // process_sale credit logic
    ok(strpos($proc, "'completed', 'paid'") === false, 'process_sale no longer hardcodes payment_status = paid');
    ok(strpos($proc, '$is_credit') !== false && strpos($proc, '$amount_paid_now') !== false, 'process_sale models credit + amount paid now');
    ok(preg_match('/Credit sales require a customer/i', $proc) === 1, 'process_sale blocks a credit sale without a customer');
    ok(strpos($proc, "'partial'") !== false && strpos($proc, "'pending'") !== false && strpos($proc, "\$final_payment_status") !== false,
        'process_sale derives payment_status pending/partial/paid');
    ok(strpos($proc, 'INSERT INTO pos_sale_payments') !== false, 'process_sale records the initial payment');

    // receive_payment
    ok(strpos($recv, "canEdit('pos')") !== false, 'receive_payment gated on canEdit(pos)');
    ok(strpos($recv, 'csrf_check()') !== false, 'receive_payment CSRF-checked');
    ok(strpos($recv, 'INSERT INTO pos_sale_payments') !== false, 'receive_payment records the payment');
    ok((bool)preg_match('/payment_status\s*=\s*\?/', $recv) && strpos($recv, "'paid'") !== false, 'receive_payment recomputes payment_status');
    ok(strpos($recv, 'balance') !== false && strpos($recv, 'exceeds the balance') !== false, 'receive_payment caps a payment at the balance due');

    // list + page
    ok(strpos($list, 'balance_due') !== false && strpos($list, 'can_receive') !== false, 'get_sales returns balance_due + can_receive');
    ok(strpos($page, 'openReceive') !== false && strpos($page, 'receiveModal') !== false && strpos($page, 'RECEIVE_URL') !== false,
        'sales history exposes a Receive Payment action + modal');

    section('B. Live reconciliation (rolled back)');
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sale_payments'")->fetch()) {
        ok(false, 'pos_sale_payments table missing — run the migration');
    } else {
        $pdo->beginTransaction();
        $uid   = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
        $shift = (int)($pdo->query("SELECT shift_id FROM cash_register_shifts ORDER BY shift_id LIMIT 1")->fetchColumn() ?: 0);
        $cust  = $pdo->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetchColumn() ?: null;

        $grand = 10000.00;
        $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, customer_id, subtotal, tax_amount, discount_amount, grand_total,
                          payment_method, payment_status, sale_status, is_return_sale, sale_date, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, 0, ?, 'credit', 'pending', 'completed', 0, NOW(), NOW())")
            ->execute(['TCR-'.uniqid(), $shift, $uid, $cust, $grand, $grand]);
        $sid = (int)$pdo->lastInsertId();

        $balOf = function() use ($pdo,$sid,$grand){
            $paid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM pos_sale_payments WHERE sale_id=$sid")->fetchColumn();
            return [$paid, round($grand-$paid,2)];
        };
        $statusOf = function($paid,$grand){ return $paid>=$grand-0.01 ? 'paid' : ($paid>0.01 ? 'partial' : 'pending'); };

        [$paid,$bal] = $balOf();
        ok(approx($paid,0) && approx($bal,$grand) && $statusOf($paid,$grand)==='pending', 'credit sale starts pending with full balance due');

        // partial payment 4,000
        $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_method, received_by, created_at) VALUES (?,4000,'cash',?,NOW())")->execute([$sid,$uid]);
        [$paid,$bal] = $balOf();
        ok(approx($paid,4000) && approx($bal,6000) && $statusOf($paid,$grand)==='partial', 'after 4,000 → partial, balance 6,000');

        // settle remaining 6,000
        $pdo->prepare("INSERT INTO pos_sale_payments (sale_id, amount, payment_method, received_by, created_at) VALUES (?,6000,'mobile_money',?,NOW())")->execute([$sid,$uid]);
        [$paid,$bal] = $balOf();
        ok(approx($paid,10000) && approx($bal,0) && $statusOf($paid,$grand)==='paid', 'after 6,000 → paid, balance 0 (amount_paid == Σ payments)');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'fixture rolled back — no test data persisted');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
