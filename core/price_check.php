<?php
/**
 * core/price_check.php — the web's price sanity check (assets/js/bms-price-check.js)
 * for API callers, so the Flutter app can ask the same "Is this correct?".
 *
 * Never blocks: the endpoint saves as usual and adds the result as `warnings`.
 * Same rules and wording (translated via t(), the strings header.php gives the JS):
 *   below_buying_retail     — retail price below the buying price (selling at a loss)
 *   below_buying_wholesale  — wholesale price below the buying price
 *   wholesale_above_retail  — wholesale price above the retail price
 * Empty or zero figures are left alone, as on the web.
 */

/*
 * 2026-11-14 — Selling settings (Smart POS, retail):
 *   pos_allow_buying_above_selling  '0' (default) | '1'
 *     Off: a buying price above the retail selling price is REFUSED on every Smart POS
 *     save path (product create/edit, Receive Stock, batch edit, Excel import, mobile
 *     app). Equal is fine. Services and items without a selling price are not checked.
 *     On: today's behaviour — a warning only.
 *   pos_default_price_list  'retail' (default) | 'wholesale' — the price list the till opens on.
 * Read straight from system_settings (get_setting() caches for the whole request).
 */
if (!function_exists('priceSettingRaw')) {
    function priceSettingRaw(PDO $pdo, string $key, string $default): string
    {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null) ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('priceBuyingAboveSellingAllowed')) {
    /** True when the company allows a buying price above the selling price. */
    function priceBuyingAboveSellingAllowed(PDO $pdo): bool
    {
        return priceSettingRaw($pdo, 'pos_allow_buying_above_selling', '0') === '1';
    }
}

if (!function_exists('priceBuyingGuardActive')) {
    /** The refusal applies: Smart POS (Simple Mode) and the setting is off. */
    function priceBuyingGuardActive(PDO $pdo): bool
    {
        return priceSettingRaw($pdo, 'pos_simple_mode', '0') === '1' && !priceBuyingAboveSellingAllowed($pdo);
    }
}

if (!function_exists('priceBuyingAboveSellingError')) {
    /**
     * The refusal message when $buying is above $selling and the guard is active, else null.
     * Empty / zero figures and services are left alone.
     */
    function priceBuyingAboveSellingError(PDO $pdo, $buying, $selling, bool $isService = false): ?string
    {
        if ($isService) return null;
        $n = function ($v): ?float {
            if ($v === null || $v === '') return null;
            $f = (float)str_replace(',', '', (string)$v);
            return $f > 0 ? $f : null;
        };
        $b = $n($buying); $s = $n($selling);
        if ($b === null || $s === null || $b <= $s) return null;
        if (!priceBuyingGuardActive($pdo)) return null;
        $fmt = fn(float $x) => rtrim(rtrim(number_format($x, 2, '.', ','), '0'), '.');
        $msg = function_exists('t')
            ? t('Buying price ({b}) is higher than the selling price ({s}). Correct it, or allow this in Settings › Selling settings.')
            : 'Buying price ({b}) is higher than the selling price ({s}). Correct it, or allow this in Settings › Selling settings.';
        return str_replace(['{b}', '{s}'], [$fmt($b), $fmt($s)], $msg);
    }
}

if (!function_exists('posDefaultPriceList')) {
    /** 'retail' | 'wholesale' — the price list the till opens on. */
    function posDefaultPriceList(PDO $pdo): string
    {
        return priceSettingRaw($pdo, 'pos_default_price_list', 'retail') === 'wholesale' ? 'wholesale' : 'retail';
    }
}

if (!function_exists('priceCheckWarnings')) {
    /**
     * @return array<int, array{code:string, message:string}>
     */
    function priceCheckWarnings($buying, $retail, $wholesale = null): array
    {
        $n = function ($v): ?float {
            if ($v === null || $v === '') return null;
            $f = (float)str_replace(',', '', (string)$v);
            return $f > 0 ? $f : null;
        };
        $b = $n($buying); $r = $n($retail); $w = $n($wholesale);
        $fmt = fn(float $x) => rtrim(rtrim(number_format($x, 2, '.', ','), '0'), '.');
        $tr  = fn(string $s) => function_exists('t') ? t($s) : $s;
        $out = [];
        if ($b !== null && $r !== null && $r < $b) {
            $out[] = ['code' => 'below_buying_retail',
                      'message' => str_replace(['{r}', '{b}'], [$fmt($r), $fmt($b)],
                          $tr('Retail price ({r}) is lower than the buying price ({b}) — you would sell at a loss. Is this correct?'))];
        }
        if ($b !== null && $w !== null && $w < $b) {
            $out[] = ['code' => 'below_buying_wholesale',
                      'message' => str_replace(['{w}', '{b}'], [$fmt($w), $fmt($b)],
                          $tr('Wholesale price ({w}) is lower than the buying price ({b}) — you would sell at a loss. Is this correct?'))];
        }
        if ($w !== null && $r !== null && $w > $r) {
            $out[] = ['code' => 'wholesale_above_retail',
                      'message' => str_replace(['{w}', '{r}'], [$fmt($w), $fmt($r)],
                          $tr('Wholesale price ({w}) is higher than retail price ({r}). Is this correct?'))];
        }
        return $out;
    }
}
