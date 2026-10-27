<?php
/**
 * migrations/tenant/2026_10_26_ledger_warehouse_backfill.php
 *
 * Gives ledger entries the shop (warehouse_id) they belong to, so the per-shop
 * Profit Report is right for what was posted before the fix:
 *   1. Expenses — Simple POS expenses saved their shop, but their ledger
 *      entries did not, so every shop showed Total Expenses 0
 *      (core/expense_posting.php backfillExpenseWarehouses()).
 *   2. Reversals (voided POS sales, voided bills/expenses, payment voids,
 *      manual journal reversals) were saved without the original's shop, so a
 *      voided sale still counted for its shop
 *      (core/ledger_post.php backfillReversalWarehouses()).
 * Expenses run first so their voids then inherit the shop in step 2.
 *
 * Idempotent: only entries whose warehouse_id is still NULL are touched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/ledger_post.php';
require_once __DIR__ . '/../../core/expense_posting.php';
global $pdo;

echo "Starting tenant migration: ledger warehouse backfill...\n";
try {
    $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'warehouse_id'")->fetchColumn();
    if (!$hasCol) {
        echo "  · journal_entries.warehouse_id not present — nothing to do.\n";
    } else {
        $e = backfillExpenseWarehouses($pdo);
        echo "  + $e expense entr" . ($e === 1 ? 'y' : 'ies') . " given their expense's shop.\n";
        $r = backfillReversalWarehouses($pdo);
        echo "  + $r reversal entr" . ($r === 1 ? 'y' : 'ies') . " given their original's shop.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
