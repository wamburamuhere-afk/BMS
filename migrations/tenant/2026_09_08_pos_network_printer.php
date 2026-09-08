<?php
/**
 * migrations/tenant/2026_09_08_pos_network_printer.php
 *
 * Phase 21 (pos_upgrade_plan.md §8) — real network (IP) thermal-printer
 * support. A printer with its own Ethernet/WiFi interface is a plain TCP
 * socket target — no browser, no WebUSB, no print-bridge agent needed. Adds
 * the per-register connection fields; 'browser' stays the default so no
 * existing register's behaviour changes unless an admin explicitly opts in.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS network printer support (Phase 21)...\n";

try {
    $cols = [
        'printer_connection_type' => "ENUM('browser','network') NOT NULL DEFAULT 'browser' AFTER receipt_printer",
        'printer_ip_address'      => "VARCHAR(45) DEFAULT NULL AFTER printer_connection_type",
        'printer_port'            => "INT DEFAULT 9100 AFTER printer_ip_address",
    ];
    foreach ($cols as $col => $def) {
        $exists = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE " . $pdo->quote($col))->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE pos_registers ADD COLUMN `$col` $def");
            echo "  + pos_registers.$col added.\n";
        } else {
            echo "  · pos_registers.$col already present.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
