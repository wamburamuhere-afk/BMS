<?php
/**
 * migrations/2026_10_15_service_price_terms_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_15_service_price_terms.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/service_price_terms.php';
global $pdo;

echo "Starting legacy migration: service_price_terms...\n";
try {
    sptEnsureSchema($pdo);
    echo "Migration complete: service_price_terms.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
