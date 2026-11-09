<?php
/**
 * core/restaurant_cook_plan.php — "Pika Leo": the cooking plan (restaurant costing plan, Phase 3).
 *
 * Before selling: "I cook 2 pots of pilau today" → plates (pots × the recipe's plates per pot), what it costs,
 * the sales it brings if every plate sells, and the gross profit expected. An EXPECTATION: none of those
 * figures is posted. Two things here do touch stock and the books, each exactly once:
 *
 *   • Taken from stock for this cook (rice bought for several days, kept as stock): the quantity leaves the
 *     shop's stock (movement 'issue_out') and its cost moves Inventory → Cost of Sales the day it is cooked
 *     (journal entity 'restaurant_cook_cogs' — the profit card's "Drinks & stock used"). An ingredient that is
 *     IN the dish's recipe cannot be taken here: it already leaves stock with each plate sold, and taking it
 *     again would count it twice.
 *   • Spoiled stock: the quantity leaves stock (movement 'damaged') and its cost moves Inventory → Cost of
 *     Sales (journal entity 'restaurant_spoilage' — the card's "Spoiled & waste", the Waste report).
 *
 * At the end of the day the cook records the plates left; plates sold are read from the till for that dish
 * and day. Planned − sold − left = plates not accounted for (eaten by staff, given away, spilled).
 * A cook can be cancelled the same day while still open: its stock comes back and the entry is reversed.
 *
 * Permissions (existing ones, nothing new to configure): see the plan = Restaurant POS view; start / close a
 * cook = Restaurant POS edit; take from stock, spoil, or cancel a cook that took stock = the right that
 * already changes stock in Purchases (adjust_stock / pos_restock, admins); money figures = restaurant_costing.
 */
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/restaurant_purchases.php';   // restaurantStockItemSql(), restaurantCanBuy()
require_once __DIR__ . '/stock_ledger.php';
require_once __DIR__ . '/gl_accounts.php';
require_once __DIR__ . '/ledger_post.php';

if (!function_exists('rcpReady')) {
    /** The Phase 3 tables exist (company migrated)? */
    function rcpReady(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = true;
            foreach (['restaurant_cook_plans', 'restaurant_cook_plan_items', 'restaurant_spoilage'] as $t) {
                if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch()) { $ok = false; break; }
            }
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('rcpCanMoveStock')) {
    /** Taking from stock / spoiling: the same right that changes stock in Purchases. */
    function rcpCanMoveStock(): bool
    {
        return restaurantCanBuy();
    }
}

if (!function_exists('rcpShopStock')) {
    function rcpShopStock(PDO $pdo, int $warehouseId, int $productId): float
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(stock_quantity), 0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
        $st->execute([$productId, $warehouseId]);
        return (float)$st->fetchColumn();
    }
}

