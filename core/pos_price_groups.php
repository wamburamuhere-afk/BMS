<?php
/**
 * core/pos_price_groups.php
 *
 * Phase 14 (pos_upgrade_plan.md §8) — selling price tiers (Retail/Wholesale/
 * Custom). Extracted out of api/pos/process_sale.php and
 * api/pos/simple_products.php so the resolution logic is independently
 * unit-testable, same reasoning as core/pos_override_guard.php (Phase 16).
 *
 * A price group is a SPARSE list of per-product overrides
 * (product_price_group_prices) — a product with no row for a given group
 * simply falls back to products.selling_price. Groups never replace
 * selling_price; they only override it, per-product, per-group.
 */

if (!function_exists('wholesalePriceGroupId')) {
    /**
     * The 'Wholesale' price_groups row seeded by the Phase 14 migration.
     * Returns null when price groups aren't set up on this tenant yet (older,
     * un-migrated database) — callers treat that as "no wholesale tier
     * available", same leniency pettyCashFunds() uses for its own table.
     */
    function wholesalePriceGroupId(PDO $pdo): ?int
    {
        try {
            $id = $pdo->query("SELECT price_group_id FROM price_groups WHERE name = 'Wholesale' LIMIT 1")->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

/**
 * Resolve the effective price for a set of products under one price group,
 * with an active promotional price (Phase 25) taking priority over the
 * group override, which in turn takes priority over plain selling_price.
 *
 * @param PDO   $pdo
 * @param int   $priceGroupId  0/absent = no group override layer, but an
 *                              active promo (if any) still applies — only
 *                              the price-group lookup is skipped.
 * @param array $productIds    product_ids to resolve (may include ids with
 *                              no override — they're simply absent from the
 *                              returned map).
 * @return array<int,float> product_id => resolved price, only for products
 *                           that have either an active promo or a row in
 *                           this group. Callers fall back to plain
 *                           selling_price for any product absent here
 *                           (identical to pre-Phase-14/25 behaviour).
 */
function resolveGroupPrices(PDO $pdo, int $priceGroupId, array $productIds): array
{
    if (empty($productIds)) {
        return [];
    }

    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    $result = [];

    if ($priceGroupId > 0) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare("
            SELECT product_id, price
            FROM product_price_group_prices
            WHERE price_group_id = ? AND product_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$priceGroupId], $productIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['product_id']] = (float)$row['price'];
        }
    }

    // Phase 25 (pos_upgrade_plan.md §9) — an active, in-window promo price
    // wins over the price-group tier (and over plain selling_price when no
    // group override exists), so this merge runs last.
    foreach (resolveActivePromoPrices($pdo, $productIds) as $pid => $price) {
        $result[$pid] = $price;
    }

    return $result;
}

/**
 * Resolve currently-active, in-window promotional prices (Phase 25).
 * If more than one active promo row overlaps the same product's window
 * (not prevented by schema — a deliberately accepted edge case), the
 * lowest price wins, chosen deterministically via MIN() rather than
 * leaving it to arbitrary row order.
 *
 * @param PDO   $pdo
 * @param array $productIds
 * @return array<int,float> product_id => active promo price, only for
 *                           products with a currently-active promotion.
 */
function resolveActivePromoPrices(PDO $pdo, array $productIds): array
{
    if (empty($productIds)) {
        return [];
    }

    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $stmt = $pdo->prepare("
        SELECT product_id, MIN(price) AS price
        FROM product_promotions
        WHERE status = 'active'
          AND starts_at <= NOW() AND ends_at >= NOW()
          AND product_id IN ($placeholders)
        GROUP BY product_id
    ");
    $stmt->execute($productIds);

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int)$row['product_id']] = (float)$row['price'];
    }
    return $result;
}

