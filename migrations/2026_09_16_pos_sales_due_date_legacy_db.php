<?php
/**
 * migrations/2026_09_16_pos_sales_due_date_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_16_pos_sales_due_date.php onto the
 * LEGACY / non-tenant database. See 2026_09_08_pos_network_printer_legacy_db.php
 * for why this pairing exists.
 *
 * Degrades instead of failing: this file, unlike a tenant migration, runs as
 * part of migrations/runner.php — a failure here (exit 1) is `script_stop:
 * true` on the deploy script, halting the release for EVERY host. If
 * `pos_sales` doesn't exist at all on this database, that's a separate,
 * pre-existing gap outside this migration's job to fix — log it and exit 0
 * rather than blocking every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add pos_sales.due_date on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch();
    if (!$table) {
        echo "  · pos_sales does not exist on this database — nothing to add. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $has = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'due_date'")->fetch();
    if ($has) {
        echo "  pos_sales.due_date already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN due_date DATE NULL AFTER payment_date");
        echo "  + Added pos_sales.due_date (DATE NULL).\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM pos_sales WHERE Key_name = 'idx_pos_sales_due_date'")->fetch();
    if ($hasIdx) {
        echo "  Index idx_pos_sales_due_date already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE pos_sales ADD INDEX idx_pos_sales_due_date (due_date)");
        echo "  + Added index idx_pos_sales_due_date.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
