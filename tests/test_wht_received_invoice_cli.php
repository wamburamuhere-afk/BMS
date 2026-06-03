<?php
/**
 * WHT on received-invoice payment (Phase 3) CLI test.
 *   php tests/test_wht_received_invoice_cli.php
 *
 * Drives api/received_invoices.php?action=record_payment in an isolated
 * subprocess (like test_supplier_payment_source_cli.php). Proves a WHT payment
 * pays the supplier NET, parks WHT in WHT Payable, stamps the invoice, and posts
 * the 3-line ledger entry — and that a no-WHT payment still pays in full. All
 * test fixtures are reversed + hard-deleted at the end.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    require_once "$root/roots.php";
    $p = json_decode(file_get_contents($argv[3]), true);
    $_POST = $p; $_GET = $p;                            // GET actions (list) read $_GET
    $_SERVER['REQUEST_METHOD'] = $p['_method'] ?? 'POST';
    $_SESSION['csrf_token'] = $p['_csrf'] ?? '';        // satisfy csrf_check if enforced
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/wht.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.01; }
function bal(PDO $pdo, int $id) { return (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id = $id")->fetchColumn(); }
function call($ep, $p) { global $root; $f = tempnam(sys_get_temp_dir(), 'wht'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f)); @unlink($f);
    $s = strpos($o, '{'); return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true); }

$createdInv = []; $createdTxn = [];
try {
    $sid    = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status!='deleted' LIMIT 1")->fetchColumn();
    $cb     = cashBankAccounts($pdo);
    $cash   = $cb ? (int)$cb[0]['account_id'] : 0;
    $whtAcc = (int)whtPayableAccountId($pdo);
    $r5     = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    ok($sid && $cash && $whtAcc && $r5, "have supplier, cash account, WHT account, WHT 5% rate");

    $mkInv = function (float $amount, float $subtotal, float $tax, string $ref) use ($pdo, $sid, &$createdInv) {
        $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by, created_at, updated_at)
                       VALUES ('supplier', ?, ?, ?, ?, ?, ?, ?, 'approved', 4, NOW(), NOW())")
            ->execute([$sid, $ref, date('Y-m-d'), date('Y-m-d'), $amount, $subtotal, $tax]);
        $id = (int)$pdo->lastInsertId(); $createdInv[] = $id; return $id;
    };

    // ── 1. WHT payment: gross 1,180,000 (sub 1,000,000 + VAT 180,000), WHT 5% ──
    $inv = $mkInv(1180000, 1000000, 180000, '__wht_ri_test');
    $cash0 = bal($pdo, $cash); $wht0 = bal($pdo, $whtAcc);
    $r = call('received_invoices', ['action'=>'record_payment','invoice_id'=>$inv,'payment_date'=>date('Y-m-d'),
              'payment_method'=>'Bank Transfer','payment_account_id'=>$cash,'wht_rate_id'=>$r5,'_csrf'=>'t']);
    ok(!empty($r['success']), "record_payment with WHT succeeded" . (empty($r['success']) ? " (".json_encode($r).")" : ""));

    $row = $pdo->query("SELECT status, wht_rate_id, wht_base, wht_amount, wht_posted, payment_transaction_id FROM supplier_invoices WHERE id=$inv")->fetch(PDO::FETCH_ASSOC);
    $txn = (int)($row['payment_transaction_id'] ?? 0); if ($txn) $createdTxn[] = $txn;
    ok($row && $row['status'] === 'paid', "invoice marked paid");
    ok($row && approx($row['wht_amount'], 50000) && approx($row['wht_posted'], 50000), "wht_amount + wht_posted = 50,000 stored");
    ok($row && (int)$row['wht_rate_id'] === $r5 && approx($row['wht_base'], 1000000), "wht_rate_id + base (1,000,000) stored");

    $lines = $txn ? $pdo->query("SELECT account_id,type,amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC) : [];
    ok(count($lines) === 3, "3-line ledger entry posted");
    $crCash = array_values(array_filter($lines, fn($l) => $l['type']==='credit' && (int)$l['account_id']===$cash));
    $crWht  = array_values(array_filter($lines, fn($l) => $l['type']==='credit' && (int)$l['account_id']===$whtAcc));
    ok($crCash && approx($crCash[0]['amount'], 1130000), "Cr Cash = NET 1,130,000");
    ok($crWht  && approx($crWht[0]['amount'], 50000),    "Cr WHT Payable = 50,000");
    ok(approx(bal($pdo, $cash), $cash0 - 1130000), "cash balance reduced by NET 1,130,000");
    ok(approx(bal($pdo, $whtAcc), $wht0 + 50000),  "WHT Payable balance increased by 50,000");

    // ── 2. No-WHT payment still pays in full ────────────────────────────────
    $inv2 = $mkInv(500000, 500000, 0, '__wht_ri_test_nowht');
    $cash1 = bal($pdo, $cash);
    $r2 = call('received_invoices', ['action'=>'record_payment','invoice_id'=>$inv2,'payment_date'=>date('Y-m-d'),
               'payment_method'=>'Cash','payment_account_id'=>$cash,'_csrf'=>'t']);
    ok(!empty($r2['success']), "no-WHT record_payment succeeded");
    $row2 = $pdo->query("SELECT wht_posted, payment_transaction_id FROM supplier_invoices WHERE id=$inv2")->fetch(PDO::FETCH_ASSOC);
    $txn2 = (int)($row2['payment_transaction_id'] ?? 0); if ($txn2) $createdTxn[] = $txn2;
    ok($row2 && $row2['wht_posted'] === null, "no-WHT: wht_posted left NULL");
    $l2 = $txn2 ? $pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn2")->fetchColumn() : 0;
    ok((int)$l2 === 2, "no-WHT: plain 2-line entry");
    ok(approx(bal($pdo, $cash), $cash1 - 500000), "no-WHT: cash reduced by full 500,000");

    // ── 3b. The list query exposes the supplier default WHT (drives modal autofill) ──
    $lst = call('received_invoices', ['action' => 'list', '_method' => 'GET']);
    $hasKey = false;
    foreach (($lst['data'] ?? []) as $r) { if (array_key_exists('default_wht_rate_id', $r)) { $hasKey = true; break; } }
    ok($hasKey, "received-invoice list exposes default_wht_rate_id for modal autofill");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    // Reverse ledger + remove fixtures so the DB is left exactly as found.
    foreach ($createdTxn as $t) { try { reverseOutflow($pdo, $t); } catch (Throwable $e) {} }
    foreach ($createdInv as $i) { try { $pdo->prepare("DELETE FROM supplier_invoices WHERE id=?")->execute([$i]); } catch (Throwable $e) {} }
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
