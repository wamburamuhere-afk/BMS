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
