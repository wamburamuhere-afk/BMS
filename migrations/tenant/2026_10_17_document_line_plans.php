<?php
/**
 * migrations/tenant/2026_10_17_document_line_plans.php
 *
 * The platform plan sold on a Quotation / Sales Order / Invoice line
 * (service_plans_plan.md Phase 5): plan_key, plan_name, plan_price_id and, for
 * an add-on line, plan_addon_id + addon_quota + addon_units. Added to all three
 * line tables together, because convert_quote_to_order.php copies every
 * quotation-line column into sales_order_items. Nullable; idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
global $pdo;

echo "Starting tenant migration: document_line_plans...\n";
try {
    sptEnsurePlanLineColumns($pdo);
    echo "Migration complete: document_line_plans.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
