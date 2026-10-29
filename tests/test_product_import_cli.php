<?php
/**
 * tests/test_product_import_cli.php — bulk product import from CSV (Smart POS).
 *   php tests/test_product_import_cli.php
 *
 * The real api/import_products.php runs in its own PHP process as a forged user
 * (tests/helpers/product_import_request.php):
 *   1. guards       — login, permission, CSRF, file type, missing column
 *   2. preview      — every row classified (new / exists / duplicate / error); writes nothing
 *   3. all or none  — a file with one bad row saves nothing
 *   4. commit       — products, categories (matched or created), unique SKU/barcode,
 *                     opening stock as a batch in the chosen shop, ledger Dr Inventory /
 *                     Cr Opening Balance balanced, wholesale price group
 *   5. re-import    — the same file again creates nothing
 * Every row this test writes is removed at the end.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../roots.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $m\n"; } else { $fail++; echo "  ✗ $m\n"; } }
function section($t) { echo "\n── $t\n"; }

function call(int $uid, bool $admin, array $params, ?string $csv, bool $canCreate = true): array
{
    $args = [PHP_BINARY, __DIR__ . '/helpers/product_import_request.php', (string)$uid, $admin ? '1' : '0', json_encode($params), $csv ?? '', $canCreate ? '1' : '0'];
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $code = preg_match('/HTTP_CODE=(\d+)/', $err, $m) ? (int)$m[1] : 0;
    if (preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $err . $out, $w)) echo "    ! PHP said '{$w[1]}': " . substr(trim(strip_tags($err . ' ' . $out)), 0, 400) . "\n";
    return [$code, json_decode($out, true), $out];
}

$tmpDir = sys_get_temp_dir();
function csvFile(string $name, string $content): string
{
    global $tmpDir;
    $p = $tmpDir . DIRECTORY_SEPARATOR . $name;
    file_put_contents($p, $content);
    return $p;
}

$adminId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$staffId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 0 AND COALESCE(u.is_admin,0) = 0 AND u.role_id <> 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$shops = array_map('intval', $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id")->fetchAll(PDO::FETCH_COLUMN));
if (!$adminId || !$staffId || !$shops) { echo "Need an admin, a non-admin user and an active warehouse.\n"; exit(1); }
$shop = $shops[0];
echo "Fixtures: admin #$adminId, staff #$staffId, shop #$shop (" . count($shops) . " active shop(s))\n";

$tag = 'PITEST' . bin2hex(random_bytes(3));
$created = ['products' => [], 'categories' => []];
$logStart = date('Y-m-d H:i:s', time() - 2);
$pdo->prepare("INSERT INTO categories (category_name, type, status) VALUES (?, 'product', 'active')")->execute(["$tag Existing Cat"]);
$existingCatId = (int)$pdo->lastInsertId();
$created['categories'][] = $existingCatId;

$header = "product_name,category,unit,cost_price,wholesale_price,selling_price,opening_stock,min_stock_level\n";
$countProducts = fn() => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE '%$tag%'")->fetchColumn();
$base = ['action' => 'commit', 'warehouse_id' => $shop];

try {
    // ═════════════════════════════════════════════════════════════════
    section('1. Guards');
    foreach (['core/product_import.php', 'api/import_products.php', 'app/bms/product/product_import.php'] as $f) {
        $rc = 0; $o = [];
        exec('php -l ' . escapeshellarg(ROOT_DIR . '/' . $f) . ' 2>&1', $o, $rc);
        ok($rc === 0, "lint $f");
    }
    $good = csvFile("$tag-good.csv", $header . "$tag Soap,$tag existing cat,pcs,900,1000,1200,30,5\n");
    [$c] = call($staffId, false, ['action' => 'preview'], $good, false);
    ok($c === 403, "a user without create permission is refused (got $c)");
    [$c] = call($adminId, true, ['action' => 'preview', '_csrf' => 'wrong'], $good);
    ok($c === 419, "a wrong CSRF token is refused (got $c)");
    [$c] = call($adminId, true, ['action' => 'preview'], null);
    ok($c === 422, "no file → 422 (got $c)");
    $txt = csvFile("$tag.txt", $header . "x,,,1,,2,,\n");
    [$c] = call($adminId, true, ['action' => 'preview'], $txt);
    ok($c === 422, "a non-CSV file → 422 (got $c)");
    $noCol = csvFile("$tag-nocol.csv", "product_name,category\nA,B\n");
    [$c, $j] = call($adminId, true, ['action' => 'preview'], $noCol);
    ok($c === 422 && strpos($j['message'] ?? '', 'cost_price') !== false, 'a missing required column → 422 naming the column');

    // ═════════════════════════════════════════════════════════════════
    section('2. Preview classifies every row and writes nothing');
    // Excel-style: BOM + semicolons + thousands separators.
    $mixed = csvFile("$tag-mixed.csv", "\xEF\xBB\xBF" . str_replace(',', ';', $header)
        . "$tag Rice;$tag existing cat;kg;1600;;\"2,600\";50;15\n"
        . "  $tag   rice ;Vyakula;kg;1600;;2600;5;0\n"
        . "$tag Bad;Vyakula;pcs;abc;;2600;5;0\n");
    $before = $countProducts();
    [$c, $j] = call($adminId, true, ['action' => 'preview'], $mixed);
    $st = array_column($j['plan'] ?? [], 'status');
    ok($c === 200 && $st === ['new', 'duplicate', 'error'], 'BOM + ";" + "2,600" read; rows: new / duplicate (same name, other case+spaces) / error — got ' . json_encode($st));
    ok(($j['plan'][0]['selling_price'] ?? null) == 2600, '"2,600" is read as 2600');
    ok($countProducts() === $before, 'preview saved nothing');

    // ═════════════════════════════════════════════════════════════════
    section('3. One bad row → nothing is saved');
    [$c, $j] = call($adminId, true, $base, $mixed);
    ok($c === 422 && $countProducts() === $before, "commit of a file with an error row → 422, no product saved (got $c)");

    // ═════════════════════════════════════════════════════════════════
    section('4. Commit');
    if (count($shops) > 1) {
        [$c] = call($adminId, true, ['action' => 'commit'], $good);
        ok($c === 422 && $countProducts() === $before, 'several shops and none chosen → 422, nothing saved');
    }
    [$c, $j] = call($adminId, true, $base, $good);
    ok($c === 200 && ($j['created'] ?? 0) === 1, 'first file: 1 product imported');
    $file = csvFile("$tag-main.csv", $header
        . "$tag SOAP ,$tag existing cat,pcs,900,1000,1200,30,5\n"      // exists (case + space)
        . "$tag Rice,$tag existing cat,kg,1600,,2600,50,15\n"          // new, existing category (other case)
        . "$tag Flour,$tag New Cat,kg,640,1100,1200,15,10\n"            // new, new category
        . "$tag Matches,$tag new cat,,3500,,4500,0,2\n"                 // new, same new category, no unit, no stock
        . "$tag Flour,$tag New Cat,kg,640,1100,1200,15,10\n");          // duplicate in the file
    [$c, $j] = call($adminId, true, ['action' => 'preview', 'warehouse_id' => $shop], $file);
    ok(json_encode(array_column($j['plan'] ?? [], 'status')) === '["exists","new","new","new","duplicate"]', 'preview: exists / new / new / new / duplicate');
    ok(($j['summary']['new_categories'] ?? []) === ["$tag New Cat"], 'preview lists the one new category once');
    [$c, $j] = call($adminId, true, $base, $file);
    ok($c === 200 && ($j['created'] ?? 0) === 3 && ($j['skipped'] ?? 0) === 2, "commit: 3 imported, 2 skipped (got $c, " . json_encode([$j['created'] ?? null, $j['skipped'] ?? null]) . ')');
    ok(($j['categories_created'] ?? []) === ["$tag New Cat"], 'exactly one category created');

    $rows = $pdo->query("SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON c.category_id = p.category_id
                          WHERE p.product_name LIKE '%$tag%' ORDER BY p.product_id")->fetchAll(PDO::FETCH_ASSOC);
    $created['products'] = array_map(fn($r) => (int)$r['product_id'], $rows);
    $byName = []; foreach ($rows as $r) $byName[$r['product_name']] = $r;
    ok(count($rows) === 4, '4 products in total (1 from the first file + 3)');
    ok(count(array_unique(array_column($rows, 'sku'))) === 4 && count(array_unique(array_column($rows, 'barcode'))) === 4, 'every SKU and barcode is unique');
    ok(($byName["$tag Rice"]['category_id'] ?? 0) == $existingCatId, 'an existing category is reused (matched case-insensitively)');
    $newCatId = (int)($byName["$tag Flour"]['category_id'] ?? 0);
    if ($newCatId) $created['categories'][] = $newCatId;
    ok($newCatId && $newCatId === (int)($byName["$tag Matches"]['category_id'] ?? -1), 'two rows with the new category share it (created once)');
    ok(($byName["$tag Matches"]['unit'] ?? '') === 'pcs', 'an empty unit becomes pcs');
    ok(($byName["$tag Rice"]['product_code'] ?? '') === ($byName["$tag Rice"]['sku'] ?? 'x') && $byName["$tag Rice"]['status'] === 'active', 'product_code = SKU, status active');

    $flour = (int)$byName["$tag Flour"]['product_id'];
    $stock = $pdo->prepare("SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
    $stock->execute([$flour, $shop]);
    ok((float)$stock->fetchColumn() === 15.0, 'opening stock is in the chosen shop (15)');
    $batch = $pdo->prepare("SELECT COUNT(*) FROM product_batches WHERE product_id = ?");
    $batch->execute([$flour]);
    ok((int)$batch->fetchColumn() === 1, 'it arrived as a batch');
    $je = $pdo->prepare("SELECT je.entry_id, je.status,
                                SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE 0 END) dr,
                                SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END) cr
                           FROM stock_movements sm
                           JOIN journal_entries je ON je.entity_type = 'stock_adjustment' AND je.entity_id = sm.movement_id
                           JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                          WHERE sm.product_id = ? GROUP BY je.entry_id, je.status");
    $je->execute([$flour]);
    $e = $je->fetch(PDO::FETCH_ASSOC);
    ok($e && abs((float)$e['dr'] - 9600) < 0.01 && abs((float)$e['cr'] - 9600) < 0.01 && $e['status'] === 'posted',
        'ledger: Dr = Cr = 15 × 640 = 9,600, posted (got ' . json_encode($e) . ')');
    $matches = (int)$byName["$tag Matches"]['product_id'];
    $batch->execute([$matches]);
    $je->execute([$matches]);
    ok((int)$batch->fetchColumn() === 0 && !$je->fetch(), 'zero opening stock → no batch, no ledger entry');
    $wp = $pdo->prepare("SELECT COUNT(*) FROM product_price_group_prices WHERE product_id = ? AND price = 1100");
    $wp->execute([$flour]);
    ok((int)$wp->fetchColumn() === 1, 'wholesale price written to the Wholesale price group');

    // ═════════════════════════════════════════════════════════════════
    section('5. Importing the same file again creates nothing');
    $n = $countProducts();
    [$c, $j] = call($adminId, true, $base, $file);
    ok($c === 422 && $countProducts() === $n, "every name exists → 422, nothing new (got $c)");
} finally {
    // ── Cleanup ─────────────────────────────────────────────────────────
    $ids = array_map('intval', $pdo->query("SELECT product_id FROM products WHERE product_name LIKE '%$tag%'")->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $in = implode(',', $ids);
        $mv = array_map('intval', $pdo->query("SELECT movement_id FROM stock_movements WHERE product_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
        if ($mv) {
            $mvIn = implode(',', $mv);
            $entries = array_map('intval', $pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type = 'stock_adjustment' AND entity_id IN ($mvIn)")->fetchAll(PDO::FETCH_COLUMN));
            if ($entries) {
                $eIn = implode(',', $entries);
                $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($eIn)");
                $pdo->exec("DELETE FROM journal_entries WHERE entry_id IN ($eIn)");
            }
            $pdo->exec("DELETE FROM stock_movements WHERE movement_id IN ($mvIn)");
        }
        foreach (['product_batches', 'product_stocks', 'product_price_group_prices'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    }
    $pdo->exec("DELETE FROM categories WHERE category_name LIKE '%$tag%'");
    try { $pdo->prepare("DELETE FROM activity_logs WHERE created_at >= ? AND description LIKE '%products from CSV%'")->execute([$logStart]); } catch (PDOException $e) {}
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . "$tag*") ?: [] as $f) @unlink($f);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE '%$tag%'")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'all test rows removed' : "$left TEST PRODUCTS REMAIN") . "\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}
