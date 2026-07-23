<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: repair invoices.balance_due drift...\n";

// `balance_due` is a denormalised convenience column that must always equal
// GREATEST(grand_total - paid_amount, 0) — the same formula the payment endpoints
// (record_payment, save_receipt, void_payment, apply_customer_advance) already
// maintain. Some rows drifted (e.g. showing 0 while the invoice is still fully
// unpaid), which made the AR aging report hide real debt. This heals every
// drifted row so the stored column matches the truth.
//
// SUBLEDGER-ONLY: this touches no journal_entries and no GL account. The Balance
// Sheet / Trial Balance / P&L / Cash Flow read only the posted journal, so they
// are completely unaffected by this repair. Criteria-based + idempotent: a re-run
// finds nothing left to fix.

try {
    $mismatch = "COALESCE(balance_due,0) <> GREATEST(grand_total - COALESCE(paid_amount,0), 0)";

    $before = (int)$pdo->query("
        SELECT COUNT(*) FROM invoices
         WHERE $mismatch AND status NOT IN ('cancelled','draft')
    ")->fetchColumn();
    echo "Rows with drifted balance_due: $before\n";

    $stmt = $pdo->prepare("
        UPDATE invoices
           SET balance_due = GREATEST(grand_total - COALESCE(paid_amount,0), 0)
         WHERE $mismatch AND status NOT IN ('cancelled','draft')
    ");
    $stmt->execute();
    echo "Repaired " . $stmt->rowCount() . " invoice(s).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
