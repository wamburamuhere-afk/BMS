<?php
/**
 * migrations/2026_09_08_pos_receipt_templates_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_08_pos_receipt_templates.php (Phase 22 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_price_groups_legacy_db.php for why this exists, and
 * 2026_09_08_pos_network_printer_legacy_db.php for why the same
 * table-missing / anchor-missing degrade-instead-of-fail approach is used
 * here too — a failure in this file is script_stop: true for every host.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS receipt templates on the legacy database (Phase 22)...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'pos_registers'")->fetch();
    if (!$table) {
        echo "  · pos_registers does not exist on this database — nothing to add columns to. Skipping (not this migration's job to create it).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $exists = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE 'receipt_template'")->fetch();
    if ($exists) {
        echo "  · pos_registers.receipt_template already present.\n";
    } else {
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE 'printer_port'")->fetch();
        $position = $anchor ? " AFTER printer_port" : "";
        $pdo->exec("ALTER TABLE pos_registers ADD COLUMN receipt_template ENUM('classic','detailed','slim') NOT NULL DEFAULT 'classic'$position");
        echo "  + pos_registers.receipt_template added" . ($position ? "" : " (anchor column 'printer_port' not found — appended at end instead)") . ".\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
