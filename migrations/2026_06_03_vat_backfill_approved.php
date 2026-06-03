<?php
/**
 * 2026_06_03_vat_backfill_approved.php
 * ------------------------------------
 * One-time backfill so EVERY already-approved invoice's VAT is recorded in the
 * VAT control accounts — not just invoices approved after the VAT feature went
 * live. Accrual basis: VAT is due once an invoice has passed approval.
 *
 *   - Sales invoices  (status approved/paid/partial, tax > 0, not yet posted)
 *       → postOutputVat → Output VAT Payable (liability) ↑
 *   - Received invoices (status approved/paid, tax > 0, not yet posted)
 *       → postInputVat  → Input VAT Recoverable (asset) ↑
 *
 * Idempotent: the helpers skip any document that already recorded its VAT
 * (output_vat_posted / input_vat_posted IS NOT NULL), so re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/vat.php';
global $pdo;

echo "Starting migration: backfill VAT for already-approved invoices...\n";

try {
    // Output VAT — sales invoices that have passed approval.
    $sales = $pdo->query("
        SELECT invoice_id
          FROM invoices
         WHERE tax_amount > 0
           AND output_vat_posted IS NULL
           AND status IN ('approved','paid','partial')
    ")->fetchAll(PDO::FETCH_COLUMN);
    $n1 = 0;
    foreach ($sales as $id) { postOutputVat($pdo, (int)$id); $n1++; }
    echo "  + output VAT recognised for {$n1} sales invoice(s).\n";

    // Input VAT — received invoices that have passed approval.
    $recv = $pdo->query("
        SELECT id
          FROM supplier_invoices
         WHERE tax_amount > 0
           AND input_vat_posted IS NULL
           AND status IN ('approved','paid')
    ")->fetchAll(PDO::FETCH_COLUMN);
    $n2 = 0;
    foreach ($recv as $id) { postInputVat($pdo, (int)$id); $n2++; }
    echo "  + input VAT recognised for {$n2} received invoice(s).\n";

    // Report the resulting position.
    $v = vatNetPosition($pdo);
    echo sprintf("  = VAT position now: Output %s − Input %s = %s %s\n",
        number_format($v['output'], 2), number_format($v['input'], 2),
        number_format(abs($v['net']), 2), strtoupper($v['label']));

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
