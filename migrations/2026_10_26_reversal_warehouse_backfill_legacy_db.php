<?php
/**
 * migrations/2026_10_26_reversal_warehouse_backfill_legacy_db.php
 *
 * Legacy-DB mirror of migrations/tenant/2026_10_26_reversal_warehouse_backfill.php:
 * gives every reversal entry saved without a warehouse its original entry's
 * warehouse_id, so per-shop reports net a voided sale to zero. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ledger_post.php';
global $pdo;

echo "Starting migration: reversal warehouse backfill...\n";
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
