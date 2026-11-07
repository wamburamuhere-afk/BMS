<?php
/**
 * Smart POS has no "Minimum selling price" — CLI regression
 *   php tests/test_smart_pos_min_price_cli.php
 *
 * Bug: the simple product form hides Min Selling Price and Max Discount %, the save code filled
 * the minimum with the selling price, and the till then refused every discount / wholesale price
 * ("Price for 'X' is below minimum selling price.").
 *
 * Rule: Simple Mode (and not the Advanced product form) -> the till never refuses a price because
 * of the minimum; a saved product's minimum is its buying price. Full BMS is unchanged.
 *
 *   1. static    — lint, the guard in process_sale, create/update/import call the rule, JS flag
 *   2. rule      — posMinPriceOff / posMinPriceFor for every settings combination (own processes)
 *   3. migration — posMinPriceFixExisting on a throwaway company: only a Smart POS database is
 *                  touched, idempotent
 *   4. real sale — real process_sale over HTTP on a throwaway company: full BMS still refuses a
 *                  price below the minimum; Smart POS sells at wholesale price and with a discount;
 *                  the Advanced product form brings the check back
 */
$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    mhStopAll();
    if ($fail > 0) exit(1);
});

section('1. Static');
foreach (['core/pos_min_price.php', 'api/create_product.php', 'api/update_product.php', 'api/pos/process_sale.php',
          'core/product_import.php', 'app/bms/pos/pos_scripts_new.php',
          'migrations/tenant/2026_11_07_smart_pos_min_price.php', 'migrations/2026_11_07_smart_pos_min_price_legacy_db.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
$src = fn(string $f) => file_get_contents("$root/$f");
check(strpos($src('api/pos/process_sale.php'), '!posMinPriceOff() && $requested_price') !== false, 'process_sale: minimum check skipped on Smart POS');
check(strpos($src('api/create_product.php'), 'posMinPriceFor(') !== false, 'create_product uses the rule');
check(strpos($src('api/update_product.php'), 'posMinPriceFor(') !== false, 'update_product uses the rule');
check(strpos($src('core/product_import.php'), "'min_selling_price' => \$cost") !== false, 'import: minimum = buying price');
check(strpos($src('app/bms/pos/pos_scripts_new.php'), 'POS_MIN_PRICE_OFF') !== false
   && substr_count($src('app/bms/pos/pos_scripts_new.php'), 'posMinOf(') >= 4, 'till script: flag + all 3 cart builders use posMinOf()');

section('2. The rule, every settings combination');
$child = function (string $simple, string $adv, float $cost, float $full): string {
    $code = '$s=["pos_simple_mode"=>' . var_export($simple, true) . ',"pos_advanced_product"=>' . var_export($adv, true) . '];'
          . 'function get_setting($k,$d=""){global $s;return $s[$k]??$d;}'
          . 'require ' . var_export(dirname(__DIR__) . '/core/pos_min_price.php', true) . ';'
          . 'echo (posMinPriceOff()?"off":"on"),"|",posMinPriceFor(' . $cost . ',' . $full . ');';
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'minp_rule_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp)));
    @unlink($tmp);
    return $out;
};
check($child('1', '0', 4500, 8000) === 'off|4500',  'Simple Mode: check off, minimum = buying price (4500, not 8000)');
check($child('1', '1', 4500, 8000) === 'on|8000',   'Simple Mode + Advanced product form: full BMS rule (8000)');
check($child('0', '0', 4500, 8000) === 'on|8000',   'Full BMS: unchanged (8000)');
check($child('0', '1', 4500, 8000) === 'on|8000',   'Full BMS + advanced flag: unchanged');
check($child('1', '0', 0,    8000) === 'off|0',     'Simple Mode, no buying price: minimum 0 (nothing blocked)');

section('3. Migration on a throwaway company');
$m = mhStart('minp');
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$tok = mhLogin($m);
check((bool)$tok, 'owner signs in');
[$code, $w] = mhCall($m, 'POST', '/api/mobile/warehouses/create.php', ['warehouse_name' => 'Main Shop'], $tok);
$wh = (int)($w['warehouse_id'] ?? 0);
check($code === 200 && $wh > 0, "shop created (HTTP $code)");
[$code, $p] = mhCall($m, 'POST', '/api/mobile/products/create.php', [
    'product_name' => 'Bl25kalam', 'selling_price' => 8000, 'cost_price' => 4500, 'wholesale_price' => 5000,
    'initial_stocks' => [['warehouse_id' => $wh, 'quantity' => 50]],
], $tok, true);
$pid = (int)($p['product_id'] ?? 0);
check($code === 200 && $pid > 0, "product created (HTTP $code)");

$tp = mhTenantPdo($m);
require_once "$root/core/pos_min_price.php";
$minOf = fn() => (float)$tp->query("SELECT min_selling_price FROM products WHERE product_id = $pid")->fetchColumn();
$setting = function (string $k, string $v) use ($tp) {
    $tp->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$k, $v, $v]);
};
check($minOf() === 8000.0, 'the bug: the product starts with minimum = selling price (8000)');
$setting('pos_simple_mode', '0');
check(posMinPriceFixExisting($tp) === 0 && $minOf() === 8000.0, 'full BMS database: migration changes nothing');
$setting('pos_simple_mode', '1'); $setting('pos_advanced_product', '1');
check(posMinPriceFixExisting($tp) === 0 && $minOf() === 8000.0, 'Advanced product form on: migration changes nothing');
$setting('pos_advanced_product', '0');
check(posMinPriceFixExisting($tp) >= 1 && $minOf() === 4500.0, 'Smart POS database: minimum becomes the buying price (4500)');
check(posMinPriceFixExisting($tp) === 0 && $minOf() === 4500.0, 'run again: nothing more to change (idempotent)');

