<?php
/**
 * migrations/2026_09_17_customers_status_deleted_enum_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_17_customers_status_deleted_enum.php onto
 * the LEGACY / non-tenant database. See
 * 2026_09_08_pos_network_printer_legacy_db.php for why this pairing exists.
 *
 * Degrades instead of failing: if `customers` doesn't exist on this database,
 * that's outside this migration's job — log it and exit 0 rather than
 * blocking every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: widen customers.status enum to include 'deleted' on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'customers'")->fetch();
    if (!$table) {
        echo "  · customers does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  customers.status column not found — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    if (stripos($col['Type'], "'deleted'") !== false) {
        echo "  customers.status already includes 'deleted' — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE customers MODIFY COLUMN status ENUM('active','inactive','suspended','blacklisted','deleted') NOT NULL DEFAULT 'active'");
        echo "  + Widened customers.status to include 'deleted' (matches suppliers.status).\n";
    }

    $fixed = $pdo->exec("UPDATE customers SET status = 'active' WHERE status = ''");
    if ($fixed > 0) {
        echo "  + Repaired $fixed customer row(s) that had been silently corrupted to an empty status by the pre-existing bug.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
