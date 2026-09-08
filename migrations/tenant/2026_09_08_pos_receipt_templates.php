<?php
/**
 * migrations/tenant/2026_09_08_pos_receipt_templates.php
 *
 * Phase 22 (pos_upgrade_plan.md §8) — receipt layout variety. 'classic' (the
 * existing, unmodified layout) stays the default so no existing register's
 * printed receipt changes unless an admin explicitly picks a different one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS receipt templates (Phase 22)...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE 'receipt_template'")->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE pos_registers ADD COLUMN receipt_template ENUM('classic','detailed','slim') NOT NULL DEFAULT 'classic' AFTER printer_port");
        echo "  + pos_registers.receipt_template added.\n";
    } else {
        echo "  · pos_registers.receipt_template already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
