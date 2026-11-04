<?php
/**
 * tests/test_restaurant_menu_photos_cli.php — Menu page: photos, buying price, recipe cost,
 * S/No. on every restaurant table, names in the company's text style (2026-11-03).
 *
 *   A. Photo through the real api/restaurant/save_menu_item.php: a new dish with a PNG is saved
 *      under this company's uploads/…/products/ folder (server-made name, never the uploaded
 *      one); the menu list returns its address; a new photo replaces the old one (old file
 *      deleted); "remove photo" clears it; a fake image and a photo over 2 MB are refused with
 *      nothing saved; a save that fails after the upload leaves no file behind.
 *   B. A dish edit that does not send a plate guess keeps what is stored (no silent wipe).
 *   C. Recipe amounts keep 3 decimal places: 5 g of salt = 0.005 kg is stored as 0.005 (was
 *      0.01), and the recipe cost uses it (0.005 × 2,000 = 10).
 *   D. Pages: S/No. first on every restaurant table; names through caseFormatJs; no plate guess.
 *
 * A/B/C run in-process inside one rolled-back transaction; photo files made are removed.
 * Exit 0 = all pass.
 */
// A CLI run cannot make a real upload: copy the file instead (web requests use the real check).
function restaurantMoveUploadedPhoto(string $tmp, string $dest): bool { return is_file($tmp) && copy($tmp, $dest); }

require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/restaurant_scope.php";

