<?php
/**
 * migrations/2026_11_10_restaurant_ready_roles_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_10_restaurant_ready_roles.php onto the LEGACY / non-tenant database:
 * ready-made restaurant roles where a Restaurant/Hybrid shop exists, and the rights added to them since
 * (Restaurant Manager → pos_restock). Criteria-based, only adds rights, idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/restaurant_order_flow_schema.php';
global $pdo;

echo "Starting migration: restaurant ready-made roles (legacy database)...\n";

try {
    $log = function (string $m) { echo $m . "\n"; };
    if (rofsHasRestaurantShop($pdo)) {
        $made = rofsEnsureReadyRoles($pdo, $log);
        if (!$made) echo "  ~ ready-made restaurant roles: nothing new.\n";
        $up = rofsUpgradeReadyRoles($pdo, $log);
        if (!$up) echo "  ~ ready-made restaurant roles: rights already up to date.\n";
    } else {
        echo "  ~ no Restaurant/Hybrid shop — nothing to do.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
