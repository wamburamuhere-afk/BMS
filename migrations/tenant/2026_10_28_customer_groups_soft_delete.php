<?php
/**
 * migrations/tenant/2026_10_28_customer_groups_soft_delete.php
 *
 * customer_groups.status gains 'deleted' so Customer Groups can soft-delete a group
 * (api/delete_customer_group.php) instead of removing the row.
 * Idempotent: the enum is only altered while 'deleted' is missing from it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: customer groups soft delete...\n";
try {
    if ((bool)$pdo->query("SHOW TABLES LIKE 'customer_groups'")->fetchColumn()) {
        $col = $pdo->query("SHOW COLUMNS FROM customer_groups LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Type'], "'deleted'") === false) {
            $pdo->exec("ALTER TABLE customer_groups MODIFY status ENUM('active','inactive','deleted') DEFAULT 'active'");
            echo "  + customer_groups.status now allows 'deleted'.\n";
        }
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
