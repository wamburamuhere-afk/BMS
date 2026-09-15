<?php
/**
 * Products — list page + Quick Add modal Simple POS simplification — CLI suite
 *   php tests/test_products_list_simple_pos_cli.php
 *
 * Covers products_simple_pos_plan.md §7: app/bms/product/products.php hides
 * the SKU column from the list table (hand-built <th>/<td>, not config-driven)
 * and applies the identical Simple POS field treatment to its own separate
 * "Quick Add Product" modal (its own SKU/Barcode/Description/Tax/Wholesale/
 * Discount/reorder-min-max/the whole Additional Details tab) as the main
 * Create form. Also fixes the last remaining "Store / Warehouse Name" ->
 * wLabel() terminology gap, and adds Manufacturing/Expiry Date fields to the
 * modal's Opening Stock section in EVERY mode (parity with the main Create
 * form's batch-date fields, per products_simple_pos_plan.md §1).
 *
 *   A. STATIC   — file lints clean; source wiring for both branches present.
 *   B. RENDERED — the real page, three states: Simple POS, normal, and
 *                 Simple POS + Advanced Product override.
 *   C. RUNTIME  — the exact Quick Add modal payload (no sku/barcode/tax/etc
 *                 typed by the user) posted through api/create_product.php,
 *                 confirming safe defaults and that the batch dates land.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }
function lacks(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — unexpectedly present `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function _pls_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'prodlist_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _pls_set_settings(string $root, string $simple, string $advanced): void {
    _pls_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_product', " . var_export($advanced, true) . "); echo 'SAVED';");
}
function _pls_render(string $root, int $uid): string {
    return _pls_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/app/bms/product/products.php';
        echo ob_get_clean();
    ");
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$full = "$root/app/bms/product/products.php";
$rc = 0; $out = [];
exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
$rc === 0 ? pass('app/bms/product/products.php') : fail('php -l failed: ' . implode(' ', $out));

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$listSrc = src($root, 'app/bms/product/products.php');
has($listSrc, '$simpleProductForm = posSimpleModeEnabled() && !advancedProductEnabled();', 'gating flag ANDs Simple POS with the Advanced Product override');
has($listSrc, "<?= wLabel('Store / Warehouse Name', 'Shop Name') ?>", 'terminology gap fixed: Store/Warehouse Name now uses wLabel()');
has($listSrc, 'name="manufacturing_date"', 'Quick Add modal: Manufacturing Date field added');
has($listSrc, 'name="expiry_date"', 'Quick Add modal: Expiry Date field added');
has($listSrc, '<input type="hidden" name="sku" id="modal_sku"', 'Quick Add modal: SKU hidden-generates in the simple branch');
has($listSrc, '<input type="hidden" name="barcode" id="modal_barcode"', 'Quick Add modal: Barcode hidden-generates in the simple branch');

// ─────────────────────────────────────────────────────────────────────────
section('3. Rendered HTML — three states, the real page');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$simpleModeBefore   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$advancedProdBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_advanced_product'")->fetchColumn();

if (!$uid) {
    pass('no admin user fixture available — sections 3-4 skipped (n/a)');
} else {
    // State A: Simple POS on, Advanced Product off.
    _pls_set_settings($root, '1', '0');
    $simple = _pls_render($root, $uid);

    lacks($simple, '<th width="12%">SKU</th>', 'Simple POS render: SKU column header absent from the list table');
    lacks($simple, 'class="custom-code">', 'Simple POS render: no SKU <code> cell rendered in any row');
    lacks($simple, 'id="modal_tax_id"', 'Simple POS render: Quick Add modal Tax Rate select absent');
    lacks($simple, 'id="modal_taxable"', 'Simple POS render: Quick Add modal is_taxable checkbox absent');
    lacks($simple, 'name="wholesale_price" value="0.00" step', 'Simple POS render: Quick Add modal Wholesale Price input absent');
    lacks($simple, 'id="tab4-tab"', 'Simple POS render: Quick Add modal Additional Details tab button absent');
    lacks($simple, 'id="tab4" role="tabpanel"', 'Simple POS render: Quick Add modal Additional Details tab pane absent');
    has($simple, 'id="modal_manufacturing_date"', 'Simple POS render: Manufacturing Date still present (all-modes field)');
    has($simple, 'id="modal_expiry_date"', 'Simple POS render: Expiry Date still present (all-modes field)');
    has($simple, 'id="tab1-tab"', 'Simple POS render: Quick Add modal Basic Info tab still present');

    // State B: normal mode — fully unchanged.
    _pls_set_settings($root, '0', '0');
    $normal = _pls_render($root, $uid);
    has($normal, '<th width="12%">SKU</th>', 'Normal mode render: SKU column header present');
    has($normal, 'id="modal_tax_id"', 'Normal mode render: Quick Add modal Tax Rate select present');
    has($normal, 'id="tab4-tab"', 'Normal mode render: Quick Add modal Additional Details tab present');
    has($normal, 'id="modal_manufacturing_date"', 'Normal mode render: Manufacturing Date present (all-modes field)');

    // State C: Simple POS + Advanced Product override — full form restored.
    _pls_set_settings($root, '1', '1');
    $override = _pls_render($root, $uid);
    has($override, '<th width="12%">SKU</th>', 'Simple POS + Advanced Product: SKU column restored');
    has($override, 'id="tab4-tab"', 'Simple POS + Advanced Product: Additional Details tab restored');

    _pls_set_settings($root, (string)($simpleModeBefore === false ? '0' : $simpleModeBefore), (string)($advancedProdBefore === false ? '0' : $advancedProdBefore));
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — the real Quick Add modal payload, end to end');

$wh = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — section 4 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];
    $uniqueName = 'CLI QuickAdd Simple Test ' . time() . '-' . rand(1000, 9999);

    // Mirrors EXACTLY what the Simple POS Quick Add modal submits: sku/barcode
    // still present (server-generated, just hidden), no description/tax_id/
    // wholesale_price/brand_id/manufacturer/etc — plus the new batch dates.
    $out = _pls_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_POST = [
            'product_name'       => " . var_export($uniqueName, true) . ",
            'sku'                => 'QUICKADD' . time(),
            'barcode'            => '69' . rand(1000000000, 2000000000),
            'category_id'        => '',
            'cost_price'         => '300',
            'selling_price'      => '500',
            'min_selling_price'  => '0.00',
            'discount_rate'      => '0.00',
            'unit'               => 'pcs',
            'manufacturing_date' => '2026-03-01',
            'expiry_date'        => '2027-03-01',
            'status'             => 'active',
            'is_service'         => '0',
            'track_inventory'    => '1',
            'initial_stock'      => [$wid => '15'],
        ];
        ob_start();
        include '$root/api/create_product.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $res = json_decode($out, true);

    if (empty($res['success']) || empty($res['product_id'])) {
        fail('product creation failed: ' . ($res['message'] ?? $out));
    } else {
        pass('product created via the real endpoint with the Quick Add modal payload shape');
        $pid = (int)$res['product_id'];

        $p = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $p->execute([$pid]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);

        ($prod['description'] === null || $prod['description'] === '') ? pass('description left empty (never collected)') : fail('description unexpectedly set');
        ($prod['tax_id'] === null) ? pass('tax_id left null') : fail('tax_id unexpectedly set: ' . var_export($prod['tax_id'], true));
        ((int)$prod['is_taxable'] === 0) ? pass('is_taxable defaults to 0') : fail('is_taxable unexpectedly 1');
        ((float)$prod['wholesale_price'] === 0.0) ? pass('wholesale_price defaults to 0') : fail('wholesale_price unexpectedly set');
        ($prod['brand_id'] === null) ? pass('brand_id left null (Additional Details tab hidden)') : fail('brand_id unexpectedly set');
        ($prod['manufacturer'] === null) ? pass('manufacturer left null') : fail('manufacturer unexpectedly set');

        $batch = $pdo->prepare("SELECT * FROM product_batches WHERE product_id = ?");
        $batch->execute([$pid]);
        $b = $batch->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            fail('no product_batches row created for the opening stock');
        } else {
            pass('a real batch was created for the opening stock');
            ($b['manufacturing_date'] === '2026-03-01') ? pass('manufacturing_date flowed through to the batch') : fail('manufacturing_date mismatch: ' . var_export($b['manufacturing_date'], true));
            ($b['expiry_date'] === '2027-03-01') ? pass('expiry_date flowed through to the batch') : fail('expiry_date mismatch: ' . var_export($b['expiry_date'], true));
        }

        // Cleanup
        $mvStmt = $pdo->prepare("SELECT movement_id FROM stock_movements WHERE product_id = ?");
        $mvStmt->execute([$pid]);
        foreach ($mvStmt->fetchAll(PDO::FETCH_COLUMN) as $movementId) {
            $jeStmt = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'stock_adjustment' AND entity_id = ?");
            $jeStmt->execute([(int)$movementId]);
            foreach ($jeStmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
                $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entryId]);
                $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$entryId]);
            }
        }
        $pdo->prepare("DELETE FROM product_batches WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
        $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $pid")->fetchColumn();
        ($left === 0) ? pass('test product fully cleaned up') : fail('test product not cleaned up');
    }
}
