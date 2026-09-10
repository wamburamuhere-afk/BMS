<?php
/**
 * migrations/2026_09_11_journal_entries_warehouse_id_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_11_journal_entries_warehouse_id.php onto the
 * LEGACY / non-tenant database, same reason as the 2026-09-10 permission-key
 * migrations: the fix should apply everywhere this codebase runs, not just for
 * registered tenants.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add journal_entries.warehouse_id on the legacy database...\n";

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
