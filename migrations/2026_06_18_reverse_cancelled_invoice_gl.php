<?php
/**
 * Migration: reverse orphaned GL entries for cancelled invoices.
 *
 * When an invoice was approved (GL posted Dr AR / Cr Revenue) and later cancelled,
 * the old code only ran reverseOutputVat() — it never reversed the revenue or COGS
 * entries. This migration finds every such orphan and reverses it now.
 *
 * Idempotent: skips any invoice that already has an invoice_void or
 * invoice_cogs_void entry.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/revenue_posting.php";   // reverseInvoiceRevenue / reverseInvoiceCOGS

global $pdo;
$userId = 1; // system/admin user for audit trail

echo "Migration: reverse_cancelled_invoice_gl\n";
echo str_repeat('-', 50) . "\n";

// Find cancelled invoices that have a revenue GL entry but no reversal yet.
$stmt = $pdo->query("
    SELECT DISTINCT i.invoice_id, i.invoice_number
      FROM invoices i
      JOIN journal_entries je ON je.entity_type = 'invoice'
                              AND je.entity_id   = i.invoice_id
                              AND je.status      = 'posted'
     WHERE i.status = 'cancelled'
       AND NOT EXISTS (
           SELECT 1 FROM journal_entries jv
            WHERE jv.entity_type = 'invoice_void'
              AND jv.entity_id   = i.invoice_id
              AND jv.status      = 'posted'
       )
");
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($invoices) . " cancelled invoice(s) with orphaned revenue GL entries.\n\n";

$revRev = 0; $revCogs = 0; $errors = 0;

foreach ($invoices as $inv) {
    $id  = (int)$inv['invoice_id'];
    $num = $inv['invoice_number'] ?? "#$id";

    // Reverse revenue (Dr AR / Cr Revenue → Cr AR / Dr Revenue)
    $r = reverseInvoiceRevenue($pdo, $id, $userId);
    if ($r['reversed'] && $r['reason'] !== 'already_reversed') {
        $revRev++;
        echo "  Revenue reversed: Invoice $num (entry #{$r['entry_id']})\n";
    } elseif ($r['reason'] === 'already_reversed') {
        echo "  Revenue already reversed: Invoice $num — skipped\n";
    } else {
        echo "  Revenue reversal FAILED: Invoice $num — {$r['reason']}\n";
        $errors++;
    }

    // Reverse COGS (Dr COGS / Cr Inventory → Cr COGS / Dr Inventory), if any
    $c = reverseInvoiceCOGS($pdo, $id, $userId);
    if ($c['posted'] && $c['reason'] !== 'already_posted') {
        $revCogs++;
        echo "  COGS reversed:    Invoice $num (entry #{$c['entry_id']})\n";
    } elseif (in_array($c['reason'], ['no_accrual', 'no_cogs', 'already_reversed', 'already_posted'], true)) {
        // No COGS entry or already reversed — fine
    } else {
        echo "  COGS reversal FAILED: Invoice $num — {$c['reason']}\n";
        $errors++;
    }
}

echo "\nDone. Revenue reversed: $revRev | COGS reversed: $revCogs | Errors: $errors\n";
if ($errors > 0) exit(1);
