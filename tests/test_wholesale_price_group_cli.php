<?php
/**
 * tests/test_wholesale_price_group_cli.php — the wholesale price a product is
 * registered/edited with is the one POS charges (Wholesale price group).
 *
 * Real endpoints, each in its own PHP process as a forged admin:
 *   1. create_product.php with wholesale → group override written
 *   2. POS (api/pos/simple_products.php, Wholesale group) prices it at that value
 *   3. update_product.php changes it → group follows; 0 removes it (POS → normal price);
 *      a save that does not send the field leaves the group alone
 *   4. edit form prefills the group price (so a save never writes a stale value)
 *   5. backfill: legacy-only products fixed, existing overrides untouched,
 *      0-price products skipped, second run fixes nothing
 *   6. mobile get returns the effective price; helpers on a DB without groups
 * Every fixture is removed at the end.
 *
 *   php tests/test_wholesale_price_group_cli.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/pos_price_groups.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $m\n"; } else { $fail++; echo "  ✗ $m\n"; } }
function section($t) { echo "\n── $t\n"; }

/** Run $file as admin with $_POST/$_GET in a fresh process → decoded JSON (or raw). */
function run(string $file, array $post = [], array $get = [], string $method = 'POST')
{
    global $ADMIN;
    $code = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';$_SERVER["HTTP_HOST"]="localhost";'
          . 'require ' . var_export(ROOT_DIR . '/roots.php', true) . ';'
          . '$_SESSION=["user_id"=>' . (int)$ADMIN . ',"role_id"=>1,"is_admin"=>true,"user_lang"=>"en","csrf_token"=>"t","first_name"=>"T","last_name"=>"A","user_role"=>"Admin"];'
          . '$_POST=' . var_export($post + ['_csrf' => 't'], true) . ';$_GET=' . var_export($get, true) . ';'
          . 'chdir(' . var_export(ROOT_DIR, true) . ');include ' . var_export(ROOT_DIR . '/' . $file, true) . ';';
    $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    if (preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $err)) echo "    ! PHP: " . substr(trim($err), 0, 300) . "\n";
    $j = json_decode(trim($out), true);
    return $j ?? $out;
}
function groupPrice(int $pid): ?float
{
    global $pdo, $GID;
    $s = $pdo->prepare("SELECT price FROM product_price_group_prices WHERE product_id = ? AND price_group_id = ?");
    $s->execute([$pid, $GID]);
    $v = $s->fetchColumn();
    return $v === false ? null : (float)$v;
}

$ADMIN = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$GID = wholesalePriceGroupId($pdo);
if (!$ADMIN || !$GID) { echo "Need an admin user and the Wholesale price group.\n"; exit(1); }
$tag = 'WPG' . bin2hex(random_bytes(3));
$made = [];

