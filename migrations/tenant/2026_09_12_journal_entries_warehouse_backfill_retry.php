<?php
/**
 * migrations/tenant/2026_09_12_journal_entries_warehouse_backfill_retry.php
 *
 * ROOT-CAUSE FIX for a self-inflicted ordering bug in the two migrations that
 * shipped on 2026-09-11: `..._journal_entries_warehouse_backfill.php` and
 * `..._journal_entries_warehouse_id.php` share the same date prefix, and the
 * migration runner applies pending files in filename (alphabetical) order —
 * "backfill" sorts before "id", so the BACKFILL ran BEFORE the column that
 * added `journal_entries.warehouse_id` existed. Confirmed in
 * migrations/tenant_deploy.log / this tenant's deploy log: all 7 backfill
 * UPDATEs failed with "Unknown column 'je.warehouse_id'" and were silently
 * skipped by that migration's own try/catch (so the deploy didn't fail —
 * it just did nothing). The column-add migration then ran and succeeded,
 * but the backfill migration was already marked done and will never
 * automatically retry.
 *
 * This migration is intentionally NOT filename-order-dependent: it verifies
 * (and adds, if somehow still missing) the column itself before backfilling,
 * so it is correct regardless of what ran before it. Same idempotent
 * UPDATE ... WHERE warehouse_id IS NULL shape as the original — safe to run
 * on a database where the original backfill partially or fully succeeded.
 * Unlike the original, failures here are NOT silently swallowed — a real
 * SQL error now fails the migration loudly (exit 1) rather than passing as
 * "complete" having done nothing, so a future ordering mistake can't hide
 * the same way.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/financial_reports.php';   // assertLedgerBalanced
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: retry journal_entries.warehouse_id backfill...\n";

try {
    $hasCol = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'warehouse_id'")->fetch();
    if (!$hasCol) {
        echo "  ! warehouse_id column missing (previous migration didn't reach this tenant either) — adding it now.\n";
        $pdo->exec("ALTER TABLE journal_entries ADD COLUMN warehouse_id INT NULL AFTER project_id");
        $hasIdx = $pdo->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'ix_je_warehouse'")->fetch();
        if (!$hasIdx) $pdo->exec("ALTER TABLE journal_entries ADD INDEX ix_je_warehouse (warehouse_id)");
        echo "  + Added journal_entries.warehouse_id + ix_je_warehouse.\n";
    } else {
        echo "  · journal_entries.warehouse_id already exists — proceeding straight to backfill.\n";
    }

    $backfills = [
        "pos_sale/pos_cogs (from pos_sales)" => "
            UPDATE journal_entries je
              JOIN pos_sales ps ON ps.sale_id = je.entity_id
               SET je.warehouse_id = ps.warehouse_id
             WHERE je.entity_type IN ('pos_sale', 'pos_cogs')
               AND je.warehouse_id IS NULL
               AND ps.warehouse_id IS NOT NULL
        ",
        "pos_return/pos_return_cogs (from pos_sales)" => "
            UPDATE journal_entries je
              JOIN pos_sales ps ON ps.sale_id = je.entity_id
               SET je.warehouse_id = ps.warehouse_id
             WHERE je.entity_type IN ('pos_return', 'pos_return_cogs')
               AND je.warehouse_id IS NULL
               AND ps.warehouse_id IS NOT NULL
        ",
        "credit_note_cogs (from credit_notes -> invoices)" => "
            UPDATE journal_entries je
              JOIN credit_notes cn ON cn.credit_note_id = je.entity_id
              JOIN invoices i ON i.invoice_id = cn.invoice_id
               SET je.warehouse_id = i.warehouse_id
             WHERE je.entity_type = 'credit_note_cogs'
               AND je.warehouse_id IS NULL
               AND i.warehouse_id IS NOT NULL
        ",
        "invoice/invoice_cogs/invoice_void (from invoices)" => "
            UPDATE journal_entries je
              JOIN invoices i ON i.invoice_id = je.entity_id
               SET je.warehouse_id = i.warehouse_id
             WHERE je.entity_type IN ('invoice', 'invoice_cogs', 'invoice_void')
               AND je.warehouse_id IS NULL
               AND i.warehouse_id IS NOT NULL
        ",
        "supplier_invoice/subcontractor_invoice (from supplier_invoices)" => "
            UPDATE journal_entries je
              JOIN supplier_invoices si ON si.id = je.entity_id
               SET je.warehouse_id = si.warehouse_id
             WHERE je.entity_type IN ('supplier_invoice', 'subcontractor_invoice')
               AND je.warehouse_id IS NULL
               AND si.warehouse_id IS NOT NULL
        ",
        "purchase_return (from purchase_returns)" => "
            UPDATE journal_entries je
              JOIN purchase_returns pr ON pr.purchase_return_id = je.entity_id
               SET je.warehouse_id = pr.warehouse_id
             WHERE je.entity_type = 'purchase_return'
               AND je.warehouse_id IS NULL
               AND pr.warehouse_id IS NOT NULL
        ",
        "stock_adjustment/stock_adjustment_void (from stock_movements)" => "
            UPDATE journal_entries je
              JOIN stock_movements sm ON sm.movement_id = je.entity_id
               SET je.warehouse_id = sm.warehouse_id
             WHERE je.entity_type IN ('stock_adjustment', 'stock_adjustment_void')
               AND je.warehouse_id IS NULL
               AND sm.warehouse_id IS NOT NULL
        ",
    ];

    $totalUpdated = 0;
    foreach ($backfills as $label => $sql) {
        // No try/catch here on purpose — the column is now guaranteed to
        // exist, so a failure at this point is a real problem that must
        // stop the deploy and surface loudly, not repeat 2026-09-11's mistake.
        $n = $pdo->exec($sql);
        echo "  + $label: $n row(s) updated.\n";
        $totalUpdated += $n;
    }

    $check = assertLedgerBalanced($pdo, date('Y-m-d'));
    echo "  " . ($check['ok'] ? '+' : '!') . " assertLedgerBalanced: ledger_balanced="
        . var_export($check['ledger_balanced'], true) . " bs_balanced=" . var_export($check['bs_balanced'], true)
        . " (diff dr-cr=" . $check['dr_cr_difference'] . ", bs diff=" . $check['bs_difference'] . ")\n";

    echo "  Total rows backfilled this run: $totalUpdated.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
