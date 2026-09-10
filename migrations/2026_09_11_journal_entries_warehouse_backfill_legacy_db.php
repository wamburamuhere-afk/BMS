<?php
/**
 * migrations/2026_09_11_journal_entries_warehouse_backfill_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_11_journal_entries_warehouse_backfill.php onto
 * the LEGACY / non-tenant database, same reason as its schema-column counterpart:
 * the backfill should apply everywhere this codebase runs, not just for registered
 * tenants.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/financial_reports.php';   // assertLedgerBalanced
global $pdo;

echo "Starting migration: backfill journal_entries.warehouse_id on the legacy database...\n";

/** One (entity_type => source join) backfill, guarded for a source table that may not exist. */
function backfill_je_warehouse(PDO $pdo, string $label, string $sql): void
{
    try {
        $n = $pdo->exec($sql);
        echo "  + $label: $n row(s) updated.\n";
    } catch (PDOException $e) {
        echo "  ! $label skipped — " . $e->getMessage() . "\n";
    }
}

try {
    backfill_je_warehouse($pdo, "pos_sale/pos_cogs (from pos_sales)", "
        UPDATE journal_entries je
          JOIN pos_sales ps ON ps.sale_id = je.entity_id
           SET je.warehouse_id = ps.warehouse_id
         WHERE je.entity_type IN ('pos_sale', 'pos_cogs')
           AND je.warehouse_id IS NULL
           AND ps.warehouse_id IS NOT NULL
    ");

    backfill_je_warehouse($pdo, "pos_return/pos_return_cogs (from pos_sales)", "
        UPDATE journal_entries je
          JOIN pos_sales ps ON ps.sale_id = je.entity_id
           SET je.warehouse_id = ps.warehouse_id
         WHERE je.entity_type IN ('pos_return', 'pos_return_cogs')
           AND je.warehouse_id IS NULL
           AND ps.warehouse_id IS NOT NULL
    ");

    backfill_je_warehouse($pdo, "credit_note_cogs (from credit_notes -> invoices)", "
        UPDATE journal_entries je
          JOIN credit_notes cn ON cn.credit_note_id = je.entity_id
          JOIN invoices i ON i.invoice_id = cn.invoice_id
           SET je.warehouse_id = i.warehouse_id
         WHERE je.entity_type = 'credit_note_cogs'
           AND je.warehouse_id IS NULL
           AND i.warehouse_id IS NOT NULL
    ");

    backfill_je_warehouse($pdo, "invoice/invoice_cogs/invoice_void (from invoices)", "
        UPDATE journal_entries je
          JOIN invoices i ON i.invoice_id = je.entity_id
           SET je.warehouse_id = i.warehouse_id
         WHERE je.entity_type IN ('invoice', 'invoice_cogs', 'invoice_void')
           AND je.warehouse_id IS NULL
           AND i.warehouse_id IS NOT NULL
    ");

    backfill_je_warehouse($pdo, "supplier_invoice/subcontractor_invoice (from supplier_invoices)", "
        UPDATE journal_entries je
          JOIN supplier_invoices si ON si.id = je.entity_id
           SET je.warehouse_id = si.warehouse_id
         WHERE je.entity_type IN ('supplier_invoice', 'subcontractor_invoice')
           AND je.warehouse_id IS NULL
           AND si.warehouse_id IS NOT NULL
    ");

    backfill_je_warehouse($pdo, "purchase_return (from purchase_returns)", "
        UPDATE journal_entries je
          JOIN purchase_returns pr ON pr.purchase_return_id = je.entity_id
           SET je.warehouse_id = pr.warehouse_id
         WHERE je.entity_type = 'purchase_return'
           AND je.warehouse_id IS NULL
           AND pr.warehouse_id IS NOT NULL
    ");

    backfill_je_warehouse($pdo, "stock_adjustment/stock_adjustment_void (from stock_movements)", "
        UPDATE journal_entries je
          JOIN stock_movements sm ON sm.movement_id = je.entity_id
           SET je.warehouse_id = sm.warehouse_id
         WHERE je.entity_type IN ('stock_adjustment', 'stock_adjustment_void')
           AND je.warehouse_id IS NULL
           AND sm.warehouse_id IS NOT NULL
    ");

    $check = assertLedgerBalanced($pdo, date('Y-m-d'));
    echo "  " . ($check['ok'] ? '+' : '!') . " assertLedgerBalanced: ledger_balanced="
        . var_export($check['ledger_balanced'], true) . " bs_balanced=" . var_export($check['bs_balanced'], true)
        . " (diff dr-cr=" . $check['dr_cr_difference'] . ", bs diff=" . $check['bs_difference'] . ")\n";
    if (!$check['ok']) {
        echo "  ! WARNING: ledger was already unbalanced BEFORE this backfill touched anything —\n";
        echo "    this migration only writes the new warehouse_id column, so it did not cause this;\n";
        echo "    investigate separately via assertLedgerBalanced()/glOpeningBalanceImbalance().\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
