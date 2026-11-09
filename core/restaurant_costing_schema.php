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
 * Phase 3 — "Pika Leo" (cooking plan) and spoiled stock:
 *   restaurant_cook_plans       one cook of one dish on one day: pots, plates planned, the price and recipe cost at
 *                               the time (an expectation), what was taken from stock (posted), plates left at the end.
 *   restaurant_cook_plan_items  the stock taken for that cook (each line = one stock movement).
 *   restaurant_spoilage         stock thrown away (spoiled): its movement and its journal entry.
 *
 * Phase 4 — "the cook asks, the storekeeper gives": restaurant_cook_plan_items.status
 *   ENUM('issued','requested','rejected') DEFAULT 'issued' (+ requested_by, decided_by, decided_at). A cook without
 *   the stock right records a REQUEST; someone with it approves (stock leaves, cost posted) or rejects. Lines from
 *   before this phase were issued straight away, so they keep 'issued'.
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

if (!function_exists('rcsEnsureCookPlanTables')) {
    function rcsEnsureCookPlanTables(PDO $pdo, callable $log): void
    {
        $tables = [
            'restaurant_cook_plans' => "CREATE TABLE IF NOT EXISTS restaurant_cook_plans (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                warehouse_id     INT NOT NULL,
                product_id       INT NOT NULL,
                plan_date        DATE NOT NULL,
                pots             DECIMAL(10,2) NOT NULL DEFAULT 1,
                plates_planned   DECIMAL(10,2) NOT NULL DEFAULT 0,
                selling_price    DECIMAL(15,2) NOT NULL DEFAULT 0,
                recipe_cost      DECIMAL(15,2) NOT NULL DEFAULT 0,
                stock_cost       DECIMAL(15,2) NOT NULL DEFAULT 0,
                plates_left      DECIMAL(10,2) NULL,
                plates_sold      DECIMAL(10,2) NULL,
                status           ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
                journal_entry_id INT NULL,
                notes            VARCHAR(255) NULL,
                created_by       INT NULL,
                created_at       DATETIME NOT NULL,
                closed_by        INT NULL,
                closed_at        DATETIME NULL,
                cancelled_by     INT NULL,
                cancelled_at     DATETIME NULL,
                KEY idx_rcp_day (warehouse_id, plan_date),
                KEY idx_rcp_dish (product_id, plan_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'restaurant_cook_plan_items' => "CREATE TABLE IF NOT EXISTS restaurant_cook_plan_items (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                plan_id     INT NOT NULL,
                product_id  INT NOT NULL,
                quantity    DECIMAL(15,3) NOT NULL,
                unit        VARCHAR(20) NULL,
                unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0,
                amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
                movement_id INT NULL,
                KEY idx_rcpi_plan (plan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'restaurant_spoilage' => "CREATE TABLE IF NOT EXISTS restaurant_spoilage (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                warehouse_id     INT NOT NULL,
                product_id       INT NOT NULL,
                quantity         DECIMAL(15,3) NOT NULL,
                unit             VARCHAR(20) NULL,
                unit_cost        DECIMAL(15,4) NOT NULL DEFAULT 0,
                amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
                reason           VARCHAR(255) NULL,
                movement_id      INT NULL,
                journal_entry_id INT NULL,
                created_by       INT NULL,
                created_at       DATETIME NOT NULL,
                KEY idx_rsp_day (warehouse_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tables as $name => $sql) {
            if (rofsHasTable($pdo, $name)) { $log("  ~ $name already exists."); continue; }
            $pdo->exec($sql);
            $log("  + $name created.");
        }
    }
}

if (!function_exists('rcsEnsureCookRequests')) {
    function rcsEnsureCookRequests(PDO $pdo, callable $log): void
    {
        if (!rofsHasTable($pdo, 'restaurant_cook_plan_items')) { $log('  ~ restaurant_cook_plan_items absent (Phase 3 not run yet) — skipped.'); return; }
        $cols = [
            'status'       => "ENUM('issued','requested','rejected') NOT NULL DEFAULT 'issued'",
            'requested_by' => 'INT NULL',
            'decided_by'   => 'INT NULL',
            'decided_at'   => 'DATETIME NULL',
        ];
        foreach ($cols as $c => $def) {
            if (rofsHasColumn($pdo, 'restaurant_cook_plan_items', $c)) { $log("  ~ restaurant_cook_plan_items.$c already exists."); continue; }
            $pdo->exec("ALTER TABLE restaurant_cook_plan_items ADD COLUMN `$c` $def");
            $log("  + restaurant_cook_plan_items.$c added.");
        }
    }
}
