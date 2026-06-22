<?php
/**
 * MEDIUM #4 — link existing Bill payments to their parent Bill in the GL.
 *
 * A payment posts its canonical entry as a mirror of the legacy transaction
 * (journal_entries.entity_type='books_transaction', entity_id=transaction_id),
 * with parent_entity_* left NULL — so the per-Bill trace ("all payments of
 * INV-X") had to go through the supplier_invoice_payments subledger. This
 * backfills journal_entries.parent_entity_type/parent_entity_id on those mirror
 * rows so the whole Bill (accrual + every part-payment) is traceable from the
 * ledger alone.
 *
 * Criteria-based + idempotent: only sets rows where parent_entity_id IS NULL.
 * Metadata only — no Dr/Cr amount changes, ledger balance untouched.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: link Bill payments to parent Bill in the GL (MEDIUM #4)...\n";

try {
    // (1) Partial-payment subledger → its Bill.
    $sub = $pdo->exec("
        UPDATE journal_entries je
          JOIN supplier_invoice_payments sip ON sip.journal_txn_id = je.entity_id
           SET je.parent_entity_type = 'supplier_invoice',
               je.parent_entity_id   = sip.invoice_id,
               je.updated_at         = NOW()
         WHERE je.entity_type = 'books_transaction'
           AND je.parent_entity_id IS NULL
           AND sip.journal_txn_id IS NOT NULL
    ");
    echo "  linked " . (int)$sub . " payment mirror row(s) via the partial-payment subledger\n";

    // (2) Legacy single-payment link (supplier_invoices.payment_transaction_id) → its Bill.
    $leg = $pdo->exec("
        UPDATE journal_entries je
          JOIN supplier_invoices si ON si.payment_transaction_id = je.entity_id
           SET je.parent_entity_type = 'supplier_invoice',
               je.parent_entity_id   = si.id,
               je.updated_at         = NOW()
         WHERE je.entity_type = 'books_transaction'
           AND je.parent_entity_id IS NULL
           AND si.payment_transaction_id IS NOT NULL
    ");
    echo "  linked " . (int)$leg . " payment mirror row(s) via the legacy payment_transaction_id\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
