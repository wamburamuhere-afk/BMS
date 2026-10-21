<?php
/**
 * migrations/2026_10_21_sales_line_discount_type_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_21_sales_line_discount_type.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/sales_line_discount.php';
global $pdo;

echo "Starting legacy migration: sales_line_discount_type...\n";
try {
    salesEnsureDiscountColumns($pdo);
    echo "Migration complete: sales_line_discount_type.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
