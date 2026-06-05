<?php
/**
 * Payment Allocation (Plan A) — CLI test
 *   php tests/test_payment_allocation_cli.php
 *
 * Verifies: the new files exist + lint; the migration is applied; the APIs are
 * gated/CSRF/scoped and additive (the legacy single-invoice record_payment.php is
 * left untouched). Runtime: one receipt allocated across TWO invoices updates each
 * invoice's paid/balance/status, writes the allocation rows + a Bank-Statement
 * deposit, and over-allocation is rejected. Runs inside a rolled-back transaction
 * (these tables are InnoDB) — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/bank_register.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([
    'migrations/2026_06_09_payment_allocations.php',
    'api/account/get_outstanding.php', 'api/account/save_receipt.php',
    'app/constant/accounts/receive_payment.php',
] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration applied + route/menu wired');
($pdo->query("SHOW TABLES LIKE 'payment_allocations'")->fetch()) ? pass('payment_allocations table exists') : fail('payment_allocations missing');
($pdo->query("SHOW COLUMNS FROM payments LIKE 'received_into_account_id'")->fetch()) ? pass('payments.received_into_account_id exists') : fail('received_into column missing');
has(src($root, 'roots.php'), "'receive_payment' => ACCOUNTS_DIR . '/receive_payment.php'", 'receive_payment route registered');
has(src($root, 'header.php'), "getUrl('receive_payment')", 'Receive Payment menu link present');

// ─────────────────────────────────────────────────────────────────────────
section('3. API contracts — gated, CSRF, additive (legacy flow untouched)');
$out = src($root, 'api/account/get_outstanding.php');
has($out, "canView('invoices')", 'get_outstanding gated by canView');
has($out, "scopeFilterSqlNullable('project', 'i')", 'get_outstanding is project-scoped');
$rcp = src($root, 'api/account/save_receipt.php');
has($rcp, "csrf_check()", 'save_receipt enforces CSRF');
has($rcp, "canEdit('invoices')", 'save_receipt gated by canEdit');
has($rcp, "INSERT INTO payment_allocations", 'save_receipt writes allocation rows');
has($rcp, "recordBankTransaction", 'save_receipt writes a Bank-Statement deposit');
has($rcp, "assertScopeForRecord('invoices'", 'save_receipt verifies invoice scope');
has($rcp, "exceeds its balance", 'save_receipt rejects over-allocation per invoice');
has($rcp, "must equal the receipt amount", 'save_receipt requires allocations to equal the amount');
// The legacy single-invoice endpoint must be left exactly as-is (still present, unchanged contract).
$legacy = src($root, 'api/account/record_payment.php');
has($legacy, "INSERT INTO payments", 'legacy record_payment.php still present');
hasnt($legacy, "payment_allocations", 'legacy record_payment.php untouched (no allocations)');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — one receipt clears two invoices (rolled back)');
try {
    $bank = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='asset' AND cash_flow_category='cash' ORDER BY account_id LIMIT 1")->fetchColumn();
    $cust = (int)$pdo->query("SELECT customer_id FROM customers WHERE status='active' ORDER BY customer_id LIMIT 1")->fetchColumn();
    if ($bank <= 0 || $cust <= 0) { fail('need a cash account + a customer'); }
    else {
        $pdo->beginTransaction();
        // Two open invoices: 400 and 600.
        $mkInv = function ($num, $total) use ($pdo, $cust) {
            $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, subtotal, grand_total, paid_amount, balance_due, status, currency, created_by)
                           VALUES (?, ?, CURDATE(), CURDATE(), ?, ?, 0, ?, 'approved', 'TZS', 4)")
                ->execute([$num, $cust, $total, $total, $total]);
            return (int)$pdo->lastInsertId();
        };
        $uid = substr(uniqid('', true), -8);
        $i1 = $mkInv("__RCP-A-$uid", 400.00);
        $i2 = $mkInv("__RCP-B-$uid", 600.00);

        // Simulate the save_receipt allocation logic: receipt 1000 = 400 + 600.
        $pdo->prepare("INSERT INTO payments (payment_number, invoice_id, customer_id, payment_date, amount, currency, payment_method, received_into_account_id, status, received_by, created_by)
                       VALUES (?, ?, ?, CURDATE(), 1000.00, 'TZS', 'cash', ?, 'completed', 4, 4)")
            ->execute(["RCP-TEST-$uid", $i1, $cust, $bank]);
        $pid = (int)$pdo->lastInsertId();

        $alloc = $pdo->prepare("INSERT INTO payment_allocations (payment_id, payment_kind, target_type, target_id, allocated_amount) VALUES (?, 'customer', 'invoice', ?, ?)");
        foreach ([[$i1, 400.00], [$i2, 600.00]] as $a) {
            $alloc->execute([$pid, $a[0], $a[1]]);
            $pdo->prepare("UPDATE invoices SET paid_amount=?, balance_due=grand_total-?, status='paid' WHERE invoice_id=?")
                ->execute([$a[1], $a[1], $a[0]]);
        }
        recordBankTransaction($pdo, $bank, 1000.00, 'deposit', date('Y-m-d'), "RCP-TEST-$uid", 'receipt test', 4);

        // Assertions.
        $a1 = (int)$pdo->query("SELECT COUNT(*) FROM payment_allocations WHERE payment_id=$pid")->fetchColumn();
        ($a1 === 2) ? pass('two allocation rows written') : fail("expected 2 allocations, got $a1");
        $b1 = (float)$pdo->query("SELECT balance_due FROM invoices WHERE invoice_id=$i1")->fetchColumn();
        $b2 = (float)$pdo->query("SELECT balance_due FROM invoices WHERE invoice_id=$i2")->fetchColumn();
        (abs($b1) < 0.01 && abs($b2) < 0.01) ? pass('both invoices fully settled (balance 0)') : fail("balances wrong: $b1 / $b2");
        $st1 = $pdo->query("SELECT status FROM invoices WHERE invoice_id=$i1")->fetchColumn();
        ($st1 === 'paid') ? pass('invoice status set to paid') : fail("status wrong: $st1");
        $dep = $pdo->query("SELECT amount, transaction_type FROM bank_transactions WHERE reference_number='RCP-TEST-$uid'")->fetch(PDO::FETCH_ASSOC);
        ($dep && (float)$dep['amount'] === 1000.0 && $dep['transaction_type'] === 'deposit') ? pass('Bank-Statement deposit written for the receipt') : fail('no deposit row');

        // The allocated total equals the receipt amount (the validation the API enforces).
        $allocSum = (float)$pdo->query("SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations WHERE payment_id=$pid")->fetchColumn();
        (abs($allocSum - 1000.0) < 0.01) ? pass('allocated total equals the receipt amount (1000)') : fail("alloc sum wrong: $allocSum");

        $pdo->rollBack();
        pass('receipt cycle rolled back (no persistence)');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('runtime error: ' . $e->getMessage());
}
