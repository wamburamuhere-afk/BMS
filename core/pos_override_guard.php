<?php
/**
 * core/pos_override_guard.php
 *
 * Phase 16 (pos_upgrade_plan.md §8) — loss-control permission split for the
 * POS terminal. Extracted out of api/pos/process_sale.php so the resolution
 * logic is independently unit-testable (same reasoning every prior phase used
 * for core/pos_shift_reporting.php, core/pos_loyalty.php, etc.) rather than
 * inlined in the endpoint where only a live HTTP call could exercise it.
 *
 * The real security boundary: a client can send ANY item['price'] /
 * item['manual_price_override'] / item['discount_percent'] it likes — none of
 * it is trusted unless the resolution below explicitly allows it.
 */

/**
 * Resolve the authoritative base unit price for one POS sale line.
 *
 * - Default: always the product's own DB selling_price — the client's
 *   item['price'] is never trusted on its own (closes a pre-existing gap
 *   where a forged request could set it to anything).
 * - Exception: item['manual_price_override'] is truthy AND the cashier holds
 *   pos_price_override — then (and only then) the client-submitted price is
 *   used, letting the POS terminal's "Edit Price" affordance actually work
 *   for the cashiers permitted to use it.
 *
 * @param array $item              raw cart line from the client
 * @param array $dbProduct         product row (must include 'selling_price')
 * @param bool  $canPriceOverride  canEdit('pos_price_override') for this user
 * @return array{original_price: float, requested_price: ?float} requested_price is
 *         non-null only when a manual override was attempted but denied — the
 *         caller should fall its own requested/discounted price back to it.
 */
function resolvePosLineBasePrice(array $item, array $dbProduct, bool $canPriceOverride): array
{
    $wantsManualPrice = !empty($item['manual_price_override']);

    if ($wantsManualPrice && $canPriceOverride) {
        return [
            'original_price'  => (float)($item['price'] ?? 0),
            'requested_price' => null, // caller keeps whatever it already resolved
        ];
    }

    $dbPrice = (float)($dbProduct['selling_price'] ?? 0);

    return [
        'original_price'  => $dbPrice,
        // An override was attempted without permission: force the
        // (already-computed) requested/discounted price back to the true DB
        // price too, so the line sells at full price rather than silently
        // keeping the unauthorized client price as the "discounted" total.
        'requested_price' => $wantsManualPrice ? $dbPrice : null,
    ];
}

/**
 * Throws if a real discount is present on a line and the cashier isn't
 * permitted to apply one. A no-op (never throws) for a zero/negligible
 * discount, or when the cashier holds pos_discount_override.
 *
 * @throws Exception
 */
function assertPosLineDiscountPermitted(float $itemDiscountAmount, bool $canDiscountOverride, string $productName): void
{
    if ($itemDiscountAmount > 0.01 && !$canDiscountOverride) {
        throw new Exception(sprintf(t('You do not have permission to apply a discount (product: %s).'), $productName));
    }
}
