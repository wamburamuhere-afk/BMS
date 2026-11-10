<?php
/**
 * core/restaurant_ready_roles.php — the ready-made restaurant roles (Waiter, Cashier, Cook, Restaurant Manager)
 * belong to Smart POS companies with a Restaurant (or Hybrid) shop, and only there.
 *
 *  - restaurantReadyRolesWanted()  Smart POS + restaurant module on + at least one shop in Restaurant/Hybrid mode.
 *  - restaurantReadyRolesSync()    when wanted: create any that are missing and add rights given to them since
 *                                  (called by the Users / Roles pages and when a shop is saved) — "by default",
 *                                  no switch to turn on first.
 *  - restaurantReadyRolesHideSql() when NOT wanted (Smart POS retail, Smart BMS): a WHERE fragment that leaves
 *                                  OUR four roles out of role lists and pickers — matched by name AND our own
 *                                  description, and only while no user holds them, so a company's own "Cashier"
 *                                  and anyone already given one of them are never affected.
 */
require_once __DIR__ . '/restaurant_order_flow_schema.php';

if (!function_exists('restaurantReadyRolesWanted')) {
    function restaurantReadyRolesWanted(PDO $pdo): bool
    {
        static $cache = [];
        $k = spl_object_id($pdo);
        if (isset($cache[$k])) return $cache[$k];
        try {
            require_once __DIR__ . '/restaurant_scope.php';
            return $cache[$k] = restaurantModuleOn($pdo) && rofsHasRestaurantShop($pdo);
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}

if (!function_exists('restaurantReadyRolesSync')) {
    /** Returns the role names created now. Never throws: a page must still load if this fails. */
    function restaurantReadyRolesSync(PDO $pdo, bool $fresh = false): array
    {
        static $done = [];
        $k = spl_object_id($pdo);
        if (isset($done[$k]) && !$fresh) return [];
        $done[$k] = true;
        try {
            if ($fresh) {
                require_once __DIR__ . '/restaurant_scope.php';
                if (!(restaurantModuleOn($pdo) && rofsHasRestaurantShop($pdo))) return [];
            } elseif (!restaurantReadyRolesWanted($pdo)) {
                return [];
            }
            $quiet = function ($m) {};
            $made = rofsEnsureReadyRoles($pdo, $quiet);
            $upgraded = rofsUpgradeReadyRoles($pdo, $quiet);
            // Done by the system while this person had the page open — logged under them so "who" is never blank.
            if (function_exists('logActivity')) {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if ($made) {
                    logActivity($pdo, $uid, 'Restaurant: ready-made roles added', 'Created automatically for a restaurant shop: ' . implode(', ', $made));
                }
                if ($upgraded) {
                    logActivity($pdo, $uid, 'Restaurant: ready-made role rights added', 'Added automatically: ' . implode('; ', $upgraded));
                }
            }
            return $made;
        } catch (Throwable $e) {
            error_log('restaurantReadyRolesSync: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('restaurantReadyRolesHideSql')) {
    /**
     * " AND NOT (...)" for a query over `roles` aliased $alias — or '' when the roles belong here.
     * Only plain SQL literals from our own definitions go in (names and descriptions are quoted by PDO).
     */
    function restaurantReadyRolesHideSql(PDO $pdo, string $alias = 'roles'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) return '';
        if (restaurantReadyRolesWanted($pdo)) return '';
        $pairs = [];
        foreach (rofsReadyRoles() as $name => [$desc]) {
            $pairs[] = "($alias.role_name = " . $pdo->quote($name) . " AND $alias.description = " . $pdo->quote($desc) . ')';
        }
        if (!$pairs) return '';
        return " AND NOT ((" . implode(' OR ', $pairs) . ") AND NOT EXISTS (SELECT 1 FROM users ru WHERE ru.role_id = $alias.role_id))";
    }
}
