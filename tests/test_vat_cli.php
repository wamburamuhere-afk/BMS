<?php
/**
 * VAT 18% control-account logic — CLI test
 *   php tests/test_vat_cli.php
 *
 * Drives the real helpers in core/vat.php (the same calls the invoice
 * approval/delete endpoints make):
 *   - postOutputVat  → Output VAT Payable (liability) rises by the sales VAT
 *   - postInputVat   → Input VAT Recoverable (asset) rises by the purchase VAT
 *   - idempotent: posting twice never double-counts
 *   - reverse* restores the exact amount; flag cleared
 *   - vatNetPosition nets the two → payable / refundable
 *
 * Uses clearly-tagged throwaway rows and removes them at the end.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/vat.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function bal(PDO $pdo, ?int $id): float { if(!$id) return 0.0; $s=$pdo->prepare("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=?"); $s->execute([$id]); return (float)$s->fetchColumn(); }

$inv_id = null; $si_id = null;
try {
    $outAcc = outputVatAccountId($pdo);
    $inAcc  = inputVatAccountId($pdo);
    ok($outAcc > 0, "Output VAT Payable account configured (#$outAcc)");
    ok($inAcc  > 0, "Input VAT Recoverable account configured (#$inAcc)");

    // ─── OUTPUT VAT (sales invoice) ─────────────────────────────────────
    $cust = (int)($pdo->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetchColumn() ?: 0);
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, subtotal, tax_amount, grand_total, balance_due, status, created_by, created_at)
                   VALUES ('VATTEST-OUT', ?, CURDATE(), 1000000, 180000, 1180000, 1180000, 'reviewed', ?, NOW())")
        ->execute([$cust ?: null, $_SESSION['user_id'] ?? 1]);
    $inv_id = (int)$pdo->lastInsertId();

    $out0 = bal($pdo, $outAcc);
    postOutputVat($pdo, $inv_id);
    $out1 = bal($pdo, $outAcc);
    ok(abs(($out1 - $out0) - 180000) < 0.01, "postOutputVat raised Output VAT Payable by 180,000");
    $flag = $pdo->query("SELECT output_vat_posted FROM invoices WHERE invoice_id=$inv_id")->fetchColumn();
    ok(abs((float)$flag - 180000) < 0.01, "invoice records the posted VAT amount (180,000)");

    // Idempotent — second call must not double-post.
    postOutputVat($pdo, $inv_id);
    ok(abs(bal($pdo,$outAcc) - $out1) < 0.01, "postOutputVat is idempotent (no double count on re-approve)");

    // Reverse — restores exactly, flag cleared.
    reverseOutputVat($pdo, $inv_id);
    ok(abs(bal($pdo,$outAcc) - $out0) < 0.01, "reverseOutputVat restored Output VAT Payable exactly");
    ok($pdo->query("SELECT output_vat_posted FROM invoices WHERE invoice_id=$inv_id")->fetchColumn() === null, "output_vat_posted cleared after reverse");
    // Reverse again — no-op.
    reverseOutputVat($pdo, $inv_id);
    ok(abs(bal($pdo,$outAcc) - $out0) < 0.01, "reverseOutputVat is idempotent (no-op when not posted)");

    // ─── INPUT VAT (received invoice) ───────────────────────────────────
    $sup = (int)($pdo->query("SELECT supplier_id FROM suppliers ORDER BY supplier_id LIMIT 1")->fetchColumn() ?: 0);
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by, created_at)
                   VALUES ('supplier', ?, 'VATTEST-IN', CURDATE(), CURDATE(), 590000, 500000, 90000, 'reviewed', ?, NOW())")
        ->execute([$sup ?: null, $_SESSION['user_id'] ?? 1]);
    $si_id = (int)$pdo->lastInsertId();

    $in0 = bal($pdo, $inAcc);
    postInputVat($pdo, $si_id);
    ok(abs((bal($pdo,$inAcc) - $in0) - 90000) < 0.01, "postInputVat raised Input VAT Recoverable by 90,000");
    postInputVat($pdo, $si_id);
    ok(abs((bal($pdo,$inAcc) - $in0) - 90000) < 0.01, "postInputVat is idempotent");

    // ─── NET POSITION (both posted: output 180k vs input 90k) ───────────
    postOutputVat($pdo, $inv_id);   // re-post output for the net check
    $vat = vatNetPosition($pdo);
    ok($vat['net'] >= 0 && $vat['label'] === 'payable', "net position with Output 180k > Input 90k is PAYABLE");

    // ─── TAX REPORT alignment — must read the SAME source as the control
    //     accounts so the report reconciles with the Balance Sheet. ──────
    $tr = file_get_contents("$root/api/account/get_tax_report.php");
    ok(strpos($tr, 'FROM supplier_invoices si') !== false, "tax report reads VAT IN from supplier_invoices (the real bill)");
    ok(strpos($tr, "FROM purchase_orders po") === false,    "tax report no longer reads input VAT from purchase_orders");
    ok(strpos($tr, "status IN ('approved','paid')") !== false, "tax report VAT IN counts approved/paid received invoices");
    ok(strpos($tr, 'vatNetPosition(') !== false,            "tax report exposes ledger position for reconciliation");

    // Reverse everything back.
    reverseOutputVat($pdo, $inv_id);
    reverseInputVat($pdo, $si_id);
    ok(abs(bal($pdo,$inAcc) - $in0) < 0.01, "reverseInputVat restored Input VAT Recoverable exactly");
    ok($pdo->query("SELECT input_vat_posted FROM supplier_invoices WHERE id=$si_id")->fetchColumn() === null, "input_vat_posted cleared after reverse");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    // Cleanup throwaway rows.
    if ($inv_id) $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$inv_id]);
    if ($si_id)  $pdo->prepare("DELETE FROM supplier_invoices WHERE id = ?")->execute([$si_id]);
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail===0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
