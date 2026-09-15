<?php
/**
 * Products — Simple POS Edit form simplification — CLI regression suite
 *   php tests/test_product_edit_simple_pos_cli.php
 *
 * Covers products_simple_pos_plan.md §5: app/bms/product/product_edit.php hides
 * the exact same fields Create hides (SKU, Barcode, Description, Tax, Wholesale
 * Price, Discount Rate, the whole Advanced Details tab, Physical Specifications)
 * when Simple POS is on and the "Advanced Product" override is off — but since
 * Edit deals with an EXISTING record, every hidden field must round-trip its
 * current value via a hidden input, or api/update_product.php's full-replace
 * write would silently wipe it. Two fields (is_taxable, is_combo) are read by
 * PRESENCE (isset), not value, so their hidden-preserve must only render when
 * the stored value is truthy — the opposite pattern from every other field.
 *
 *   A. STATIC   — file lints clean; source wiring for both branches present.
 *   B. RENDERED — the real page, three states: Simple POS, normal, and
 *                 Simple POS + Advanced Product override (full form back).
 *   C. RUNTIME  — the exact hidden-input payload the Simple POS render itself
 *                 produces is POSTed through api/update_product.php, then the
 *                 DB is checked field-by-field for zero data loss. Run twice:
 *                 once for a product with every "advanced" flag ON, once with
 *                 them OFF — the isset()-based fields must survive both ways.
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

function _pes_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'prodedit_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _pes_set_settings(string $root, string $simple, string $advanced): void {
    _pes_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_product', " . var_export($advanced, true) . "); echo 'SAVED';");
}
function _pes_render(string $root, int $uid, int $pid): string {
    return _pes_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_GET['id'] = $pid;
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/app/bms/product/product_edit.php';
        echo ob_get_clean();
    ");
}
// Extract every <input type="hidden" name="X" value="Y"> INSIDE the #productForm
// block only (the page has three other unrelated forms further down).
function _pes_extract_hidden(string $html): array {
    $start = strpos($html, '<form id="productForm"');
    $end = strpos($html, '</form>', $start === false ? 0 : $start);
    if ($start === false || $end === false) return [];
    $formHtml = substr($html, $start, $end - $start);
    $out = [];
    if (preg_match_all('/<input\s+type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/', $formHtml, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $name = html_entity_decode($row[1]);
            $val  = html_entity_decode($row[2]);
            // fields named like stock[3] aren't part of this preservation set
            if (strpos($name, '[') !== false) continue;
            $out[$name] = $val;
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach (['app/bms/product/product_edit.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$editSrc = src($root, 'app/bms/product/product_edit.php');
has($editSrc, '$simpleProductForm = posSimpleModeEnabled() && !advancedProductEnabled();', 'gating flag ANDs Simple POS with the Advanced Product override');
has($editSrc, '<input type="hidden" name="sku" value="<?= safe_output($product[\'sku\']) ?>">', 'SKU hidden-preserve on the else branch');
has($editSrc, '<input type="hidden" name="barcode" value="<?= safe_output($product[\'barcode\']) ?>">', 'Barcode hidden-preserve on the else branch');
has($editSrc, '<input type="hidden" name="description" value="<?= safe_output($product[\'description\']) ?>">', 'Description hidden-preserve on the else branch');
has($editSrc, '<input type="hidden" name="wholesale_price" value="<?= $product[\'wholesale_price\'] ?>">', 'Wholesale Price hidden-preserve');
has($editSrc, '<input type="hidden" id="discount_rate" name="discount_rate"', 'Discount Rate hidden-preserve keeps its id (so calculateMinSellingPrice() reads the real rate, not 0)');
has($editSrc, '<input type="hidden" name="tax_id" value="<?= safe_output($product[\'tax_id\']) ?>">', 'tax_id always hidden-preserved (value-based, safe unconditionally)');
has($editSrc, "<?php if (\$product['is_taxable']): ?>", 'is_taxable hidden input only rendered when truthy (isset()-based field on the API side)');
has($editSrc, '<input type="hidden" name="is_taxable" value="1">', 'is_taxable hidden-preserve emits value="1" only');
has($editSrc, "elseif (\$simpleProductForm && !empty(\$product['is_combo']))", 'is_combo hidden input only rendered when truthy, same isset()-based guard as is_taxable');
has($editSrc, '<input type="hidden" name="is_combo" value="1">', 'is_combo hidden-preserve emits value="1" only');
has($editSrc, '<input type="hidden" id="weight" name="weight"', 'Weight hidden-preserve');
has($editSrc, '<input type="hidden" id="dim_length" name="dim_length"', 'Dimension (L) hidden-preserve');
has($editSrc, '<input type="hidden" name="brand_id" value="<?= safe_output($product[\'brand_id\']) ?>">', 'Advanced Details tab: brand_id hidden-preserve');
has($editSrc, '<input type="hidden" name="manufacturer"', 'Advanced Details tab: manufacturer hidden-preserve');
has($editSrc, '<input type="hidden" name="warranty_period"', 'Advanced Details tab: warranty_period hidden-preserve');
has($editSrc, '<input type="hidden" name="guarantee_period"', 'Advanced Details tab: guarantee_period hidden-preserve');
has($editSrc, '<input type="hidden" name="expiry_days"', 'Advanced Details tab: expiry_days hidden-preserve');

// ─────────────────────────────────────────────────────────────────────────
section('3. Rendered HTML — three states, the real page, a real product');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wh  = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cat = $pdo->query("SELECT category_id FROM categories WHERE status='active' AND type='product' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$tax = $pdo->query("SELECT rate_id, rate_percentage FROM tax_rates WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$brand = $pdo->query("SELECT brand_id FROM brands WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$simpleModeBefore   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$advancedProdBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_advanced_product'")->fetchColumn();

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — sections 3-4 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];

    // Seed a fully "advanced" product directly — every field this phase hides
    // gets a distinctive, non-default value so we can prove it survives a
    // Simple POS edit untouched.
    function _pes_seed_product(PDO $pdo, string $name, array $overrides = []): int {
        $defaults = [
            'product_code' => null, 'product_name' => $name, 'description' => 'Seeded description text',
            'category_id' => null, 'brand_id' => null, 'unit' => 'pcs',
            'cost_price' => 1000.00, 'selling_price' => 1500.00, 'min_selling_price' => 1200.00,
            'wholesale_price' => 1300.00, 'discount_rate' => 12.50, 'tax_id' => null, 'tax_rate' => 0,
            'is_taxable' => 0, 'is_combo' => 0, 'is_service' => 0, 'track_inventory' => 1,
            'sku' => 'SEEDSKU' . rand(100000, 999999), 'barcode' => '69' . rand(1000000000, 2000000000),
            'barcode_symbology' => 'CODE128', 'weight' => 3.250, 'dimensions' => '10×20×30 cm',
            'manufacturer' => 'Acme Seeded Mfg', 'model' => 'Model-X9', 'serial_number' => 'SN-SEED-001',
            'warranty_period' => 12, 'warranty_unit' => 'months', 'guarantee_period' => 30, 'guarantee_unit' => 'days',
            'expiry_days' => 90, 'reorder_level' => 5, 'min_stock_level' => 2, 'max_stock_level' => 100,
            'status' => 'active', 'created_by' => 1,
        ];
        $data = array_merge($defaults, $overrides);
        $cols = array_keys($data);
        $sql = "INSERT INTO products (" . implode(',', $cols) . ") VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")";
        $pdo->prepare($sql)->execute($data);
        return (int)$pdo->lastInsertId();
    }

    $catId = $cat['category_id'] ?? null;
    $taxId = $tax['rate_id'] ?? null;
    $brandId = $brand['brand_id'] ?? null;

    $pidTaxableCombo = _pes_seed_product($pdo, 'CLI Edit Simple Test A ' . time() . '-' . rand(1000, 9999), [
        'category_id' => $catId, 'brand_id' => $brandId, 'tax_id' => $taxId,
        'is_taxable' => $taxId ? 1 : 0, 'is_combo' => 1,
    ]);
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 40, 0)")->execute([$pidTaxableCombo, $wid]);

    // State A: Simple POS on, Advanced Product off — the simplified render.
    _pes_set_settings($root, '1', '0');
    $simple = _pes_render($root, $uid, $pidTaxableCombo);

    lacks($simple, 'id="sku"', 'Simple POS render: SKU input field absent');
    lacks($simple, 'id="barcode"', 'Simple POS render: Barcode input field absent');
    lacks($simple, 'id="description"', 'Simple POS render: Description field absent');
    lacks($simple, 'id="tax_id"', 'Simple POS render: Tax Configuration select absent');
    lacks($simple, 'id="wholesale_price"', 'Simple POS render: Wholesale Price field absent');
    lacks($simple, 'id="edit_is_taxable"', 'Simple POS render: is_taxable checkbox absent');
    lacks($simple, 'id="brand_id"', 'Simple POS render: Brand select (Advanced tab) absent');
    lacks($simple, 'id="advanced-tab"', 'Simple POS render: Advanced Details tab BUTTON absent');
    lacks($simple, 'id="is_combo_toggle"', 'Simple POS render: is_combo toggle UI absent');
    has($simple, 'id="unit"', 'Simple POS render: Unit field still present (required)');
    has($simple, 'id="product_name"', 'Simple POS render: Product Name field still present (required)');

    $hidden = _pes_extract_hidden($simple);
    foreach (['sku', 'barcode', 'barcode_symbology', 'description', 'tax_id', 'wholesale_price', 'discount_rate',
              'brand_id', 'manufacturer', 'model', 'serial_number', 'warranty_period', 'warranty_unit',
              'guarantee_period', 'guarantee_unit', 'expiry_days', 'weight', 'dim_length', 'dim_width', 'dim_height',
              'reorder_level', 'min_stock_level', 'max_stock_level', 'min_selling_price', 'is_taxable', 'is_combo'] as $f) {
        array_key_exists($f, $hidden) ? pass("hidden-preserve input present for `$f`") : fail("hidden-preserve input MISSING for `$f`");
    }
    (($hidden['is_taxable'] ?? null) === '1') ? pass('is_taxable hidden value is "1" (product is taxable)') : fail('is_taxable hidden value wrong: ' . var_export($hidden['is_taxable'] ?? null, true));
    (($hidden['is_combo'] ?? null) === '1') ? pass('is_combo hidden value is "1" (product is a combo)') : fail('is_combo hidden value wrong: ' . var_export($hidden['is_combo'] ?? null, true));
    (($hidden['discount_rate'] ?? null) === '12.50') ? pass('discount_rate hidden value matches the real stored rate (not defaulted to 0)') : fail('discount_rate hidden mismatch: ' . var_export($hidden['discount_rate'] ?? null, true));

    // The page header already carries a persistent Save button wired via
    // form="productForm" (line ~533) — independent of which tab is active,
    // so it alone already guarantees the form stays submittable even with
    // the Advanced Details pane permanently display:none in Simple POS.
    has($simple, 'form="productForm"', 'Header-level Update button (form="productForm") present — submits regardless of active tab');
    // The Inventory tab's own footer additionally gets its own Update button
    // in Simple POS (since it's now the last visible tab, replacing the dead
    // "Next: Additional" link that used to target the now-absent advanced-tab).
    $inventoryPos = strpos($simple, 'id="inventory"');
    $advancedCommentPos = strpos($simple, 'Tab 4: Advanced Details');
    $inventoryFooterSubmit = false;
    if ($inventoryPos !== false && $advancedCommentPos !== false) {
        $inventorySection = substr($simple, $inventoryPos, $advancedCommentPos - $inventoryPos);
        $inventoryFooterSubmit = strpos($inventorySection, 'type="submit"') !== false;
        $inventoryFooterSubmit = $inventoryFooterSubmit && strpos($inventorySection, '#advanced-tab') === false;
    }
    $inventoryFooterSubmit
        ? pass('Inventory tab footer: dead "Next: Additional" link replaced with a real Update button')
        : fail('Inventory tab footer still has a dead link or is missing its own Update button');

    // State B: normal mode — fully unchanged.
    _pes_set_settings($root, '0', '0');
    $normal = _pes_render($root, $uid, $pidTaxableCombo);
    has($normal, 'id="advanced-tab"', 'Normal mode render: Advanced Details tab button present');
    has($normal, 'id="sku"', 'Normal mode render: SKU field present');
    has($normal, 'id="brand_id"', 'Normal mode render: Brand select present');
    has($normal, 'id="edit_is_taxable"', 'Normal mode render: is_taxable checkbox present');
    has($normal, "\$('#advanced-tab').tab('show')", 'Normal mode render: Inventory footer still links to Advanced Details (unchanged)');

    // State C: Simple POS + Advanced Product override — full form restored.
    _pes_set_settings($root, '1', '1');
    $override = _pes_render($root, $uid, $pidTaxableCombo);
    has($override, 'id="advanced-tab"', 'Simple POS + Advanced Product: Advanced Details tab restored');
    has($override, 'id="sku"', 'Simple POS + Advanced Product: SKU field restored');

    _pes_set_settings($root, (string)($simpleModeBefore === false ? '0' : $simpleModeBefore), (string)($advancedProdBefore === false ? '0' : $advancedProdBefore));

    // ─────────────────────────────────────────────────────────────────────
    section('4. Runtime — real Simple POS hidden-input payload through api/update_product.php');

    // 4a. The taxable/combo product: submit the SAME payload the render produced
    // (hidden inputs) + the still-visible simple fields, with only a genuine
    // visible-field edit (selling_price bumped), and confirm nothing else moved.
    _pes_set_settings($root, '1', '0');
    $payload = $hidden;
    $payload['product_id'] = $pidTaxableCombo;
    $payload['product_name'] = 'CLI Edit Simple Test A (edited)';
    $payload['category_id'] = $catId ?? '';
    $payload['cost_price'] = '1000';
    $payload['selling_price'] = '1600'; // the one real edit a Simple POS user makes
    $payload['unit'] = 'pcs';
    $payload['status'] = 'active';
    $payload['is_service'] = '0';
    $payload['track_inventory'] = '1';
    $payload['updated_by'] = $uid;

    $postCode = "\$_POST = " . var_export($payload, true) . "; \$_POST['stock'] = [$wid => '40'];";

    $out = _pes_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        $postCode
        ob_start();
        include '$root/api/update_product.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $res = json_decode($out, true);

    if (empty($res['success'])) {
        fail('update_product.php call failed: ' . ($res['message'] ?? $out));
    } else {
        pass('Simple POS hidden-input payload accepted by api/update_product.php');
        $p = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $p->execute([$pidTaxableCombo]);
        $after = $p->fetch(PDO::FETCH_ASSOC);

        ((float)$after['selling_price'] === 1600.0) ? pass('the genuine visible-field edit (selling_price) DID apply') : fail('selling_price edit did not apply');
        (strpos($after['sku'], 'SEEDSKU') === 0) ? pass('sku preserved') : fail('sku lost: ' . var_export($after['sku'], true));
        (strpos((string)$after['barcode'], '69') === 0) ? pass('barcode preserved') : fail('barcode lost');
        ($after['description'] === 'Seeded description text') ? pass('description preserved') : fail('description lost: ' . var_export($after['description'], true));
        ((int)$after['tax_id'] === (int)$taxId) ? pass('tax_id preserved') : fail('tax_id lost: ' . var_export($after['tax_id'], true));
        ((int)$after['is_taxable'] === 1) ? pass('is_taxable stayed 1 (was truthy, hidden input correctly present)') : fail('is_taxable flipped: ' . var_export($after['is_taxable'], true));
        ((int)$after['is_combo'] === 1) ? pass('is_combo stayed 1 (was truthy, hidden input correctly present)') : fail('is_combo flipped: ' . var_export($after['is_combo'], true));
        (abs((float)$after['wholesale_price'] - 1300.00) < 0.01) ? pass('wholesale_price preserved') : fail('wholesale_price lost: ' . $after['wholesale_price']);
        (abs((float)$after['discount_rate'] - 12.50) < 0.01) ? pass('discount_rate preserved (used its real id, not defaulted to 0)') : fail('discount_rate corrupted: ' . $after['discount_rate']);
        (abs((float)$after['weight'] - 3.250) < 0.001) ? pass('weight preserved') : fail('weight lost: ' . $after['weight']);
        ($after['dimensions'] === '10×20×30 cm') ? pass('dimensions preserved') : fail('dimensions lost: ' . var_export($after['dimensions'], true));
        ((int)$after['brand_id'] === (int)$brandId) ? pass('brand_id preserved') : fail('brand_id lost: ' . var_export($after['brand_id'], true));
        ($after['manufacturer'] === 'Acme Seeded Mfg') ? pass('manufacturer preserved') : fail('manufacturer lost');
        ($after['model'] === 'Model-X9') ? pass('model preserved') : fail('model lost');
        ($after['serial_number'] === 'SN-SEED-001') ? pass('serial_number preserved') : fail('serial_number lost');
        ((int)$after['warranty_period'] === 12 && $after['warranty_unit'] === 'months') ? pass('warranty period+unit preserved') : fail('warranty lost');
        ((int)$after['guarantee_period'] === 30 && $after['guarantee_unit'] === 'days') ? pass('guarantee period+unit preserved') : fail('guarantee lost');
        ((int)$after['expiry_days'] === 90) ? pass('expiry_days preserved') : fail('expiry_days lost');
        (abs((float)$after['min_selling_price'] - 1200.00) < 0.01 || abs((float)$after['min_selling_price'] - (1600 - 1600 * 0.125)) < 0.01)
            ? pass('min_selling_price stayed consistent with the real discount rate (not silently zeroed)')
            : fail('min_selling_price corrupted: ' . $after['min_selling_price']);
    }

    // 4b. A SECOND product with is_taxable=0 / is_combo=0 — the hidden inputs
    // for these two must be ABSENT (not present with value="0"), and after a
    // Simple POS edit they must stay 0, never flip to 1.
    $pidPlain = _pes_seed_product($pdo, 'CLI Edit Simple Test B ' . time() . '-' . rand(1000, 9999), [
        'category_id' => $catId, 'is_taxable' => 0, 'is_combo' => 0, 'tax_id' => null,
    ]);
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 10, 0)")->execute([$pidPlain, $wid]);

    $simpleB = _pes_render($root, $uid, $pidPlain);
    $hiddenB = _pes_extract_hidden($simpleB);
    (!array_key_exists('is_taxable', $hiddenB)) ? pass('is_taxable hidden input correctly ABSENT when the product is not taxable') : fail('is_taxable hidden input present with a non-taxable product — would force it to 1');
    (!array_key_exists('is_combo', $hiddenB)) ? pass('is_combo hidden input correctly ABSENT when the product is not a combo') : fail('is_combo hidden input present with a non-combo product — would force it to 1');

    $payloadB = $hiddenB;
    $payloadB['product_id'] = $pidPlain;
    $payloadB['product_name'] = 'CLI Edit Simple Test B (edited)';
    $payloadB['category_id'] = $catId ?? '';
    $payloadB['cost_price'] = '500';
    $payloadB['selling_price'] = '900';
    $payloadB['unit'] = 'pcs';
    $payloadB['status'] = 'active';
    $payloadB['is_service'] = '0';
    $payloadB['track_inventory'] = '1';
    $payloadB['updated_by'] = $uid;

    $postCodeB = "\$_POST = " . var_export($payloadB, true) . "; \$_POST['stock'] = [$wid => '10'];";
    $outB = _pes_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        $postCodeB
        ob_start();
        include '$root/api/update_product.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $resB = json_decode($outB, true);
    if (empty($resB['success'])) {
        fail('update_product.php (plain product) call failed: ' . ($resB['message'] ?? $outB));
    } else {
        $pB = $pdo->prepare("SELECT is_taxable, is_combo FROM products WHERE product_id = ?");
        $pB->execute([$pidPlain]);
        $afterB = $pB->fetch(PDO::FETCH_ASSOC);
        ((int)$afterB['is_taxable'] === 0) ? pass('is_taxable stayed 0 after Simple POS edit (no false-positive flip)') : fail('is_taxable incorrectly flipped to 1');
        ((int)$afterB['is_combo'] === 0) ? pass('is_combo stayed 0 after Simple POS edit (no false-positive flip)') : fail('is_combo incorrectly flipped to 1');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('5. Cleanup');
    foreach ([$pidTaxableCombo, $pidPlain] as $pid) {
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
        $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM product_batches WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id IN ($pidTaxableCombo, $pidPlain)")->fetchColumn();
    ($left === 0) ? pass('both test products fully cleaned up') : fail('test products not cleaned up');
}