if (!function_exists('rcpDish')) {
    /**
     * A dish of this shop's Menu, with what planning needs: plates per pot, cost of one plate (ingredients at
     * today's cost + its share of the other costs), and the recipe's ingredients (they leave stock as plates sell).
     */
    function rcpDish(PDO $pdo, int $warehouseId, int $productId): ?array
    {
        $extraReady = restaurantExtraCostReady($pdo);
        $cols = $extraReady ? ', COALESCE(p.recipe_yield, 1) AS yield, COALESCE(p.recipe_extra_cost, 0) AS extra' : ', 1 AS yield, 0 AS extra';
        $st = $pdo->prepare("SELECT p.product_id, p.product_name, COALESCE(p.selling_price, 0) AS selling_price, COALESCE(p.is_combo, 0) AS is_combo $cols
                               FROM products p
                              WHERE p.product_id = ? AND p.is_menu_item = 1 AND p.is_service = 1 AND p.status = 'active'
                                AND (p.warehouse_id = ? OR p.warehouse_id IS NULL)");
        $st->execute([$productId, $warehouseId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return null;
        $c = $pdo->prepare("SELECT ac.component_product_id AS product_id, p.product_name, COALESCE(NULLIF(ac.unit,''), NULLIF(p.unit,''), 'pcs') AS unit,
                                   ac.qty_per_unit, COALESCE(p.cost_price, 0) AS cost_price
                              FROM product_assembly_components ac JOIN products p ON p.product_id = ac.component_product_id
                             WHERE ac.parent_product_id = ? ORDER BY p.product_name");
        $c->execute([$productId]);
        $comps = [];
        $ing = 0.0;
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $q = (float)$r['qty_per_unit']; $cost = (float)$r['cost_price'];
            $ing += $q * $cost;
            $comps[] = ['product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'unit' => $r['unit'], 'qty_per_plate' => $q,
                        'cost_price' => $cost, 'stock' => rcpShopStock($pdo, $warehouseId, (int)$r['product_id'])];
        }
        $yield = max(1, (int)$d['yield']);
        $extra = (float)$d['extra'];
        return [
            'product_id' => (int)$d['product_id'], 'name' => $d['product_name'], 'selling_price' => (float)$d['selling_price'],
            'yield' => $yield, 'extra' => $extra, 'is_combo' => (int)$d['is_combo'] === 1,
            'plate_cost' => round($ing + $extra / $yield, 4), 'has_recipe' => $comps || $extra > 0, 'components' => $comps,
        ];
    }
}

if (!function_exists('rcpDishes')) {
    /** Every dish of the shop's Menu (for the picker), with plates per pot and whether its cost is known. */
    function rcpDishes(PDO $pdo, int $warehouseId): array
    {
        $extraPP = restaurantExtraPerPlateSql($pdo, 'p');
        $yield   = restaurantExtraCostReady($pdo) ? 'COALESCE(p.recipe_yield, 1)' : '1';
        $st = $pdo->prepare("SELECT p.product_id, p.product_name, COALESCE(p.selling_price, 0) AS selling_price, $yield AS yield,
                                    (SELECT COALESCE(SUM(ac.qty_per_unit * COALESCE(cp.cost_price, 0)), 0) FROM product_assembly_components ac
                                       JOIN products cp ON cp.product_id = ac.component_product_id WHERE ac.parent_product_id = p.product_id) + $extraPP AS plate_cost,
                                    " . restaurantDishHasCostSql($pdo, 'p') . " AS has_recipe
                               FROM products p
                              WHERE p.is_menu_item = 1 AND p.is_service = 1 AND p.status = 'active' AND (p.warehouse_id = ? OR p.warehouse_id IS NULL)
                           ORDER BY p.product_name");
        $st->execute([$warehouseId]);
        return array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'selling_price' => (float)$r['selling_price'],
                                    'yield' => max(1, (int)$r['yield']), 'plate_cost' => round((float)$r['plate_cost'], 4), 'has_recipe' => (bool)$r['has_recipe']],
                         $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('rcpStockItems')) {
    /** Counted items of this shop with stock on hand (rice, oil… kept for several days), minus $exclude. */
    function rcpStockItems(PDO $pdo, int $warehouseId, array $exclude = []): array
    {
        $ex = array_values(array_filter(array_map('intval', $exclude)));
        $not = $ex ? ' AND p.product_id NOT IN (' . implode(',', $ex) . ')' : '';
        $st = $pdo->prepare("SELECT p.product_id, p.product_name, COALESCE(NULLIF(p.unit,''), NULLIF(p.unit_of_measure,''), 'pcs') AS unit,
                                    COALESCE(p.cost_price, 0) AS cost_price, COALESCE(SUM(ps.stock_quantity), 0) AS stock
                               FROM products p JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id = ?
                              WHERE " . restaurantStockItemSql('p') . $not . "
                           GROUP BY p.product_id, p.product_name, p.unit, p.unit_of_measure, p.cost_price
                             HAVING stock > 0
                           ORDER BY p.product_name");
        $st->execute([$warehouseId]);
        return array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'unit' => $r['unit'],
                                    'cost_price' => (float)$r['cost_price'], 'stock' => round((float)$r['stock'], 3)], $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('rcpLastLines')) {
    /** What was taken from stock PER POT the last time this dish was cooked here — to fill the form again. */
    function rcpLastLines(PDO $pdo, int $warehouseId, int $productId): array
    {
        $st = $pdo->prepare("SELECT id, pots FROM restaurant_cook_plans WHERE warehouse_id = ? AND product_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
        $st->execute([$warehouseId, $productId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (float)$p['pots'] <= 0) return [];
        $it = $pdo->prepare("SELECT product_id, quantity FROM restaurant_cook_plan_items WHERE plan_id = ?");
        $it->execute([(int)$p['id']]);
        return array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'qty_per_pot' => round((float)$r['quantity'] / (float)$p['pots'], 3)],
                         $it->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('rcpSoldOn')) {
    /** Plates of this dish the till sold in this shop on this day (completed sales, returns left out). */
    function rcpSoldOn(PDO $pdo, int $warehouseId, int $productId, string $date): float
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(psi.quantity), 0) FROM pos_sale_items psi JOIN pos_sales ps ON ps.sale_id = psi.sale_id
                              WHERE ps.sale_status IN ('completed','partially_refunded') AND ps.is_return_sale = 0
                                AND ps.warehouse_id = ? AND psi.product_id = ? AND DATE(ps.sale_date) = ?");
        $st->execute([$warehouseId, $productId, $date]);
        return round((float)$st->fetchColumn(), 2);
    }
}

if (!function_exists('rcpCostAccounts')) {
    /** [Cost of Sales, Inventory] — the fixed accounts every stock-out of the restaurant uses. */
    function rcpCostAccounts(PDO $pdo): array
    {
        $cogs = (int)cogsAccountId($pdo); $inv = (int)inventoryAccountId($pdo);
        if ($cogs <= 0 || $inv <= 0) throw new Exception(t('Inventory account is not configured — contact your administrator before restocking.'));
        return [$cogs, $inv];
    }
}

if (!function_exists('rcpStockOut')) {
    /** Take $qty of a counted item out of this shop's stock; returns the movement id. Inside the caller's transaction. */
    function rcpStockOut(PDO $pdo, int $warehouseId, int $productId, float $qty, float $unitCost, string $type, string $ref, int $refId, string $notes, int $uid, string $unit): int
    {
        $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ?, current_stock = current_stock - ? WHERE product_id = ?")->execute([$qty, $qty, $productId]);
        $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity, 0) - ? WHERE product_id = ? AND warehouse_id = ?")->execute([$qty, $productId, $warehouseId]);
        return recordStockMovement($pdo, [
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'movement_type' => $type, 'quantity' => $qty, 'unit' => $unit,
            'unit_cost' => $unitCost, 'reference_id' => $refId, 'reference_type' => 'manual', 'reference_number' => $ref,
            'created_by' => $uid, 'notes' => mb_substr($notes, 0, 250),
        ]);
    }
}

if (!function_exists('rcpCountedItem')) {
    /** A counted item (restaurantStockItemSql) by id, or null. */
    function rcpCountedItem(PDO $pdo, int $productId): ?array
    {
        $st = $pdo->prepare("SELECT p.product_id, p.product_name, COALESCE(p.cost_price, 0) AS cost_price,
                                    COALESCE(NULLIF(p.unit,''), NULLIF(p.unit_of_measure,''), 'pcs') AS unit
                               FROM products p WHERE p.product_id = ? AND " . restaurantStockItemSql('p'));
        $st->execute([$productId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('rcpCreate')) {
    /**
     * Start cooking: a plan for today, and (optionally) what is taken from stock for it.
     * @param array $in warehouse_id, product_id, pots, lines=[[product_id, qty], …] (whole cook amounts), [notes]
     * Throws Exception with a user-facing message; changes nothing then.
     */
    function rcpCreate(PDO $pdo, array $in, int $uid): array
    {
        $wh   = (int)($in['warehouse_id'] ?? 0);
        $pid  = (int)($in['product_id'] ?? 0);
        $pots = round((float)($in['pots'] ?? 0), 2);
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255) ?: null;
        if (!restaurantShopActive($pdo, $wh)) throw new Exception(t('This shop is not set up as a restaurant.'));
        $dish = rcpDish($pdo, $wh, $pid);
        if (!$dish) throw new Exception(t('Choose a dish from the Menu.'));
        if ($pots <= 0 || $pots > 100) throw new Exception(t('Enter how many pots you cook (more than 0, up to 100).'));
        $plates = round($pots * $dish['yield'], 2);

        $compIds = array_column($dish['components'], 'product_id');
        $clean = [];
        foreach ((array)($in['lines'] ?? []) as $l) {
            $lp = (int)($l['product_id'] ?? 0); $lq = round((float)($l['qty'] ?? 0), 3);
            if ($lp <= 0 || $lq <= 0) continue;
            $clean[$lp] = round(($clean[$lp] ?? 0) + $lq, 3);
        }
        if ($clean && !rcpCanMoveStock()) throw new Exception(t('Only someone who may change stock (Purchases) can take items from stock.'));
        $items = [];
        foreach ($clean as $lp => $lq) {
            $item = rcpCountedItem($pdo, $lp);
            if (!$item) throw new Exception(t('Pick an item you count (rice, oil…).'));
            if (in_array($lp, $compIds, true)) {
                throw new Exception(sprintf(t('%s is in this dish\'s recipe: it already leaves stock with each plate sold. Do not take it again here.'), $item['product_name']));
            }
            $have = rcpShopStock($pdo, $wh, $lp);
            if ($lq > $have + 0.0005) {
                throw new Exception(sprintf(t('Only %1$s %2$s of %3$s left in stock.'), rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.'), $item['unit'], $item['product_name']));
            }
            $items[] = $item + ['qty' => $lq];
        }
        $recipeCost = round($dish['plate_cost'] * $plates, 2);   // ingredients' share × plates + other costs × pots — an estimate
        $accounts = $items ? rcpCostAccounts($pdo) : null;
        $date = date('Y-m-d');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO restaurant_cook_plans (warehouse_id, product_id, plan_date, pots, plates_planned, selling_price, recipe_cost, stock_cost, status, notes, created_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'open', ?, ?, NOW())")
                ->execute([$wh, $pid, $date, $pots, $plates, $dish['selling_price'], $recipeCost, $notes, $uid]);
            $id = (int)$pdo->lastInsertId();
            $ref = 'COOK-' . $id;
            $stockCost = 0.0;
            $ins = $pdo->prepare("INSERT INTO restaurant_cook_plan_items (plan_id, product_id, quantity, unit, unit_cost, amount, movement_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $it) {
                $uc = (float)$it['cost_price']; $amt = round($it['qty'] * $uc, 2);
                $mv = rcpStockOut($pdo, $wh, (int)$it['product_id'], $it['qty'], $uc, 'issue_out', $ref, $id, "Cooking: {$dish['name']} — taken from stock", $uid, $it['unit']);
                $ins->execute([$id, (int)$it['product_id'], $it['qty'], $it['unit'], $uc, $amt, $mv]);
                $stockCost += $amt;
            }
            $stockCost = round($stockCost, 2);
            $entry = null;
            if ($stockCost > 0) {
                [$cogs, $inv] = $accounts;
                $entry = postLedgerEntry($pdo, "Cooking $ref — {$dish['name']} × " . rtrim(rtrim(number_format($pots, 2, '.', ''), '0'), '.') . ' pot(s): stock used', [
                    ['account_id' => $cogs, 'type' => 'debit',  'amount' => $stockCost, 'description' => 'Stock used for cooking'],
                    ['account_id' => $inv,  'type' => 'credit', 'amount' => $stockCost, 'description' => 'Stock used for cooking'],
                ], null, $id, 'restaurant_cook_cogs', $date, $uid, $wh);
            }
            $pdo->prepare("UPDATE restaurant_cook_plans SET stock_cost = ?, journal_entry_id = ? WHERE id = ?")->execute([$stockCost, $entry, $id]);
            logActivity($pdo, $uid, "Cooking plan $ref: {$dish['name']} × $pots pot(s) = $plates plate(s)" . ($stockCost > 0 ? ", stock used $stockCost" : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'plates' => $plates, 'recipe_cost' => $recipeCost, 'stock_cost' => $stockCost];
    }
}

if (!function_exists('rcpGet')) {
    function rcpGet(PDO $pdo, int $planId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM restaurant_cook_plans WHERE id = ?");
        $st->execute([$planId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('rcpClose')) {
    /** End of the day: plates left (can be corrected the same day). Sold is read from the till for that dish and day. */
    function rcpClose(PDO $pdo, int $planId, float $left, int $uid): array
    {
        $p = rcpGet($pdo, $planId);
        if (!$p || $p['status'] === 'cancelled') throw new Exception(t('This cooking plan was not found.'));
        if (!userCan('warehouse', (int)$p['warehouse_id'])) throw new Exception(wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.'));
        if ($p['status'] === 'closed' && $p['plan_date'] !== date('Y-m-d')) throw new Exception(t('Only today\'s cooking can be changed.'));
        $left = round($left, 2);
        if ($left < 0 || $left > (float)$p['plates_planned'] + 0.004) throw new Exception(sprintf(t('Plates left must be between 0 and %s.'), rtrim(rtrim(number_format((float)$p['plates_planned'], 2, '.', ''), '0'), '.')));
        $sold = rcpSoldOn($pdo, (int)$p['warehouse_id'], (int)$p['product_id'], (string)$p['plan_date']);
        $pdo->prepare("UPDATE restaurant_cook_plans SET plates_left = ?, plates_sold = ?, status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?")
            ->execute([$left, $sold, $uid, $planId]);
        logActivity($pdo, $uid, "Cooking plan COOK-$planId closed: planned {$p['plates_planned']}, sold $sold, left $left");
        return ['planned' => (float)$p['plates_planned'], 'sold' => $sold, 'left' => $left, 'unaccounted' => round((float)$p['plates_planned'] - $sold - $left, 2)];
    }
}

if (!function_exists('rcpCancel')) {
    /** Cancel today's open cook (a mistake): stock taken comes back, its entry is reversed. */
    function rcpCancel(PDO $pdo, int $planId, int $uid): void
    {
        $p = rcpGet($pdo, $planId);
        if (!$p || $p['status'] === 'cancelled') throw new Exception(t('This cooking plan was not found.'));
        if (!userCan('warehouse', (int)$p['warehouse_id'])) throw new Exception(wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.'));
        if ($p['status'] !== 'open' || $p['plan_date'] !== date('Y-m-d')) throw new Exception(t('Only today\'s cooking that is still open can be cancelled.'));
        $it = $pdo->prepare("SELECT i.*, p.product_name FROM restaurant_cook_plan_items i JOIN products p ON p.product_id = i.product_id WHERE i.plan_id = ?");
        $it->execute([$planId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
        if ($items && !rcpCanMoveStock()) throw new Exception(t('Only someone who may change stock (Purchases) can cancel cooking that took items from stock.'));
        $wh = (int)$p['warehouse_id']; $ref = 'COOK-' . $planId;
        $pdo->beginTransaction();
        try {
            foreach ($items as $i) {
                $q = (float)$i['quantity'];
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, current_stock = current_stock + ? WHERE product_id = ?")->execute([$q, $q, (int)$i['product_id']]);
                $pdo->prepare("UPDATE product_stocks SET stock_quantity = IFNULL(stock_quantity, 0) + ? WHERE product_id = ? AND warehouse_id = ?")->execute([$q, (int)$i['product_id'], $wh]);
                recordStockMovement($pdo, [
                    'product_id' => (int)$i['product_id'], 'warehouse_id' => $wh, 'movement_type' => 'return_in', 'quantity' => $q, 'unit' => $i['unit'] ?: 'pcs',
                    'unit_cost' => (float)$i['unit_cost'], 'reference_id' => $planId, 'reference_type' => 'manual', 'reference_number' => $ref,
                    'created_by' => $uid, 'notes' => 'Cooking cancelled — back to stock',
                ]);
            }
            if ((float)$p['stock_cost'] > 0 && !empty($p['journal_entry_id'])) {
                [$cogs, $inv] = rcpCostAccounts($pdo);
                $amt = round((float)$p['stock_cost'], 2);
                postLedgerEntry($pdo, "Cooking $ref cancelled — stock back", [
                    ['account_id' => $inv,  'type' => 'debit',  'amount' => $amt, 'description' => 'Cooking cancelled'],
                    ['account_id' => $cogs, 'type' => 'credit', 'amount' => $amt, 'description' => 'Cooking cancelled'],
                ], null, $planId, 'restaurant_cook_cogs_void', date('Y-m-d'), $uid, $wh);
            }
            $pdo->prepare("UPDATE restaurant_cook_plans SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW() WHERE id = ?")->execute([$uid, $planId]);
            logActivity($pdo, $uid, "Cooking plan $ref cancelled" . ($items ? ' — stock returned' : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('rcpSpoil')) {
    /**
     * Spoiled stock: it leaves stock and its cost goes to Cost of Sales as waste (entity 'restaurant_spoilage').
     * @param array $in warehouse_id, product_id, qty, [reason]
     */
    function rcpSpoil(PDO $pdo, array $in, int $uid): array
    {
        $wh = (int)($in['warehouse_id'] ?? 0); $pid = (int)($in['product_id'] ?? 0); $qty = round((float)($in['qty'] ?? 0), 3);
        $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 255) ?: null;
        if (!rcpCanMoveStock()) throw new Exception(t('Only someone who may change stock (Purchases) can record spoiled stock.'));
        if (!restaurantShopActive($pdo, $wh)) throw new Exception(t('This shop is not set up as a restaurant.'));
        $item = rcpCountedItem($pdo, $pid);
        if (!$item) throw new Exception(t('Pick an item you count (rice, oil…).'));
        if ($qty <= 0) throw new Exception(t('Quantity must be greater than zero.'));
        $have = rcpShopStock($pdo, $wh, $pid);
        if ($qty > $have + 0.0005) throw new Exception(sprintf(t('Only %1$s %2$s of %3$s left in stock.'), rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.'), $item['unit'], $item['product_name']));
        $uc = (float)$item['cost_price']; $amt = round($qty * $uc, 2);
        $accounts = $amt > 0 ? rcpCostAccounts($pdo) : null;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO restaurant_spoilage (warehouse_id, product_id, quantity, unit, unit_cost, amount, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$wh, $pid, $qty, $item['unit'], $uc, $amt, $reason, $uid]);
            $id = (int)$pdo->lastInsertId();
            $ref = 'SPOIL-' . $id;
            $mv = rcpStockOut($pdo, $wh, $pid, $qty, $uc, 'damaged', $ref, $id, "Spoiled: {$item['product_name']}" . ($reason ? " — $reason" : ''), $uid, $item['unit']);
            $entry = null;
            if ($amt > 0) {
                [$cogs, $inv] = $accounts;
                $entry = postLedgerEntry($pdo, "Spoiled stock $ref — {$item['product_name']} × " . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . " {$item['unit']}", [
                    ['account_id' => $cogs, 'type' => 'debit',  'amount' => $amt, 'description' => 'Spoiled stock'],
                    ['account_id' => $inv,  'type' => 'credit', 'amount' => $amt, 'description' => 'Spoiled stock'],
                ], null, $id, 'restaurant_spoilage', date('Y-m-d'), $uid, $wh);
            }
            $pdo->prepare("UPDATE restaurant_spoilage SET movement_id = ?, journal_entry_id = ? WHERE id = ?")->execute([$mv, $entry, $id]);
            logActivity($pdo, $uid, "Spoiled stock $ref: {$item['product_name']} × $qty {$item['unit']} (cost $amt)" . ($reason ? " — $reason" : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'amount' => $amt, 'left' => round($have - $qty, 3), 'unit' => $item['unit'], 'name' => $item['product_name']];
    }
}

if (!function_exists('rcpDay')) {
    /**
     * The cooks of one shop on one day (cancelled ones left out), with what the till sold of each dish.
     * Sold is per DISH and day: two cooks of the same dish share it (shown on each, counted once in totals).
     */
    function rcpDay(PDO $pdo, int $warehouseId, string $date): array
    {
        $st = $pdo->prepare("SELECT c.*, p.product_name FROM restaurant_cook_plans c JOIN products p ON p.product_id = c.product_id
                              WHERE c.warehouse_id = ? AND c.plan_date = ? AND c.status <> 'cancelled' ORDER BY c.id");
        $st->execute([$warehouseId, $date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sold = [];
        $items = $pdo->prepare("SELECT i.product_id, p.product_name, i.quantity, i.unit, i.amount FROM restaurant_cook_plan_items i JOIN products p ON p.product_id = i.product_id WHERE i.plan_id = ?");
        foreach ($rows as &$r) {
            $pid = (int)$r['product_id'];
            if (!isset($sold[$pid])) $sold[$pid] = rcpSoldOn($pdo, $warehouseId, $pid, $date);
            $items->execute([(int)$r['id']]);
            $r = [
                'id' => (int)$r['id'], 'product_id' => $pid, 'name' => $r['product_name'], 'pots' => (float)$r['pots'],
                'plates_planned' => (float)$r['plates_planned'], 'selling_price' => (float)$r['selling_price'],
                'recipe_cost' => (float)$r['recipe_cost'], 'stock_cost' => (float)$r['stock_cost'],
                'sold' => $sold[$pid], 'plates_left' => $r['plates_left'] === null ? null : (float)$r['plates_left'],
                'status' => $r['status'], 'created_at' => $r['created_at'],
                'items' => array_map(fn($i) => ['name' => $i['product_name'], 'qty' => (float)$i['quantity'], 'unit' => $i['unit'], 'amount' => (float)$i['amount']], $items->fetchAll(PDO::FETCH_ASSOC)),
            ];
        }
        unset($r);
        return $rows;
    }
}
