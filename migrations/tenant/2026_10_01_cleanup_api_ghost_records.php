<?php
/**
 * Migration: 2026_10_01_cleanup_api_ghost_records
 *
 * Removes products and suppliers created by Flutter API integration tests
 * whose names start with 'ZZ ' (ghost records created before the v19 fix
 * that stored NULL status, making them invisible to get/list queries but
 * still present in the DB). Hard-deletes because the ghost products had
 * current_stock = 0 and no completed sales — no FK references exist.
 *
 * Idempotent — safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: cleanup API ghost test records...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Products ---
    $r = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($r && $r->fetch()) {
        // Include rows with status = NULL or '' (the ghost pattern) as well as valid names.
        $stmt = $pdo->prepare(
            "DELETE FROM products WHERE product_name LIKE 'ZZ %' AND (status IS NULL OR status = '' OR status NOT IN ('active','inactive','discontinued','draft','pending','approved'))"
        );
        $stmt->execute();
        echo "  · deleted " . $stmt->rowCount() . " ghost product(s) (name LIKE 'ZZ %' with invalid/NULL status).\n";
    } else {
        echo "  · products table not present — skipping.\n";
    }

    // --- Suppliers ---
    $r = $pdo->query("SHOW TABLES LIKE 'suppliers'");
    if ($r && $r->fetch()) {
        $stmt = $pdo->prepare(
            "DELETE FROM suppliers WHERE supplier_name LIKE 'ZZ %' AND (status IS NULL OR status = '')"
        );
        $stmt->execute();
        echo "  · deleted " . $stmt->rowCount() . " ghost supplier(s) (name LIKE 'ZZ %' with NULL/empty status).\n";
    } else {
        echo "  · suppliers table not present — skipping.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
