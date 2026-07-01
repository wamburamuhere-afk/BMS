<?php
/**
 * HIGH #3 — block deleting a Bill that has payments — CLI test
 *   php tests/test_bill_delete_guard_cli.php
 *
 * Verifies supplierInvoiceHasPayments() (the guard the delete endpoint uses)
 * detects a payment across every path: amount_paid, the partial/paid statuses,
 * the legacy single-payment link, and the partial-payment subledger — and
 * returns false for a clean, unpaid Bill (which stays deletable).
 *
 * All scenarios run inside a single ROLLED-BACK transaction; the DB is unchanged.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/purchase_posting.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function mkBill(PDO $pdo, int $sid, int $uid, string $status, float $paid, $legacyTxn = null): int {
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, amount_paid, status, payment_transaction_id, recorded_by)
                   VALUES ('supplier', ?, ?, '2026-06-01', '2026-06-01', 10000, ?, ?, ?, ?)")
        ->execute([$sid, 'TEST-DG-' . uniqid(), $paid, $status, $legacyTxn, $uid]);
    return (int)$pdo->lastInsertId();
}

$sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$sid ? pass("active supplier → #$sid") : fail('no active supplier');

if (!$sid) { fail('cannot run scenarios'); } else {
$pdo->beginTransaction();
try {
    section('1. Clean unpaid Bill — deletable (no payments)');
    $b = mkBill($pdo, $sid, $uid, 'approved', 0.0);
    // Purge any orphaned supplier_invoice_payments rows that share this auto-increment ID
    // (orphaned rows from deleted invoices can cause false positives).
    $pdo->prepare("DELETE FROM supplier_invoice_payments WHERE invoice_id = ?")->execute([$b]);
    (supplierInvoiceHasPayments($pdo, $b) === false) ? pass('unpaid approved Bill → hasPayments=false (deletable)') : fail('false positive on a clean Bill');

    section('2. amount_paid > 0 → blocked');
    $b = mkBill($pdo, $sid, $uid, 'approved', 2500.0);
    (supplierInvoiceHasPayments($pdo, $b) === true) ? pass('amount_paid>0 → hasPayments=true') : fail('missed amount_paid');

    section('3. status partial/paid → blocked');
    $b1 = mkBill($pdo, $sid, $uid, 'partial', 0.0);
    $b2 = mkBill($pdo, $sid, $uid, 'paid', 0.0);
    (supplierInvoiceHasPayments($pdo, $b1) === true) ? pass("status='partial' → true") : fail('missed partial status');
    (supplierInvoiceHasPayments($pdo, $b2) === true) ? pass("status='paid' → true")    : fail('missed paid status');

    section('4. legacy single-payment link → blocked');
    $b = mkBill($pdo, $sid, $uid, 'approved', 0.0, 999001);
    (supplierInvoiceHasPayments($pdo, $b) === true) ? pass('payment_transaction_id set → true') : fail('missed legacy payment link');

    section('5. partial-payment subledger row → blocked');
    $b = mkBill($pdo, $sid, $uid, 'approved', 0.0);
    $pdo->prepare("INSERT INTO supplier_invoice_payments (invoice_id, payment_date, amount, payment_method, recorded_by)
                   VALUES (?, '2026-06-02', 1000, 'Cash', ?)")->execute([$b, $uid]);
    (supplierInvoiceHasPayments($pdo, $b) === true) ? pass('subledger payment row → true') : fail('missed subledger payment');

    section('6. non-existent Bill → false (nothing to block)');
    (supplierInvoiceHasPayments($pdo, 999999999) === false) ? pass('missing id → false') : fail('returned true for a missing id');

} finally {
    $pdo->rollBack();
}
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE invoice_ref LIKE 'TEST-DG-%'")->fetchColumn();
($leftover === 0) ? pass('rolled back cleanly — no fixtures persisted') : fail("LEAKED $leftover rows");
}
