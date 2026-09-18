<?php
/**
 * Products — Simple POS Create form simplification — CLI regression suite
 *   php tests/test_product_create_simple_pos_cli.php
 *
 * Covers products_simple_pos_plan.md §3-4: app/bms/product/product_create.php
 * collapses from 4 tabs to one single-section form when Simple POS is on and
 * the superadmin "Advanced Product" override is off — SKU/Barcode still
 * auto-generate (as hidden inputs), Description/Tax/the whole Advanced
 * Details tab disappear, "Cost Price" becomes "Buying Price", the Shop
 * picker only appears with >1 shop in scope, and Manufacturing/Expiry Date
 * are new fields feeding straight into the real batch Phase 1 wired up.
 * Updated 2026-09-18: Wholesale Price is no longer hidden — it now shows as
 * a real field alongside Buying/Retail Price, in the same 3-column layout as
 * the POS Restock modal, and "Selling Price" is relabeled "Retail Price" to
 * match Restock's terminology exactly (both requests: "bei ya jumla" /
 * "bei ya rejareja" should be visible on Create the same way they are there).
 *
 *   A. STATIC   — files lint clean; source wiring for both branches present.
 *   B. RENDERED — the real page, three states: Simple POS, normal, and
 *                 Simple POS + Advanced Product override (full form back).
 *   C. RUNTIME  — a real end-to-end product creation through the exact
 *                 payload the Simple POS form's JS produces, verifying every
 *                 hidden-field default AND the resulting batch/dates.
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

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function _pcs_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'prodcreate_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _pcs_set_settings(string $root, string $simple, string $advanced): void {
    _pcs_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_product', " . var_export($advanced, true) . "); echo 'SAVED';");
}
function _pcs_render(string $root, int $uid): string {
    // loadLanguage('en') forced AFTER header.php's own language resolution so
    // rendered-HTML assertions are deterministic regardless of this admin
    // user's stored language preference (which defaults to Swahili here) —
    // same fix as test_services_simple_pos_cli.php's _svc_render().
    return _pcs_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        \$_SESSION['user_lang'] = 'en';
        ob_start();
        include '$root/app/bms/product/product_create.php';
        echo ob_get_clean();
    ");
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach (['app/bms/product/product_create.php', 'app/bms/product/product_create_footer.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$createSrc = src($root, 'app/bms/product/product_create.php');
has($createSrc, '$simpleProductForm = posSimpleModeEnabled() && !advancedProductEnabled();', 'the gating flag ANDs Simple POS with the Advanced Product override');
has($createSrc, "\$showShopPicker = count(\$warehouses) > 1;", 'Shop picker only shown with a genuine choice');
has($createSrc, "manufacturing_date", 'Manufacturing Date field present in the simple branch');
has($createSrc, "expiry_date", 'Expiry Date field present in the simple branch');
has($createSrc, "<?php if (\$simpleProductForm): ?>", 'the simple branch is a real PHP conditional, not just hidden by CSS');

$footerSrc = src($root, 'app/bms/product/product_create_footer.php');
has($footerSrc, "#simple_shop_id", 'footer JS wires the Simple POS shop picker into initial_stock_data');
has($footerSrc, "#simple_opening_stock", 'footer JS wires the Simple POS opening-stock field');

// ─────────────────────────────────────────────────────────────────────────
section('3. Rendered HTML — three states, the real page');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$simpleModeBefore   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$advancedProdBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_advanced_product'")->fetchColumn();

if (!$uid) {
    pass('no admin user fixture available — sections 3-4 skipped (n/a)');
} else {
    // State A: Simple POS on, Advanced Product off — the simplified form.
    _pcs_set_settings($root, '1', '0');
    $simple = _pcs_render($root, $uid);
    has($simple, 'id="manufacturing_date"', 'Simple POS render: Manufacturing Date present');
    has($simple, 'id="expiry_date"', 'Simple POS render: Expiry Date present');
    has($simple, 'Buying Price', 'Simple POS render: "Cost Price" relabeled to "Buying Price"');
    (strpos($simple, 'id="sku"') === false) ? pass('Simple POS render: SKU input field absent') : fail('SKU field still visible in Simple POS');
    (preg_match('/<input type="hidden" name="sku"/', $simple) === 1) ? pass('Simple POS render: SKU still auto-generated as a hidden input') : fail('SKU hidden input missing — product would save with no SKU at all');
    (preg_match('/<input type="hidden" name="barcode"/', $simple) === 1) ? pass('Simple POS render: Barcode still auto-generated as a hidden input') : fail('Barcode hidden input missing');
    (strpos($simple, 'id="description"') === false) ? pass('Simple POS render: Description field absent') : fail('Description field still present');
    (strpos($simple, 'id="tax_id"') === false) ? pass('Simple POS render: Tax field absent') : fail('Tax field still present');
    // 2026-09-18 request: "bei ya jumla" (Wholesale Price) now shown here too,
    // in the same 3-column Buying/Wholesale/Retail layout as the POS Restock
    // modal — a real, visible, editable field, not the old hidden-only one.
    (preg_match('/<input type="number"[^>]*id="wholesale_price"/', $simple) === 1) ? pass('Simple POS render: Wholesale Price is a real, visible field (matches Restock)') : fail('Wholesale Price field is missing or still hidden-only');
    has($simple, 'Retail Price', 'Simple POS render: "Selling Price" relabeled to "Retail Price" (matches Restock)');
    (strpos($simple, 'id="discount_rate"') === false) ? pass('Simple POS render: Discount Rate field absent') : fail('Discount Rate field still present');
    (strpos($simple, 'id="brand_id"') === false) ? pass('Simple POS render: Advanced Details tab (Brand) absent') : fail('Advanced Details tab still present');
    (strpos($simple, 'id="weight"') === false) ? pass('Simple POS render: Weight/Dimensions absent') : fail('Weight field still present');
    (strpos($simple, 'id="productTabs"') === false) ? pass('Simple POS render: no tab navigation (collapsed to one section)') : fail('Tab navigation still present');

    // State B: normal mode — fully unchanged.
    _pcs_set_settings($root, '0', '0');
    $normal = _pcs_render($root, $uid);
    has($normal, 'id="productTabs"', 'Normal mode render: tab navigation present');
    has($normal, 'id="sku"', 'Normal mode render: SKU field present');
    has($normal, 'id="brand_id"', 'Normal mode render: Advanced Details tab present');
    has($normal, 'Cost Price / Purchase Price', 'Normal mode render: original "Cost Price" label unchanged');

    // State C: Simple POS + Advanced Product override — full form restored.
    _pcs_set_settings($root, '1', '1');
    $override = _pcs_render($root, $uid);
    has($override, 'id="productTabs"', 'Simple POS + Advanced Product: full tabbed form restored');
    has($override, 'id="brand_id"', 'Simple POS + Advanced Product: Advanced Details tab restored');

    _pcs_set_settings($root, (string)($simpleModeBefore === false ? '0' : $simpleModeBefore), (string)($advancedProdBefore === false ? '0' : $advancedProdBefore));
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — the real Simple POS payload, end to end');

$wh = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — section 4 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];
    $uniqueName = 'CLI Simple Create Test ' . time() . '-' . rand(1000, 9999);

    // This mirrors EXACTLY what the Simple POS form submits: no sku typed by
    // the user (still generated server-side same as before), no description/
    // tax_id/wholesale_price/brand_id/etc. at all, initial_stock_data built
    // the way the footer JS now builds it from the Shop picker + one qty field.
    $out = _pcs_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_POST = [
            'product_name'       => " . var_export($uniqueName, true) . ",
            'sku'                => 'PROD1234567890',
            'barcode'            => '6001234567890',
            'category_id'        => '',
            'cost_price'         => '500',
            'selling_price'      => '800',
            'min_selling_price'  => '800.00',
            'unit'               => 'pcs',
            'manufacturing_date' => '2026-02-01',
            'expiry_date'        => '2027-02-01',
            'status'             => 'active',
            'initial_stock_data' => json_encode([$wid => 25]),
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
        pass('product created via the real endpoint with the Simple POS payload shape');
        $pid = (int)$res['product_id'];

        $p = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $p->execute([$pid]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);

        ($prod['description'] === null || $prod['description'] === '') ? pass('description left empty (never collected)') : fail('description unexpectedly set: ' . $prod['description']);
        ($prod['tax_id'] === null) ? pass('tax_id left null (no tax, hidden field)') : fail('tax_id unexpectedly set: ' . var_export($prod['tax_id'], true));
        ((float)$prod['wholesale_price'] === 0.0) ? pass('wholesale_price defaults to 0') : fail('wholesale_price unexpectedly set: ' . $prod['wholesale_price']);
        ((int)$prod['is_service'] === 0) ? pass('is_service defaults to 0 (physical product)') : fail('is_service unexpectedly set');
        ((int)$prod['track_inventory'] === 1) ? pass('track_inventory still defaults to 1 even though the checkbox is not rendered') : fail('track_inventory not defaulting correctly');
        ((int)$prod['is_taxable'] === 0) ? pass('is_taxable defaults to 0 (consistent with no tax configured)') : fail('is_taxable unexpectedly 1 with no tax_id');

        $batch = $pdo->prepare("SELECT * FROM product_batches WHERE product_id = ?");
        $batch->execute([$pid]);
        $b = $batch->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            fail('no product_batches row created for the opening stock');
        } else {
            pass('a real batch was created for the opening stock (same Phase 1 wiring)');
            ($b['manufacturing_date'] === '2026-02-01') ? pass('manufacturing_date flowed through to the batch') : fail('manufacturing_date mismatch: ' . var_export($b['manufacturing_date'], true));
            ($b['expiry_date'] === '2027-02-01') ? pass('expiry_date flowed through to the batch') : fail('expiry_date mismatch: ' . var_export($b['expiry_date'], true));
            ((float)$b['quantity_received'] === 25.0) ? pass('opening stock quantity correct') : fail('quantity mismatch: ' . $b['quantity_received']);
        }

        // Cleanup — GL entry first (needs the movement id before that row is gone)
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
