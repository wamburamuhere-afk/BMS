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
ok(strpos($mp, 'min="0.001" step="any"') !== false, 'Recipe: amounts can be typed to 3 decimals (0.005)');
$rc = $src('api/restaurant/recipe.php');
ok(strpos($rc, "round((float)(\$c['qty'] ?? 0), 3)") !== false, 'Recipe API keeps 3 decimals');
