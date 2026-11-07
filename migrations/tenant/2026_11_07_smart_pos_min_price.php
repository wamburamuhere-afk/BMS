<?php
/**
 * migrations/tenant/2026_11_07_smart_pos_min_price.php
 *
 * Smart POS has no "Minimum selling price": on a Simple Mode tenant, every product's minimum
 * becomes its buying price (it used to be auto-filled with the selling price, which blocked
 * discounts and wholesale sales). Logic in core/pos_min_price.php. Idempotent; a tenant that is
 * not in Simple Mode is left untouched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/pos_min_price.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: Smart POS minimum selling price...\n";
posMinPriceFixExisting($pdo, function (string $m) { echo $m . "\n"; });
echo "Migration complete.\n";