$tmpDir = sys_get_temp_dir() . '/rmphoto_' . bin2hex(random_bytes(3));
@mkdir($tmpDir);
$img = function (string $type) use ($tmpDir): string {
    $im = imagecreatetruecolor(40, 30);
    imagefill($im, 0, 0, imagecolorallocate($im, 13, 110, 253));
    $f = "$tmpDir/p_" . bin2hex(random_bytes(3)) . ".$type";
    $type === 'png' ? imagepng($im, $f) : imagejpeg($im, $f);
    imagedestroy($im);
    return $f;
};
$file = fn(string $path, string $name, ?int $size = null) => ['name' => $name, 'type' => 'image/x', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => $size ?? filesize($path)];
$made = [];   // photo files written into uploads/, removed at the end
$abs = fn(?string $rel) => $rel ? $root . '/' . $rel : '';
$save = function (array $post, ?array $photo = null) {
    $_FILES = $photo ? ['photo' => $photo] : [];
    $r = call('api/restaurant/save_menu_item.php', 'POST', $post);
    $_FILES = [];
    return $r;
};

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $urlOf = fn($pid) => val("SELECT image_url FROM products WHERE product_id = ?", [$pid]) ?: null;

    section('A. Photo');
    $png = $img('png');
    $r = $save(['warehouse_id' => $W, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1'],
               $file($png, '../../evil.php.png'));
    ok(!empty($r['success']), 'new dish with a photo saved' . (!empty($r['success']) ? '' : ' — ' . ($r['message'] ?? '')));
    $DISH = (int)($r['product_id'] ?? 0);
    $u1 = $urlOf($DISH); $made[] = $u1;
    ok((bool)preg_match('#^uploads/(?:t\d+/)?products/menu_[0-9a-f]{16}\.png$#', (string)$u1), "stored as this company's uploads/…/products/menu_<random>.png ($u1) — not the uploaded name");
    ok($u1 && is_file($abs($u1)) && filesize($abs($u1)) === filesize($png), 'the file is really there');
    $list = call('api/restaurant/get_menu_items.php', 'GET', ['warehouse_id' => $W]);
    $hit = null; foreach (($list['data'] ?? []) as $it) if ((int)$it['product_id'] === $DISH) $hit = $it;
    ok($hit && $hit['image'] !== '' && str_ends_with($hit['image'], basename((string)$u1)), 'the menu list returns the photo address');

    $jpg = $img('jpg');
    $r = $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1'],
               $file($jpg, 'new.jpg'));
    $u2 = $urlOf($DISH); $made[] = $u2;
    ok(!empty($r['success']) && $u2 !== $u1 && str_ends_with((string)$u2, '.jpg') && is_file($abs($u2)), 'a new photo replaces it');
    ok(!is_file($abs($u1)), 'the old photo file is deleted');

    $r = $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1']);
    ok(!empty($r['success']) && $urlOf($DISH) === $u2, 'saving without a photo keeps the photo');

    $r = $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1', 'remove_photo' => '1']);
    ok(!empty($r['success']) && $urlOf($DISH) === null && !is_file($abs($u2)), '"Remove" clears the photo and deletes the file');

    $countFiles = fn() => count(glob($root . '/' . bmsUploadsRel('products') . 'menu_*') ?: []);
    $before = $countFiles();
    $fake = "$tmpDir/fake.png"; file_put_contents($fake, "<?php echo 'not an image'; ?>");
    $r = $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1'],
               $file($fake, 'photo.png'));
    ok(empty($r['success']) && $urlOf($DISH) === null && $countFiles() === $before, 'a fake image (PHP named .png) is refused, nothing saved (' . ($r['message'] ?? '') . ')');
    $r = $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1'],
               $file($img('png'), 'big.png', 3 * 1024 * 1024));
    ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), '2 MB') !== false && $countFiles() === $before, 'a photo over 2 MB is refused');

    // A save that fails AFTER the photo was stored (a name already in use) must not leave the file.
    $pdo->prepare("INSERT INTO products (product_name, selling_price, is_service, status) VALUES (?, 1, 1, 'active')")->execute(["MP Taken $sfx"]);
    $r = $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Taken $sfx", 'selling_price' => 6000, 'prep_area' => 'kitchen', 'is_available' => '1'],
               $file($img('png'), 'x.png'));
    ok(empty($r['success']) && $countFiles() === $before, 'a save that fails (name in use) leaves no photo file behind');

    section('B. A dish edit keeps a stored plate guess it did not send');
    $pdo->prepare("UPDATE products SET purchase_price = 900 WHERE product_id = ?")->execute([$DISH]);
    $save(['warehouse_id' => $W, 'product_id' => $DISH, 'item_type' => 'dish', 'product_name' => "MP Pilau $sfx", 'selling_price' => 6500, 'prep_area' => 'kitchen', 'is_available' => '1']);
    ok((float)val("SELECT purchase_price FROM products WHERE product_id = ?", [$DISH]) == 900.0 && (float)val("SELECT selling_price FROM products WHERE product_id = ?", [$DISH]) == 6500.0, 'price changed, stored guess untouched (not wiped to 0)');

    section('C. Recipe amounts keep 3 decimals');
    $ing = call('api/restaurant/recipe.php', 'POST', ['action' => 'new_ingredient', 'warehouse_id' => $W, 'name' => "MP Salt $sfx", 'unit' => 'kg', 'cost_price' => 2000]);
    $SALT = (int)($ing['id'] ?? 0);
    $ok = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'components' => json_encode([['product_id' => $SALT, 'qty' => 0.005]])]);
    ok(!empty($ok['success']), 'recipe with 5 g (0.005 kg) of salt saved' . (!empty($ok['success']) ? '' : ' — ' . ($ok['message'] ?? '')));
    $q = (float)val("SELECT qty_per_unit FROM product_assembly_components WHERE parent_product_id = ? AND component_product_id = ?", [$DISH, $SALT]);
    ok(abs($q - 0.005) < 0.00001, "stored 0.005 exactly (got $q; was 0.01 before)");
    $list = call('api/restaurant/get_menu_items.php', 'GET', ['warehouse_id' => $W]);
    $hit = null; foreach (($list['data'] ?? []) as $it) if ((int)$it['product_id'] === $DISH) $hit = $it;
    ok($hit && $hit['has_recipe'] && abs($hit['recipe_cost'] - 10) < 0.001, 'recipe cost = 0.005 × 2,000 = 10');
    $ok = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'components' => json_encode([['product_id' => $SALT, 'qty' => 0.0004]])]);
    ok(empty($ok['success']) || (float)val("SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = ?", [$DISH]) == 0, 'less than 1 g (0.0004 → 0.000) is not stored as an ingredient');

    section('E. Cooking by the pot: "1 pot = 20 plates"');
    $mkIng = function (string $n, string $unit, float $cost) use ($W, $sfx, $pdo) {
        $r = call('api/restaurant/recipe.php', 'POST', ['action' => 'new_ingredient', 'warehouse_id' => $W, 'name' => "$n $sfx", 'unit' => $unit, 'cost_price' => $cost]);
        $id = (int)($r['id'] ?? 0);
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 50, 0)")->execute([$id, $W]);
        $pdo->prepare("UPDATE products SET stock_quantity = 50, current_stock = 50 WHERE product_id = ?")->execute([$id]);
        return $id;
    };
    $RICE = $mkIng('MP Rice', 'kg', 3000); $OIL = $mkIng('MP Oil', 'litre', 8000); $PINCH = $mkIng('MP Pepper', 'kg', 20000);
    // Whole pot: 5 kg rice, 0.5 litre oil (500 ml), 10 g pepper; makes 20 plates.
    $ok = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'yield' => 20,
        'components' => json_encode([['product_id' => $RICE, 'qty' => 5], ['product_id' => $OIL, 'qty' => 0.5], ['product_id' => $PINCH, 'qty' => 0.01]])]);
    ok(!empty($ok['success']), 'pot recipe saved (5 kg rice, 500 ml oil, 10 g pepper → 20 plates)' . (!empty($ok['success']) ? '' : ' — ' . ($ok['message'] ?? '')));
    $rows = []; foreach ($pdo->query("SELECT component_product_id, qty_per_unit, total_qty FROM product_assembly_components WHERE parent_product_id = $DISH")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['component_product_id']] = $r;
    ok((int)val("SELECT recipe_yield FROM products WHERE product_id = ?", [$DISH]) === 20, 'the dish remembers: 20 plates');
    ok(abs($rows[$RICE]['qty_per_unit'] - 0.25) < 1e-9 && abs($rows[$RICE]['total_qty'] - 5) < 1e-9, 'rice: whole pot 5 kg kept, one plate 0.25 kg');
    ok(abs($rows[$OIL]['qty_per_unit'] - 0.025) < 1e-9, 'oil: one plate 0.025 litre (25 ml)');
    ok(abs($rows[$PINCH]['qty_per_unit'] - 0.001) < 1e-9 && abs($rows[$PINCH]['total_qty'] - 0.01) < 1e-9, 'pepper: 10 g ÷ 20 = 0.5 g → kept as 1 g so it stays in the recipe and leaves stock');
    $g = call('api/restaurant/recipe.php', 'GET', ['action' => 'get', 'product_id' => $DISH, 'warehouse_id' => $W]);
    $byId = []; foreach (($g['components'] ?? []) as $c) $byId[$c['product_id']] = $c;
    ok(($g['yield'] ?? 0) === 20 && abs(($byId[$RICE]['pot_qty'] ?? 0) - 5) < 1e-9 && abs(($byId[$RICE]['qty'] ?? 0) - 0.25) < 1e-9, 'reopening shows 20 plates and the whole-pot amounts');
    $plateCost = 0.25 * 3000 + 0.025 * 8000 + 0.001 * 20000;   // 750 + 200 + 20 = 970
    ok(abs(($g['cost'] ?? 0) - $plateCost) < 0.01, "cost of one plate = $plateCost");
    $list = call('api/restaurant/get_menu_items.php', 'GET', ['warehouse_id' => $W]);
    $hit = null; foreach (($list['data'] ?? []) as $it) if ((int)$it['product_id'] === $DISH) $hit = $it;
    ok($hit && abs($hit['recipe_cost'] - $plateCost) < 0.01, 'the Menu list shows the same cost of one plate');

    // Sell 2 plates for real: stock and cost of goods use one plate's share × 2.
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-MP', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $before = (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$RICE, $W]);
    $sale = call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null,
        'items' => [['product_id' => $DISH, 'product_name' => 'x', 'quantity' => 2, 'price' => 6500, 'discounted_price' => 6500, 'tax_rate' => 0]],
        'subtotal' => 13000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 13000, 'payment_method' => 'cash',
        'amount_tendered' => 13000, 'change_given' => 0, 'amount_paid' => 13000, 'sale_type' => 'dine_in'], true);
    ok(!empty($sale['success']), '2 plates sold' . (!empty($sale['success']) ? '' : ' — ' . ($sale['message'] ?? '')));
    $after = (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$RICE, $W]);
    ok(abs(($before - $after) - 0.5) < 1e-9, 'rice stock down by 0.5 kg (2 × 0.25)');
    $cogs = (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                         WHERE je.status = 'posted' AND je.entity_type = 'pos_cogs' AND je.entity_id = ? AND jei.type = 'debit'", [(int)($sale['sale_id'] ?? 0)]);
    ok(abs($cogs - 2 * $plateCost) < 0.01, 'ledger cost of goods = 2 plates × ' . $plateCost . ' = ' . (2 * $plateCost) . " (got $cogs)");

    $bad = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'yield' => 0, 'components' => json_encode([['product_id' => $RICE, 'qty' => 1]])]);
    ok(empty($bad['success']) && (int)val("SELECT recipe_yield FROM products WHERE product_id = ?", [$DISH]) === 20, '0 plates is refused, the recipe is unchanged');
    $one = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'components' => json_encode([['product_id' => $RICE, 'qty' => 0.3]])]);
    ok(!empty($one['success']) && (int)val("SELECT recipe_yield FROM products WHERE product_id = ?", [$DISH]) === 1
        && abs((float)val("SELECT qty_per_unit FROM product_assembly_components WHERE parent_product_id = ?", [$DISH]) - 0.3) < 1e-9, 'without "plates" a recipe is for one plate, exactly as before (0.3 kg per plate)');
    $none = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'yield' => 20, 'components' => '[]']);
    ok(!empty($none['success']) && (int)val("SELECT recipe_yield FROM products WHERE product_id = ?", [$DISH]) === 1, 'removing the recipe sets the plates back to 1');
} finally {
    $pdo->endOuter();
    foreach ($made as $rel) if ($rel && is_file($abs($rel))) @unlink($abs($rel));
    array_map('unlink', glob("$tmpDir/*") ?: []); @rmdir($tmpDir);
}

