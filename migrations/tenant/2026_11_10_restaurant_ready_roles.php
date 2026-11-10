<?php
/**
 * migrations/tenant/2026_11_10_restaurant_ready_roles.php
 *
 * Ready-made restaurant roles, for a company with a Restaurant/Hybrid shop only:
 *  - create Waiter / Cashier / Cook / Restaurant Manager where missing (they are there by default now,
 *    not only after "Cashier must confirm orders" is turned on);
 *  - give OUR roles (same name AND our description) the rights added since they were made — the
 *    Restaurant Manager's stock right (pos_restock), so the manager can buy food and give out the stock
 *    a cook asks for. Rights are only added, never removed; a company's own roles are never touched.
 * Criteria-based (pos_mode, role name + description), never ids. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_order_flow_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant ready-made roles...\n";

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