section('4. Real sales (process_sale over HTTP)');
$sale = fn(float $price) => [
    'customer_id' => null, 'warehouse_id' => $wh, 'project_id' => null,
    'items' => [['product_id' => $pid, 'quantity' => 1, 'price' => $price, 'discounted_price' => $price, 'tax_rate' => 0]],
    'subtotal' => $price, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $price,
    'payment_method' => 'cash', 'amount_tendered' => $price, 'change_given' => 0, 'amount_paid' => $price,
];
$tp->exec("UPDATE products SET min_selling_price = 8000 WHERE product_id = $pid");

[$c, $j] = mhCall($m, 'POST', '/api/pos/open_shift.php', ['opening_cash' => 0], $tok, true);
check($c === 200 && !empty($j['success']), "cash register shift opened (HTTP $c)");

$setting('pos_simple_mode', '0');
[$c, $j] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(5000), $tok, true);
check($c === 422 && stripos(json_encode($j), 'below minimum selling price') !== false, "full BMS: wholesale price 5000 refused (the original error, HTTP $c)");
[$c, $j] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(8000), $tok, true);
check($c === 200 && !empty($j['success']), "full BMS: the normal price 8000 still sells (HTTP $c)");

$setting('pos_simple_mode', '1');
[$c, $j] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(5000), $tok, true);
check($c === 200 && !empty($j['success']), "Smart POS: wholesale price 5000 sells (HTTP $c) — minimum 8000 is stored but ignored");
[$c, $j] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(7000), $tok, true);
check($c === 200 && !empty($j['success']), "Smart POS: a discounted price 7000 sells (HTTP $c)");

$setting('pos_advanced_product', '1');
[$c, $j] = mhCall($m, 'POST', '/api/pos/process_sale.php', $sale(5000), $tok, true);
check($c === 422 && stripos(json_encode($j), 'below minimum selling price') !== false, "Advanced product form on: the check is back (HTTP $c)");

$l = $tp->query("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE 0 END),0) dr,
                        COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
                 FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'")->fetch(PDO::FETCH_ASSOC);
check((float)$l['dr'] > 0 && abs((float)$l['dr'] - (float)$l['cr']) < 0.01, 'ledger has the sales and is balanced (Σ Dr = Σ Cr = ' . $l['dr'] . ')');
$sold = (float)$tp->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE sale_status = 'completed'")->fetchColumn();
check(abs($sold - (8000 + 5000 + 7000)) < 0.01, "three sales booked at the prices charged: 8000 + 5000 + 7000 (got $sold)");