section('D. Pages');
$src = fn($f) => str_replace("\r\n", "\n", file_get_contents("$root/$f"));
foreach (['app/bms/restaurant/menu.php' => 'menuTable', 'app/bms/restaurant/tables.php' => 'tablesTable', 'app/bms/restaurant/reservations.php' => 'resTable',
          'app/bms/restaurant/modifier_group.php' => 'groupsTable', 'app/bms/restaurant/purchases.php' => 'rpOweTable', 'app/bms/restaurant/sales_report.php' => 'rrTypeTable'] as $f => $id) {
    $s = $src($f); $p = strpos($s, 'id="' . $id . '"');
    $head = $p === false ? '' : substr($s, $p, 900);
    ok((bool)preg_match('/<th[^>]*>\s*<\?= t\(\'S\/No\.\'\) \?>/', $head), "$f: S/No. is the first column of #$id");
}
$sr = $src('app/bms/restaurant/sales_report.php');
ok(substr_count($sr, "t('S/No.')") === 2, 'Restaurant Sales report: both tables start with S/No.');
$mg = $src('app/bms/restaurant/modifier_group.php');
ok(substr_count($mg, "t('S/No.')") === 3 && strpos($mg, '<th>#</th>') === false, 'Modifier groups: list, options and products tables start with S/No. ("#" gone)');
$mp = $src('app/bms/restaurant/menu.php');
ok(strpos($mp, "const nm = s => safeOutput(caseFormatJs(String(s || '')));") !== false && strpos($mp, 'safeOutput(it.product_name)') === false && strpos($mp, 'safeOutput(l.name)') === false, 'Menu: item, group and ingredient names follow the company text style');
ok(strpos($mp, 'name="photo"') !== false && strpos($mp, 'accept="image/jpeg,image/png,image/webp"') !== false && strpos($mp, 'function thumb(it)') !== false, 'Menu: photo picker in the form and a photo (or first letter) on each item');
ok(strpos($mp, 'id="f-plate"') === false && strpos($mp, 'plate_cost') === false && strpos($mp, 'RM.addRecipe') !== false, 'Menu: no plate guess; a dish without a recipe shows "Add recipe"');
ok(strpos($mp, 'class="form-control text-end rcp-qty" min="0" step="any"') !== false, 'Recipe: amounts can be typed with decimals or in g / ml');
$rc = $src('api/restaurant/recipe.php');
ok(strpos($rc, "round((float)(\$c['qty'] ?? 0), 3)") !== false, 'Recipe API keeps 3 decimals');
ok(strpos($mp, 'id="rcpYield"') !== false && strpos($mp, 'yield: RCP.yield') !== false, 'Recipe pop-up: "This recipe makes __ plates", sent with the recipe');
ok(strpos($mp, 'id="stNeedBtn"') !== false && strpos($mp, "it.is_service && !it.has_recipe") !== false, 'Menu: "Dishes without a recipe" count that shows only those dishes');

