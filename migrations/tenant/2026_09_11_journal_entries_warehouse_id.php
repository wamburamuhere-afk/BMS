<?php
/**
 * migrations/tenant/2026_09_11_journal_entries_warehouse_id.php
 *
 * journal_entries has `project_id` (2026_05_28_journal_entries_project_and_source_link.php)
 * but no `warehouse_id` — no posting path (POS sale, invoice, supplier bill, stock
 * adjustment) records which warehouse a GL entry belongs to, so Income Statement /
 * Balance Sheet / Cash Flow / Trial Balance cannot be filtered by warehouse at all.
 * Purely additive, nullable column + a supporting index mirroring the existing
 * `ix_je_project` on project_id. core/ledger_post.php's postLedgerEntry() (next
 * change) starts writing it; a separate backfill migration fills historical rows.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add journal_entries.warehouse_id...\n";

try {
    $has = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'warehouse_id'")->fetch();
    if ($has) {
        echo "  journal_entries.warehouse_id already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE journal_entries ADD COLUMN warehouse_id INT NULL AFTER project_id");
        echo "  + Added journal_entries.warehouse_id (INT NULL).\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'ix_je_warehouse'")->fetch();
    if ($hasIdx) {
        echo "  Index ix_je_warehouse already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE journal_entries ADD INDEX ix_je_warehouse (warehouse_id)");
        echo "  + Added index ix_je_warehouse.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
