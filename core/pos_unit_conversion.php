<?php
/**
 * core/pos_unit_conversion.php
 *
 * Phase 15 (pos_upgrade_plan.md §8) — unit conversion at the register.
 * Extracted for independent testability, same reasoning as every other
 * core/pos_*.php helper added in this tranche.
 */

/**
 * Resolve a product's selling-unit conversion by label, server-side —
 * never trust a client-submitted multiplier or price directly.
 *
 * @return array{multiplier: float, unit_price_override: ?float}|null
 *         null = no such conversion row for this product (base unit sale —
 *         caller should treat quantity/price exactly as it did before this
 *         phase, fully backward compatible).
 */
function resolveUnitConversion(PDO $pdo, int $productId, string $unitLabel): ?array
{
    $unitLabel = trim($unitLabel);
    if ($productId <= 0 || $unitLabel === '') return null;

    $stmt = $pdo->prepare("
        SELECT base_unit_multiplier, unit_price_override
        FROM product_unit_conversions
        WHERE product_id = ? AND unit_label = ?
        LIMIT 1
    ");
    $stmt->execute([$productId, $unitLabel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    return [
        'multiplier'           => (float)$row['base_unit_multiplier'],
        'unit_price_override'  => $row['unit_price_override'] !== null ? (float)$row['unit_price_override'] : null,
    ];
}

/**
 * Convert a cart line sold in a non-base selling unit into base-unit
 * quantity + base-unit price, resolving the conversion server-side.
 *
 * @param float $enteredQty    quantity the cashier entered, in the SOLD unit
 *                              (e.g. "2" cartons)
 * @param float $baseUnitPrice the product's own resolved per-base-unit price
 *                              (already price-group-aware, from Phase 14) —
 *                              used unchanged when no unit_price_override exists.
 * @return array{base_quantity: float, base_unit_price: float}
 */
function convertToBaseUnit(array $conversion, float $enteredQty, float $baseUnitPrice): array
{
    $multiplier = $conversion['multiplier'] > 0 ? $conversion['multiplier'] : 1.0;
    $baseQuantity = $enteredQty * $multiplier;

    $resolvedBaseUnitPrice = $baseUnitPrice;
    if ($conversion['unit_price_override'] !== null) {
        // Override is priced PER SELLING UNIT (e.g. per carton) — convert
        // back down to a per-base-unit price so line_total = price × base_qty
        // still equals entered_qty × unit_price_override.
        $resolvedBaseUnitPrice = $conversion['unit_price_override'] / $multiplier;
    }

    return ['base_quantity' => $baseQuantity, 'base_unit_price' => $resolvedBaseUnitPrice];
}