if (!function_exists('effectiveWholesalePrice')) {
    /**
     * The wholesale price POS really charges for a product: its Wholesale
     * price-group override when one exists, else the legacy
     * products.wholesale_price (> 0) as entered on the product form, else null.
     * Edit forms prefill from this so a save never writes a stale value back.
     */
    function effectiveWholesalePrice(PDO $pdo, int $productId, $legacyWholesale = null): ?float
    {
        $gid = wholesalePriceGroupId($pdo);
        if ($gid) {
            $st = $pdo->prepare("SELECT price FROM product_price_group_prices WHERE product_id = ? AND price_group_id = ?");
            $st->execute([$productId, $gid]);
            $v = $st->fetchColumn();
            if ($v !== false) return (float)$v;
        }
        return ($legacyWholesale !== null && (float)$legacyWholesale > 0) ? (float)$legacyWholesale : null;
    }
}

if (!function_exists('syncWholesaleGroupPrice')) {
    /**
     * Write a product's wholesale price where POS reads it — the Wholesale
     * price group (the same upsert api/pos/quick_restock.php does) — and keep
     * the legacy products.wholesale_price column equal to it.
     *   $price > 0  → upsert the override;
     *   $price <= 0 → remove the override (POS falls back to the normal price).
     * A tenant without price groups only gets the legacy column. Runs inside the
     * caller's transaction; throws on DB error so the caller rolls back.
     */
    function syncWholesaleGroupPrice(PDO $pdo, int $productId, float $price): void
    {
        $price = round(max(0.0, $price), 2);
        $gid = wholesalePriceGroupId($pdo);
        if ($gid) {
            if ($price > 0) {
                $pdo->prepare("
                    INSERT INTO product_price_group_prices (product_id, price_group_id, price, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
                ")->execute([$productId, $gid, $price]);
            } else {
                $pdo->prepare("DELETE FROM product_price_group_prices WHERE product_id = ? AND price_group_id = ?")
                    ->execute([$productId, $gid]);
            }
        }
        // Older schemas lack the legacy column (api/mobile/products/get.php checks the
        // same) — never let its absence fail, and so roll back, the caller's save.
        static $hasLegacyCol = null;
        if ($hasLegacyCol === null) {
            $hasLegacyCol = (bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'wholesale_price'")->fetch();
        }
        if ($hasLegacyCol) {
            $pdo->prepare("UPDATE products SET wholesale_price = ? WHERE product_id = ?")->execute([$price, $productId]);
        }
    }
}

if (!function_exists('backfillWholesaleGroupPrices')) {
    /**
     * One-off repair (migrations/*_wholesale_price_group_backfill*): products
     * registered with a wholesale price got it only in the legacy
     * products.wholesale_price column, which POS never reads, so wholesale
     * customers paid the normal price. Copies that price into the Wholesale
     * price group for every non-deleted product that has one (> 0) and no
     * Wholesale override yet. Never overwrites an existing override. Idempotent.
     * Returns the number of products fixed (0 when price groups or the legacy
     * column do not exist on this database).
     */
    function backfillWholesaleGroupPrices(PDO $pdo): int
    {
        if (!(bool)$pdo->query("SHOW TABLES LIKE 'product_price_group_prices'")->fetch()) return 0;
        if (!(bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'wholesale_price'")->fetch()) return 0;
        $gid = wholesalePriceGroupId($pdo);
        if (!$gid) return 0;
        $st = $pdo->prepare("
            INSERT IGNORE INTO product_price_group_prices (product_id, price_group_id, price, created_at, updated_at)
            SELECT p.product_id, ?, ROUND(p.wholesale_price, 2), NOW(), NOW()
              FROM products p
             WHERE p.wholesale_price > 0
               AND p.status <> 'deleted'
               AND NOT EXISTS (SELECT 1 FROM product_price_group_prices x
                                WHERE x.product_id = p.product_id AND x.price_group_id = ?)
        ");
        $st->execute([$gid, $gid]);
        return $st->rowCount();
    }
}