section('F. The real recipe pop-up code, run in Node (g / ml, plates)');
preg_match('/const RCP_KG = .*?\nfunction rcpShow\(l\).*?\n/s', $mp, $mH);
preg_match('/function rcpFmtQty\(q, unit\) \{.*?\n}\n/s', $mp, $mF);
preg_match('/function rcpSetQty\(i, v\) \{.*?\n}\n/s', $mp, $mS);
preg_match('/function rcpTotals\(\) \{.*?\n}\n/s', $mp, $mT);
ok(!empty($mH) && !empty($mF) && !empty($mS) && !empty($mT), 'recipe functions found in menu.php');
if (preg_match('/^v\d+/', trim((string)shell_exec('node -v 2>&1'))) && $mH && $mF && $mS && $mT) {
    $js = "let RCP = { yield: 1, price: 6500, lines: [] };\nconst safeOutput = s => String(s); const fmt = n => String(n);\nconst out = {}; const dom = {};\n"
        . "const \$ = sel => ({ text: v => { dom[sel] = v; return { toggleClass: () => {} }; }, toggleClass: () => ({}) });\nfunction rcpRender() {}\n"
        . $mH[0] . $mF[0] . $mS[0] . $mT[0] . <<<'JS'
RCP.lines = [{ unit: 'kg', du: 'g', qty: 0, cost: 3000 }, { unit: 'litre', du: 'ml', qty: 0, cost: 8000 }, { unit: 'pcs', du: 'pcs', qty: 0, cost: 500 }];
rcpSetQty(0, '250'); out.g = RCP.lines[0].qty;             // 250 g → 0.25 kg
rcpSetQty(1, '500'); out.ml = RCP.lines[1].qty;            // 500 ml → 0.5 litre
rcpSetQty(2, '3'); out.pcs = RCP.lines[2].qty;              // pieces stay as typed
RCP.lines[0].du = 'kg'; rcpSetQty(0, '5'); out.kg = RCP.lines[0].qty;
out.show = [rcpShow({ unit: 'kg', du: 'g', qty: 0.25 }), rcpShow({ unit: 'kg', du: 'kg', qty: 0.25 })];
out.small = [rcpSmall('Kg'), rcpSmall('ltr'), rcpSmall('pcs')];
RCP.yield = 20; RCP.lines = [{ unit: 'kg', qty: 5, cost: 3000 }, { unit: 'litre', qty: 0.5, cost: 8000 }, { unit: 'kg', qty: 0.01, cost: 20000 }];
out.per = RCP.lines.map(rcpPerPlate);
out.note = rcpFmtQty(rcpPerPlate(RCP.lines[1]), 'litre');
rcpTotals(); out.pot = dom['#rcpPotCost']; out.plate = dom['#rcpCost'];
console.log(JSON.stringify(out));
JS;
    $tf = tempnam(sys_get_temp_dir(), 'rcp') . '.js'; file_put_contents($tf, $js);
    $raw = (string)shell_exec('node ' . escapeshellarg($tf) . ' 2>&1'); @unlink($tf);
    $o = json_decode($raw, true);
    ok(is_array($o), 'node ran the recipe code' . (is_array($o) ? '' : ' — ' . substr($raw, 0, 300)));
    if (is_array($o)) {
        ok($o['g'] === 0.25 && $o['ml'] === 0.5 && $o['pcs'] === 3 && $o['kg'] === 5, 'typed 250 g → 0.25 kg, 500 ml → 0.5 litre, 3 pcs → 3, 5 kg → 5');
        ok($o['show'] === [250, 0.25], 'shown back as 250 g, or 0.25 when kg is chosen');
        ok($o['small'][0]['u'] === 'g' && $o['small'][1]['u'] === 'ml' && $o['small'][2] === null, 'kg → g and litre → ml offered; pieces have no small unit');
        ok($o['per'] === [0.25, 0.025, 0.001], 'one plate of a 20-plate pot: 0.25 kg rice, 0.025 l oil, pepper at least 1 g (same as the server)');
        ok($o['note'] === '25 ml', 'per-plate note reads "25 ml"');
        ok((string)$o['pot'] === '19200' && (string)$o['plate'] === '970', 'whole pot costs 19,200 (15,000 + 4,000 + 200); one plate 970 (same as the server)');
    }
} else {
    ok(false, 'node available for the recipe code check');
}
