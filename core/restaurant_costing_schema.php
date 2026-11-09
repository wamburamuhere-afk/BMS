<?php
/**
 * core/restaurant_costing_schema.php — schema + permission for restaurant costing
 * (restaurant costing plan, 2026-11-09: "how many plates will this pot give, and what will it earn?").
 *
 * Phase 1 — permission row `restaurant_costing` ("See costs and profit"): who may see buying prices,
 * recipe costs and gross profit in the restaurant screens. Before this, anyone who could open the
 * Menu (restaurant_pos view — every waiter and cashier) saw what each plate earns.
 *   Back-compat grant, once, when the row is first created: every role that can EDIT restaurant_pos
 *   (owners' managers — they set prices and recipes) gets view. View-only roles (waiter, cashier,
 *   cook) do not. Admins always pass (isAdmin() bypass). Nothing an admin later changes is undone.
 *
 * Phase 2 — products.recipe_extra_cost DECIMAL(15,2) NOT NULL DEFAULT 0: "other costs" of a dish's recipe,
 *   for the WHOLE pot (recipe_yield plates). Things not kept as stock (tomatoes, spices, salt), or — for a
 *   small restaurant that keeps no stock at all — the whole pot's cost ("this pot costs me 30,000").
 *   Only an estimate input: nothing is posted from it (real food cost stays what Purchases records), so
 *   it never counts twice in the books. One plate's cost = ingredients' share + extra ÷ plates.
 *
 * Dependency-free (only PDO): shared by the tenant and legacy migrations. Reuses the permission
 * helpers of core/restaurant_order_flow_schema.php (rofsHasTable / rofsPermissionId / rofsGrant).
 */

require_once __DIR__ . '/restaurant_order_flow_schema.php';

if (!function_exists('rcsEnsureCostingPermission')) {
    function rcsEnsureCostingPermission(PDO $pdo, callable $log): void
    {
        if (!rofsHasTable($pdo, 'permissions') || !rofsHasTable($pdo, 'role_permissions')) {
            $log('  ~ no permissions tables — skipped.');
            return;
        }
        if (rofsPermissionId($pdo, 'restaurant_costing')) {
            $log("  ~ permission 'restaurant_costing' already present.");
            return;
        }
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                       VALUES ('', 'restaurant_costing', 'Restaurant Costs & Profit', ?, 'Settings', 0, NOW())")
            ->execute(['See buying prices, recipe costs and gross profit on the restaurant Menu, recipes and reports (view). Without it, staff see selling prices only.']);
        $log("  + permission 'restaurant_costing' created.");

        // Back-compat, only now that the row is new: whoever can edit the restaurant menu keeps seeing costs.
        $costId = rofsPermissionId($pdo, 'restaurant_costing');
        $rp     = rofsPermissionId($pdo, 'restaurant_pos');
        if ($costId && $rp) {
            $st = $pdo->prepare("SELECT DISTINCT role_id FROM role_permissions WHERE permission_id = ? AND can_edit = 1");
            $st->execute([$rp]);
            $n = 0;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) { rofsGrant($pdo, (int)$rid, $costId, ['view']); $n++; }
            $log("  + restaurant_costing view given to $n role(s) that can edit Restaurant POS.");
        }
    }
}

if (!function_exists('rcsEnsureExtraCostColumn')) {
    function rcsEnsureExtraCostColumn(PDO $pdo, callable $log): void
    {
        if (!rofsHasTable($pdo, 'products')) { $log('  ~ products table absent — nothing to do.'); return; }
        if (rofsHasColumn($pdo, 'products', 'recipe_extra_cost')) { $log('  ~ products.recipe_extra_cost already exists.'); return; }
        $pdo->exec("ALTER TABLE products ADD COLUMN `recipe_extra_cost` DECIMAL(15,2) NOT NULL DEFAULT 0");
        $log('  + products.recipe_extra_cost added (default 0).');
    }
}
