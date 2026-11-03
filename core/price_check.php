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
