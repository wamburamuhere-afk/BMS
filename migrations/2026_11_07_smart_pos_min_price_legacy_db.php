<?php
/**
 * migrations/2026_11_07_smart_pos_min_price_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_07_smart_pos_min_price.php onto the LEGACY / non-tenant
 * database. Same body: core/pos_min_price.php. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/pos_min_price.php';
global $pdo;

echo "Starting migration: Smart POS minimum selling price (legacy database)...\n";
posMinPriceFixExisting($pdo, function (string $m) { echo $m . "\n"; });
echo "Migration complete.\n";
