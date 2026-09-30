<?php
/**
 * Migration: 2026_09_30_cleanup_probe_products
 *
 * Soft-deletes probe/test products whose names start with 'ZZ '
 * (created by API integration tests on the shop tenant: "ZZ Probe Item",
 * "ZZ Idem", etc.). Uses status='deleted' so FK references are preserved.
 *
 * Idempotent — safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: cleanup probe products...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $r = $pdo->query("SHOW TABLES LIKE 'products'");
    if (!($r && $r->fetch())) {
        echo "  · products table not present — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $stmt = $pdo->prepare("UPDATE products SET status = 'deleted' WHERE product_name LIKE 'ZZ %' AND status != 'deleted'");
    $stmt->execute();
    $n = $stmt->rowCount();
    echo "  · soft-deleted $n probe product(s) (name LIKE 'ZZ %').\n";

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
