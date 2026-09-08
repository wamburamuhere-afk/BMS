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
 * Resolve the effective price for a set of products under one price group.
 *
 * @param PDO   $pdo
 * @param int   $priceGroupId  0/absent = no group -> empty result, callers
 *                              should fall back to plain selling_price for
 *                              every product (identical to pre-Phase-14).
 * @param array $productIds    product_ids to resolve (may include ids with
 *                              no override — they're simply absent from the
 *                              returned map).
 * @return array<int,float> product_id => override price, only for products
 *                           that actually have a row in this group.
 */
function resolveGroupPrices(PDO $pdo, int $priceGroupId, array $productIds): array
{
    if ($priceGroupId <= 0 || empty($productIds)) {
        return [];
    }

    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $stmt = $pdo->prepare("
        SELECT product_id, price
        FROM product_price_group_prices
        WHERE price_group_id = ? AND product_id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$priceGroupId], $productIds));

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int)$row['product_id']] = (float)$row['price'];
    }
    return $result;
}
