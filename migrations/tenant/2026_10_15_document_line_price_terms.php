<?php
/**
 * migrations/tenant/2026_10_15_document_line_price_terms.php
 *
 * The price term chosen on a Quotation / Sales Order / Invoice line
 * (customer_subscription_plan.md Phase 2): price_term_id + a snapshot of its
 * label and length. Added to all three line tables together, because
 * convert_quote_to_order.php copies every quotation-line column into
 * sales_order_items. Nullable; idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
global $pdo;

echo "Starting tenant migration: document_line_price_terms...\n";
try {
    sptEnsureLineColumns($pdo);
    echo "Migration complete: document_line_price_terms.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
