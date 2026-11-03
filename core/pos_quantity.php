<?php
/**
 * core/pos_quantity.php — which POS quantities may have decimals (2026-11-03).
 *
 * Goods sold by measure (sugar per kg, oil per litre, cloth per metre) can be sold in parts:
 * 0.5 kg of sugar at 3,000/kg = 1,500, and stock goes down by exactly 0.5. Goods counted
 * one by one (pcs, bottle, box …) stay whole numbers, so nobody sells half a bottle by mistake.
 *
 * The product's own `unit` decides (or the selling unit's label when a unit conversion is used).
 * Quantities keep at most 3 decimal places — the same as product_stocks / pos_sale_items.
 * The same list is sent to the POS screen, so screen and server always agree.
 */

if (!function_exists('posDecimalUnits')) {
    /** Units (lower-case) that may be sold in parts. English + common Swahili spellings. */
    function posDecimalUnits(): array
    {
        return [
            'kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms', 'kilogramme',
            'g', 'gm', 'gms', 'gr', 'gram', 'grams', 'gramme', 'gramu',
            'l', 'lt', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters', 'lita',
            'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters',
            'm', 'mtr', 'mtrs', 'metre', 'metres', 'meter', 'meters', 'mita',
        ];
    }
}

if (!function_exists('posUnitAllowsDecimal')) {
    function posUnitAllowsDecimal(?string $unit): bool
    {
        $u = strtolower(trim((string)$unit));
        $u = rtrim($u, '.');
        return $u !== '' && in_array($u, posDecimalUnits(), true);
    }
}

if (!function_exists('posValidateLineQuantity')) {
    /**
     * Checks one sale line's quantity and returns it rounded to 3 decimal places.
     * Returns a string error when the quantity is not acceptable:
     *   - missing, not a number, zero or negative;
     *   - has a fraction but the unit is counted in whole numbers.
     *
     * @return float|string  the clean quantity, or an error message
     */
    function posValidateLineQuantity($raw, ?string $unit, string $productName)
    {
        if (!is_numeric($raw)) {
            return sprintf(t('Enter a quantity for \'%s\'.'), $productName);
        }
        $qty = round((float)$raw, 3);
        if ($qty <= 0) {
            return sprintf(t('The quantity for \'%s\' must be more than 0.'), $productName);
        }
        if (abs($qty - round($qty)) > 0.0000001 && !posUnitAllowsDecimal($unit)) {
            return sprintf(t('\'%s\' is sold in whole numbers. Enter a quantity without decimals.'), $productName);
        }
        return $qty;
    }
}
