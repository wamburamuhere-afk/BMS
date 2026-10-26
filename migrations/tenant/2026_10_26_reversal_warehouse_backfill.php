<?php
/**
 * migrations/tenant/2026_10_26_reversal_warehouse_backfill.php
 *
 * Reversal entries (voided POS sales, voided bills/expenses, payment voids,
 * manual journal reversals) used to be saved without the original entry's
 * warehouse_id. A per-shop Profit Report then kept the original and lost the
 * reversal — a voided sale still counted for that shop. Reversals now inherit
 * the warehouse (core/expense_posting.php & co.); this fills the ones already
 * posted (core/ledger_post.php backfillReversalWarehouses()).
 *
 * Idempotent: only reversals whose warehouse_id is still NULL are touched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/ledger_post.php';
global $pdo;

echo "Starting tenant migration: reversal warehouse backfill...\n";
try {
    $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'warehouse_id'")->fetchColumn();
    if (!$hasCol) {
        echo "  · journal_entries.warehouse_id not present — nothing to do.\n";
    } else {
        $n = backfillReversalWarehouses($pdo);
        echo "  + $n reversal entr" . ($n === 1 ? 'y' : 'ies') . " given their original's warehouse.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
