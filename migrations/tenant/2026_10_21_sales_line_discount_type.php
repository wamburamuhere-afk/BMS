<?php
/**
 * migrations/tenant/2026_10_21_sales_line_discount_type.php
 *
 * A Quotation / Sales Order / Invoice line discount can be a fixed amount of money
 * as well as a percentage (core/sales_line_discount.php): discount_type + the
 * discount_amount it stands for, on all three line tables together (quote → order
 * conversion copies every line column). Older lines get their amount from their
 * percentage. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/sales_line_discount.php';
global $pdo;

echo "Starting tenant migration: sales_line_discount_type...\n";
try {
    salesEnsureDiscountColumns($pdo);
    echo "Migration complete: sales_line_discount_type.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
