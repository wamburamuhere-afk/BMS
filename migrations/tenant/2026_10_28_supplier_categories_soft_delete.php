<?php
/**
 * migrations/tenant/2026_10_28_supplier_categories_soft_delete.php
 *
 * supplier_categories.status gains 'deleted' so Supplier Categories can soft-delete
 * an unused category (api/delete_supplier_category.php) instead of removing the row.
 * Idempotent: the enum is only altered while 'deleted' is missing from it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: supplier categories soft delete...\n";
try {
    if ((bool)$pdo->query("SHOW TABLES LIKE 'supplier_categories'")->fetchColumn()) {
        $col = $pdo->query("SHOW COLUMNS FROM supplier_categories LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Type'], "'deleted'") === false) {
            $pdo->exec("ALTER TABLE supplier_categories MODIFY status ENUM('active','inactive','deleted') DEFAULT 'active'");
            echo "  + supplier_categories.status now allows 'deleted'.\n";
        }
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
