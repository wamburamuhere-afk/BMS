<?php
/**
 * migrations/2026_10_26_ledger_warehouse_backfill_legacy_db.php
 *
 * Legacy-DB mirror of migrations/tenant/2026_10_26_ledger_warehouse_backfill.php:
 * gives expense ledger entries their expense's shop, then gives reversal
 * entries their original's shop, so the per-shop Profit Report counts shop
 * expenses and nets voided sales to zero. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ledger_post.php';
require_once __DIR__ . '/../core/expense_posting.php';
global $pdo;

echo "Starting migration: ledger warehouse backfill...\n";
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
