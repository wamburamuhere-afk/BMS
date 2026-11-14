<?php
/**
 * tests/test_pos_selling_settings_cli.php — Selling settings (2026-11-14), Smart POS retail.
 *
 *   1. "Allow a buying price above the selling price" (pos_allow_buying_above_selling, off by default):
 *      off → every Smart POS save path refuses buying > retail (equal is fine; services and items
 *      without a selling price are not checked); on → saves as before. Paths: product create/edit,
 *      Receive Stock (quick_restock), batch edit, Excel import, mobile create/edit.
 *   2. "Price list the Sell screen opens on" (pos_default_price_list, retail by default): pos.php
 *      selects the Wholesale group when chosen; the cashier can still switch; a walk-in returns to it;
 *      a Restaurant-only shop goes back to Retail.
 *   3. Settings page: section shown for Smart POS retail, saves both values.
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
$IS_WORKER = ($argv[1] ?? '') === 'worker';
// Test seam: helpers.php defines get_setting() only if it does not exist yet, and its own version
// caches every setting at the first call — so settings written inside this test's transaction would
// never be seen. This one reads live from the same connection. Test-only; the app is unchanged.
function get_setting($key, $default = '') {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $default;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
require_once __DIR__ . '/helpers/restaurant_inproc.php';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf';   // JSON calls (mobile endpoints) carry no _csrf field
require_once "$root/core/price_check.php";
require_once "$root/core/pos_price_groups.php";
require_once "$root/core/product_import.php";

$set = function (string $k, string $v) use ($pdo) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$k, $v]);
};
$del = fn(string $k) => $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);

// Worker: one endpoint call in its own process. The refusals end with exit(), so they cannot run
// in-process; here the whole run stays inside an uncommitted transaction that dies with the
// process (nothing is ever committed). Settings are written BEFORE anything reads get_setting().
if ($IS_WORKER) {
    $cfg = json_decode((string)file_get_contents($argv[2]), true);
    $pdo->startOuter();
    foreach ($cfg['settings'] as $k => $v) $set($k, (string)$v);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                   VALUES (?, ?, 'pcs', 800, 0, 475, 475, 0, 'active', 10, 10)")->execute(["ZZ SELLSET W $sfx", "SSW-$sfx"]);
    $P = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 10, 0)")->execute([$P, $W]);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, 'ZZ-SS', 10, 10, 475, 800, NOW())")->execute([$P, $W]);
    $B = (int)$pdo->lastInsertId();
    $data = json_decode(str_replace(['"{P}"', '"{B}"', '"{W}"', '{SFX}'], [$P, $B, $W, $sfx], json_encode($cfg['data'])), true);
    register_shutdown_function(function () use ($P, $B) {
        // Runs after the endpoint (even after its exit()): what the database holds now, same transaction.
        global $pdo;
        echo "\n@@STATE " . json_encode([
            'cost'       => (float)$pdo->query("SELECT cost_price FROM products WHERE product_id = $P")->fetchColumn(),
            'batch_cost' => (float)$pdo->query("SELECT unit_cost FROM product_batches WHERE batch_id = $B")->fetchColumn(),
            'batches'    => (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = $P")->fetchColumn(),
        ]) . "\n";
    });
    echo "@@OUT " . raw($cfg['file'], 'POST', $data, !empty($cfg['json'])) . "\n";
    exit;   // never commits
}
$worker = function (string $file, array $data, array $settings, bool $json = false) use ($root): array {
    $f = tempnam(sys_get_temp_dir(), 'pss');
    file_put_contents($f, json_encode(['file' => $file, 'data' => $data, 'settings' => $settings, 'json' => $json]));
    $out = (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $res = preg_match('/\{"success".*?\}(?=\s|$)/s', $out, $m) ? (json_decode($m[0], true) ?: []) : [];
    $state = preg_match('/@@STATE (\{.*\})/', $out, $s) ? (json_decode($s[1], true) ?: []) : [];
    if (!$res) $res = ['success' => false, 'message' => 'no JSON: ' . substr($out, 0, 300)];
    return [$res, $state];
};

section('2. Save paths refuse buying > selling (setting off)');
$OFF_ = ['pos_simple_mode' => '1', 'pos_allow_buying_above_selling' => '0'];
$ON_  = ['pos_simple_mode' => '1', 'pos_allow_buying_above_selling' => '1'];
$refused = fn(array $r) => empty($r['success']) && stripos((string)($r['message'] ?? ''), 'higher than the selling price') !== false;
$msg = fn(array $r) => ' (' . ($r['message'] ?? '') . ')';

[$r] = $worker('api/create_product.php', ['product_name' => 'ZZ SELLSET {SFX}', 'unit' => 'pcs', 'cost_price' => 11400, 'selling_price' => 800, 'is_service' => 0], $OFF_);
ok($refused($r), 'Add product: buying 11,400 > selling 800 refused' . $msg($r));
[$r] = $worker('api/create_product.php', ['product_name' => 'ZZ SELLSET OK {SFX}', 'unit' => 'pcs', 'cost_price' => 475, 'selling_price' => 800, 'is_service' => 0, 'track_inventory' => 0], $OFF_);
ok(!empty($r['success']), 'Add product: buying 475 < selling 800 saves' . $msg($r));
[$r] = $worker('api/create_product.php', ['product_name' => 'ZZ SELLSET SV {SFX}', 'unit' => 'job', 'cost_price' => 11400, 'selling_price' => 800, 'is_service' => 1, 'track_inventory' => 0], $OFF_);
ok(!$refused($r), 'Add service: not checked' . $msg($r));

[$r, $st] = $worker('api/update_product.php', ['product_id' => '{P}', 'product_name' => 'ZZ SELLSET W {SFX}', 'unit' => 'pcs', 'cost_price' => 900, 'selling_price' => 800, 'is_service' => 0], $OFF_);
ok($refused($r), 'Edit product: buying 900 > 800 refused' . $msg($r));
ok(abs(($st['cost'] ?? 0) - 475) < 0.01, 'product keeps buying price 475');

[$r, $st] = $worker('api/pos/quick_restock.php', ['product_id' => '{P}', 'warehouse_id' => '{W}', 'quantity' => 5, 'buying_price' => 11400, 'selling_price' => 800], $OFF_);
ok($refused($r), 'Receive Stock: buying 11,400 > 800 refused' . $msg($r));
ok(($st['batches'] ?? 0) === 1, 'no new batch created');

[$r, $st] = $worker('api/stock/update_warehouse_batch.php', ['batch_id' => '{B}', 'warehouse_id' => '{W}', 'unit_cost' => 25001, 'selling_price' => 5000], $OFF_);
ok($refused($r), 'Batch edit: buying 25,001 > 5,000 refused' . $msg($r));
ok(abs(($st['batch_cost'] ?? 0) - 475) < 0.01, 'batch keeps 475');
[$r] = $worker('api/stock/update_warehouse_batch.php', ['batch_id' => '{B}', 'warehouse_id' => '{W}', 'unit_cost' => 25001, 'selling_price' => ''], $OFF_);
ok($refused($r), 'Batch edit with no batch selling price: compared with the product price (800) → refused' . $msg($r));
[$r, $st] = $worker('api/stock/update_warehouse_batch.php', ['batch_id' => '{B}', 'warehouse_id' => '{W}', 'unit_cost' => 500, 'selling_price' => 800], $OFF_);
ok(!empty($r['success']) && abs(($st['batch_cost'] ?? 0) - 500) < 0.01, 'Batch edit: buying 500 < 800 saves' . $msg($r));

[$r] = $worker('api/mobile/products/create.php', ['product_name' => 'ZZ MOB {SFX}', 'cost_price' => 11400, 'selling_price' => 800, 'unit' => 'pcs'], $OFF_, true);
ok($refused($r), 'App: Add product refused' . $msg($r));
[$r, $st] = $worker('api/mobile/products/update.php', ['product_id' => '{P}', 'cost_price' => 9000], $OFF_, true);
ok($refused($r) && abs(($st['cost'] ?? 0) - 475) < 0.01, 'App: Edit buying to 9,000 > 800 refused' . $msg($r));
[$r] = $worker('api/mobile/products/update.php', ['product_id' => '{P}', 'description' => 'note only'], $OFF_, true);
ok(!empty($r['success']), 'App: an edit that does not touch prices still saves' . $msg($r));
section('3. Setting ON -> the same saves go through');
[$r] = $worker('api/create_product.php', ['product_name' => 'ZZ SELLSET ON {SFX}', 'unit' => 'pcs', 'cost_price' => 11400, 'selling_price' => 800, 'is_service' => 0, 'track_inventory' => 0], $ON_);
ok(!empty($r['success']), 'Add product with buying above selling saves when allowed' . $msg($r));
[$r, $st] = $worker('api/stock/update_warehouse_batch.php', ['batch_id' => '{B}', 'warehouse_id' => '{W}', 'unit_cost' => 25001, 'selling_price' => 5000], $ON_);
ok(!empty($r['success']) && abs(($st['batch_cost'] ?? 0) - 25001) < 0.01, 'Batch edit above selling saves when allowed' . $msg($r));

$pdo->startOuter();
try {
    section('1. The rule (core/price_check.php)');
    $set('pos_simple_mode', '1');
    $del('pos_allow_buying_above_selling');
    $del('pos_default_price_list');
    ok(!priceBuyingAboveSellingAllowed($pdo), 'default: buying above selling NOT allowed');
    ok(posDefaultPriceList($pdo) === 'retail', 'default price list: retail');
    ok(priceBuyingGuardActive($pdo), 'guard active in Smart POS with the setting off');
    $e = priceBuyingAboveSellingError($pdo, 11400, 800);
    ok(is_string($e) && strpos($e, '11,400') !== false && strpos($e, '800') !== false, 'buying 11,400 > selling 800 refused, message names both');
    ok(priceBuyingAboveSellingError($pdo, 800, 800) === null, 'equal prices allowed');
    ok(priceBuyingAboveSellingError($pdo, 475, 800) === null, 'buying below selling allowed');
    ok(priceBuyingAboveSellingError($pdo, 11400, 800, true) === null, 'services not checked');
    ok(priceBuyingAboveSellingError($pdo, 500, 0) === null && priceBuyingAboveSellingError($pdo, 500, '') === null, 'no selling price → not checked');
    ok(priceBuyingAboveSellingError($pdo, '1,500', '1,000') !== null, 'figures with thousands commas understood');
    $set('pos_allow_buying_above_selling', '1');
    ok(priceBuyingAboveSellingError($pdo, 11400, 800) === null, 'setting ON → allowed');
    $set('pos_allow_buying_above_selling', '0');
    $set('pos_simple_mode', '0');
    ok(priceBuyingAboveSellingError($pdo, 11400, 800) === null, 'Smart BMS (Simple Mode off) → not checked');
    $set('pos_simple_mode', '1');
    $set('pos_default_price_list', 'wholesale');
    ok(posDefaultPriceList($pdo) === 'wholesale', 'price list setting reads wholesale');
    $set('pos_default_price_list', 'bogus');
    ok(posDefaultPriceList($pdo) === 'retail', 'unknown value falls back to retail');
    $del('pos_default_price_list');

    section('2b. Excel import (in-process)');

    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $plan = piPlan($pdo, [
        ['line' => 2, 'product_name' => "ZZ IMP A $sfx", 'cost_price' => '11400', 'selling_price' => '800'],
        ['line' => 3, 'product_name' => "ZZ IMP B $sfx", 'cost_price' => '475', 'selling_price' => '800'],
    ]);
    ok(($plan[0]['status'] ?? '') === 'error' && stripos($plan[0]['message'] ?? '', 'higher than the selling price') !== false, 'Excel import: row with buying > selling is an error');
    ok(($plan[1]['status'] ?? '') === 'new', 'Excel import: good row stays new');

    $set('pos_allow_buying_above_selling', '1');
    $plan = piPlan($pdo, [['line' => 2, 'product_name' => "ZZ IMP C $sfx", 'cost_price' => '11400', 'selling_price' => '800']]);
    ok(($plan[0]['status'] ?? '') === 'new', 'Excel import row accepted when allowed');
    $set('pos_allow_buying_above_selling', '0');

    section('4. Till opens on the chosen price list (pos.php)');
    $wgid = (int)(wholesalePriceGroupId($pdo) ?? 0);
    $groups = (int)val("SELECT COUNT(*) FROM price_groups WHERE status = 'active'");
    $html = raw('app/bms/pos/pos.php', 'GET');
    $sel = function (string $h): ?int { return preg_match('/id="posPriceGroupId".*?<option value="(\d+)"[^>]*selected/s', $h, $m) ? (int)$m[1] : null; };
    if ($wgid && $groups > 1 && strpos($html, 'id="posPriceGroupId"') !== false) {
        $retail = (int)val("SELECT price_group_id FROM price_groups WHERE status = 'active' ORDER BY is_default DESC, name ASC LIMIT 1");
        ok($sel($html) === $retail, 'default setting: Retail selected');
        ok(strpos($html, "const POS_DEFAULT_PRICE_GROUP_ID = $retail;") !== false, 'script default = Retail');
        $set('pos_default_price_list', 'wholesale');
        $html = raw('app/bms/pos/pos.php', 'GET');
        ok($sel($html) === $wgid, 'Wholesale chosen: Wholesale selected on open');
        ok(strpos($html, "const POS_DEFAULT_PRICE_GROUP_ID = $wgid;") !== false && strpos($html, "const POS_RETAIL_PRICE_GROUP_ID  = $retail;") !== false, 'script knows default (Wholesale) and Retail');
        ok(substr_count($html, '<option value=') > 1 && preg_match('/id="posPriceGroupId"[^>]*disabled/', $html) === 0, 'list still has both and is not locked — cashier can switch');
        $del('pos_default_price_list');
    } else {
        ok(true, 'price list picker not shown on this database (one active group or not rendered) — till check skipped');
    }
    $js = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
    ok(strpos($js, "val(String(posDefaultGroupForShop() || \$('#posPriceGroupId option:first').val())).trigger('change')") !== false, 'walk-in / cleared customer returns to the default list');
    ok(strpos($js, 'posRestaurantOnlyShop()) ? POS_RETAIL_PRICE_GROUP_ID : POS_DEFAULT_PRICE_GROUP_ID') !== false, 'Restaurant-only shop uses Retail');
    ok(strpos($js, 'posLastRestOnly !== restOnly') !== false, 'only a change of shop kind resets the list (cashier switch kept)');

    section('5. Settings page');
    $page = raw('app/constant/settings/pos_config_settings.php', 'GET');
    ok(strpos($page, 'name="pos_allow_buying_above_selling"') !== false, 'Prices section: the allow switch is shown (Smart POS)');
    ok(strpos($page, 'name="pos_default_price_list"') !== false, 'Prices section: default price list choice shown');
    ok(preg_match('/id="pos_allow_buying_above_selling"[^>]*checked/', $page) === 0, 'switch off by default');
    ok(preg_match('/id="pdl_retail"[^>]*checked/', $page) === 1, 'Retail chosen by default');
    $src = file_get_contents("$root/app/constant/settings/pos_config_settings.php");
    ok(strpos($src, "save_setting('pos_allow_buying_above_selling'") !== false && strpos($src, "save_setting('pos_default_price_list'") !== false, 'both values saved on Save');
    ok(strpos($src, '$prices_section = posSimpleModeEnabled() && !$rf_set;') !== false, 'section only for Smart POS with a retail shop');

    section('6. Browser block');
    $pc = file_get_contents("$root/assets/js/bms-price-check.js");
    ok(strpos($pc, 'window.BMS_PRICE_BLOCK_BUY_ABOVE_RETAIL && pos(b) && pos(r) && r < b') !== false, 'price check blocks buying > retail when not allowed');
    $hd = file_get_contents("$root/header.php");
    ok(strpos($hd, 'window.BMS_PRICE_BLOCK_BUY_ABOVE_RETAIL = ') !== false, 'header passes the flag');
} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $pdo->endOuter();
}
ok(!$pdo->inTransaction(), 'everything rolled back');
