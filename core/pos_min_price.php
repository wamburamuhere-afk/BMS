<?php
/**
 * Smart POS (Simple Mode) has no "Minimum selling price".
 *
 * The simple Add/Edit Product form hides the field and "Max Discount %", so the
 * shop owner never sets them. The save code used to auto-fill the minimum from
 * the selling price (selling price - 0% discount = selling price), which silently
 * blocked every discount and every wholesale sale: "Price for 'X' is below
 * minimum selling price."
 *
 * The rule now, for a shop running Simple Mode (and not the superadmin's
 * "Advanced product form" override, same test as $simpleProductForm):
 *   - the till never refuses a price because of the minimum;
 *   - a saved product's minimum is its buying price (the real floor), instead of
 *     its selling price.
 * Full BMS (Simple Mode off, or Advanced product form on) is unchanged.
 *
 * Dependency-free on purpose: loaded by APIs, the migration and the tests.
 */

if (!function_exists('posMinPriceOff')) {
    /** True when this shop is Smart POS: no minimum selling price anywhere. */
    function posMinPriceOff(): bool
    {
        $get = function_exists('get_setting') ? 'get_setting' : (function_exists('getSetting') ? 'getSetting' : null);
        if ($get === null) {
            return false;
        }
        return $get('pos_simple_mode', '0') === '1' && $get('pos_advanced_product', '0') !== '1';
    }
}

if (!function_exists('posMinPriceFixExisting')) {
    /**
     * Data fix for the migration (criteria-based, idempotent): on a Smart POS database,
     * set every product's minimum selling price to its buying price. Does nothing on a
     * database that is not in Simple Mode, or has the Advanced product form on.
     * Returns the number of products changed.
     */
    function posMinPriceFixExisting(PDO $pdo, ?callable $log = null): int
    {
        $say = $log ?: function ($m) {};
        try {
            $set = function (string $k) use ($pdo) {
                $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
                $st->execute([$k]);
                $v = $st->fetchColumn();
                return $v === false ? '0' : (string)$v;
            };
            if ($set('pos_simple_mode') !== '1' || $set('pos_advanced_product') === '1') {
                $say('Not a Smart POS database - no products changed.');
                return 0;
            }
            $has = $pdo->query("SHOW COLUMNS FROM products LIKE 'min_selling_price'")->fetch();
            if (!$has) { return 0; }
            $n = $pdo->exec("UPDATE products SET min_selling_price = cost_price WHERE min_selling_price <> cost_price");
            $say("Smart POS: minimum selling price set to the buying price on $n product(s).");
            return (int)$n;
        } catch (PDOException $e) {
            $say('Skipped (' . $e->getMessage() . ')');
            return 0;
        }
    }
}

if (!function_exists('posMinPriceFor')) {
    /**
     * The minimum selling price to STORE for a product.
     * Smart POS -> its buying price; otherwise -> $fullBmsValue untouched (the old rule).
     */
    function posMinPriceFor(float $costPrice, float $fullBmsValue): float
    {
        return posMinPriceOff() ? max(0.0, round($costPrice, 2)) : $fullBmsValue;
    }
}