try {
    section('1. Registering a product writes the wholesale price where POS reads it');
    $r = run('api/create_product.php', ['product_name' => "Test Oil $tag", 'unit' => 'pcs', 'cost_price' => '1000', 'selling_price' => '1500',
                                        'wholesale_price' => '1300', 'sku' => "SKU-$tag", 'status' => 'active', 'is_service' => '0', 'track_inventory' => '1']);
    $pid = (int)$pdo->query("SELECT product_id FROM products WHERE product_name = " . $pdo->quote("Test Oil $tag"))->fetchColumn();
    if ($pid) $made[] = $pid;
    ok(is_array($r) && !empty($r['success']) && $pid > 0, 'create_product.php succeeded' . (is_array($r) ? '' : ': ' . substr((string)$r, 0, 200)));
    ok(groupPrice($pid) === 1300.0, 'Wholesale price group = 1,300 (was never written before the fix)');
    ok((float)$pdo->query("SELECT wholesale_price FROM products WHERE product_id = $pid")->fetchColumn() === 1300.0, 'legacy column kept equal');

    section('2. POS charges it');
    $pos = run('api/pos/simple_products.php', [], ['price_group_id' => (string)$GID, 'search' => "Test Oil $tag"], 'GET');
    $rows = is_array($pos) ? ($pos['products'] ?? $pos['data'] ?? []) : [];
    $row = array_values(array_filter($rows, fn($x) => (int)($x['product_id'] ?? $x['id'] ?? 0) === $pid))[0] ?? null;
    $price = $row ? (float)($row['price'] ?? $row['effective_price'] ?? $row['selling_price'] ?? 0) : null;
    ok($row !== null, 'POS product list returns the product');
    ok($price === 1300.0, 'POS Wholesale price = 1,300 (got ' . var_export($price, true) . ')');

    section('3. Editing');
    $base = ['product_id' => (string)$pid, 'product_name' => "Test Oil $tag", 'unit' => 'pcs', 'cost_price' => '1000', 'selling_price' => '1500', 'sku' => "SKU-$tag", 'status' => 'active'];
    $r = run('api/update_product.php', $base + ['wholesale_price' => '1250']);
    ok(is_array($r) && !empty($r['success']), 'update_product.php succeeded' . (is_array($r) ? '' : ': ' . substr((string)$r, 0, 200)));
    ok(groupPrice($pid) === 1250.0, 'changing the wholesale price updates the group (1,250)');
    $r = run('api/update_product.php', $base);   // a form that does not send the field
    ok(groupPrice($pid) === 1250.0, 'a save without the wholesale field leaves the group price alone');
    $r = run('api/update_product.php', $base + ['wholesale_price' => '0']);
    ok(groupPrice($pid) === null, 'setting it to 0 removes the override (POS falls back to the normal price)');
    $pos = run('api/pos/simple_products.php', [], ['price_group_id' => (string)$GID, 'search' => "Test Oil $tag"], 'GET');
    $rows = is_array($pos) ? ($pos['products'] ?? $pos['data'] ?? []) : [];
    $row = array_values(array_filter($rows, fn($x) => (int)($x['product_id'] ?? $x['id'] ?? 0) === $pid))[0] ?? null;
    ok($row && (float)($row['price'] ?? $row['effective_price'] ?? $row['selling_price'] ?? 0) === 1500.0, 'POS Wholesale price is back to the normal 1,500');

    section('4. Edit form shows the real price');
    syncWholesaleGroupPrice($pdo, $pid, 1222);
    $pdo->prepare("UPDATE products SET wholesale_price = 999 WHERE product_id = ?")->execute([$pid]);   // stale legacy value
    $html = run('app/bms/product/product_edit.php', [], ['id' => (string)$pid], 'GET');
    ok(is_string($html) && preg_match('#name="wholesale_price"[^>]*value="1222\.00"|value="1222\.00"[^>]*name="wholesale_price"#', $html) === 1, 'edit form prefills 1,222 (the group price), not the stale 999');

    section('5. Backfill of existing products');
    $mk = function (string $name, float $w, string $status = 'active') use ($pdo, &$made, $tag) {
        $pdo->prepare("INSERT INTO products (product_name, sku, unit, cost_price, selling_price, wholesale_price, status, is_service, created_by)
                       VALUES (?, ?, 'pcs', 100, 200, ?, ?, 0, 1)")->execute(["$name $tag", "B-$name-$tag", $w, $status]);
        $id = (int)$pdo->lastInsertId(); $made[] = $id; return $id;
    };
    $legacy  = $mk('Legacy', 180);
    $hasOne  = $mk('HasOverride', 170); syncWholesaleGroupPrice($pdo, $hasOne, 150); $pdo->prepare("UPDATE products SET wholesale_price = 170 WHERE product_id = ?")->execute([$hasOne]);
    $zero    = $mk('Zero', 0);
    $n = backfillWholesaleGroupPrices($pdo);
    ok(groupPrice($legacy) === 180.0, 'legacy-only product now has its wholesale price in the group (180)');
    ok(groupPrice($hasOne) === 150.0, 'an existing override is never overwritten (stays 150)');
    ok(groupPrice($zero) === null, 'a product with wholesale 0 is skipped');
    ok($n >= 1, "backfill reported $n product(s) fixed");
    ok(backfillWholesaleGroupPrices($pdo) === 0, 'second run fixes nothing (idempotent)');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_DIR . '/migrations/2026_10_03_wholesale_price_group_backfill_legacy_db.php') . ' 2>&1');
    ok(strpos((string)$out, 'Migration complete') !== false, 'legacy migration runs cleanly: ' . trim((string)$out));

    section('6. Mobile + helpers');
    $m = run('api/mobile/products/get.php', [], ['id' => (string)$legacy], 'GET');
    ok(is_array($m) && (float)($m['data']['wholesale_price'] ?? -1) === 180.0, 'mobile products/get returns the effective wholesale price');
    ok(effectiveWholesalePrice($pdo, $zero, 0) === null, 'no price anywhere → null');
    ok(effectiveWholesalePrice($pdo, $legacy, 999) === 180.0, 'group price wins over the legacy column');
    foreach (['api/create_product.php', 'api/update_product.php', 'api/mobile/products/create.php', 'api/mobile/products/update.php', 'api/generate_product_variants.php'] as $f) {
        ok(strpos(file_get_contents(ROOT_DIR . '/' . $f), 'syncWholesaleGroupPrice(') !== false, "$f writes the Wholesale group");
    }
} finally {
    if ($made) {
        $ids = implode(',', array_map('intval', $made));
        $pdo->exec("DELETE FROM product_price_group_prices WHERE product_id IN ($ids)");
        foreach (['product_batches', 'stock_movements', 'product_stocks'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE product_id IN ($ids)"); } catch (PDOException $e) {}
        }
        $pdo->exec("DELETE FROM products WHERE product_id IN ($ids)");
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE " . $pdo->quote("%$tag%"))->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'all test products removed' : "$left LEFT") . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
