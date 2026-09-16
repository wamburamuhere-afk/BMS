<?php
/**
 * Services (Non-Inventory Products) — Simple POS registration simplification
 *   php tests/test_services_simple_pos_cli.php
 *
 * The Add/Edit Service modals in app/bms/product/services.php collapse to
 * Service Name + Amount to Sell (+ Shop, only if the user has >1 shop) when
 * Simple POS is on and the superadmin "Advanced Product" override is off —
 * same combined gate as Products (products_simple_pos_plan.md). Description,
 * Item Code, Unit, Qty, Cost Price, Tax Rate, Project, the Materials/BOM
 * table and the Step1/Step2 tab navigation disappear; normal tenants and the
 * Advanced Product override see the full form unchanged.
 *
 *   A. STATIC    — file lints clean; source wiring for both branches.
 *   B. ARITHMETIC — the showShopPicker/onlyWarehouseId auto-assign logic in isolation.
 *   C. RENDERED  — the real page, three states: Simple POS, normal, override.
 *   D. RUNTIME   — a real create through the exact Simple POS payload shape.
 *   E. RUNTIME   — an edit made through the Simple POS field set must never
 *                  wipe description/tax/cost/components that a fuller form
 *                  had set earlier (the exact class of bug already caught
 *                  once in update_expense.php this session).
 *   F. ENTITLEMENT — create/update must gate on canCreate/canEdit('products'),
 *                  NOT 'nip_materials' (owned by the 'procurement' feature).
 *                  Services are sales-only and never stock-tracked, so a
 *                  tenant restricted to Simple POS (Procurement off) must
 *                  still be able to register/edit a Service — this was the
 *                  "Access Denied: you do not have permission to create NIP
 *                  products" bug reported 2026-09-16.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 90) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function _svc_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'svcpos_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _svc_set_settings(string $root, string $simple, string $advanced): void {
    _svc_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_product', " . var_export($advanced, true) . "); echo 'SAVED';");
}
function _svc_render(string $root, int $uid): string {
    // loadLanguage('en') forced AFTER header.php's own language resolution so
    // rendered-HTML assertions are deterministic regardless of this admin
    // user's stored language preference (which defaults to Swahili here).
    return _svc_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        \$_SESSION['user_lang'] = 'en';
        ob_start();
        include '$root/app/bms/product/services.php';
        echo ob_get_clean();
    ");
}

// ─────────────────────────────────────────────────────────────────────────
section('1. File exists + lints clean');
$file = "$root/app/bms/product/services.php";
if (!file_exists($file)) { fail('MISSING: app/bms/product/services.php'); }
else {
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($file) . " 2>&1", $out, $rc);
    $rc === 0 ? pass('app/bms/product/services.php') : fail('php -l failed: ' . implode("\n", $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$svcSrc = src($root, 'app/bms/product/services.php');
has($svcSrc, '$simpleServiceForm = posSimpleModeEnabled() && !advancedProductEnabled();', 'gating flag ANDs Simple POS with the Advanced Product override');
has($svcSrc, '$showShopPicker    = count($warehouses) > 1;', 'shop picker only shown with a genuine choice');
has($svcSrc, "\$onlyWarehouseId   = (count(\$warehouses) === 1) ? (int)\$warehouses[0]['warehouse_id'] : 0;", 'single shop auto-assigns silently');
has($svcSrc, '<?php if (!$simpleServiceForm): ?>', 'nav headers wrapped in a real PHP conditional, not just CSS');
has($svcSrc, "<input type=\"hidden\" name=\"warehouse_id\" id=\"svc_warehouse_id\" value=\"<?= \$onlyWarehouseId ?>\">", 'Add form: single-shop warehouse_id becomes a hidden auto-assigned input');
has($svcSrc, "<input type=\"hidden\" name=\"warehouse_id\" id=\"edit_svc_warehouse_id\" value=\"<?= \$onlyWarehouseId ?>\">", 'Edit form: single-shop warehouse_id becomes a hidden auto-assigned input');
substr_count($svcSrc, '$simpleServiceForm && !$showShopPicker') === 2 ? pass('single-shop branch gated in both Add and Edit forms') : fail('expected the $simpleServiceForm && !$showShopPicker gate exactly twice');
substr_count($svcSrc, "d-none") >= 8 ? pass('multiple advanced fields carry a conditional d-none wrapper') : fail('expected several d-none wrapped fields, found too few');
has($svcSrc, "t('Amount to Sell')", 'Simple POS relabels Selling Price to "Amount to Sell"');
has($svcSrc, "SIMPLE_SERVICE_FORM = <?= json_encode(\$simpleServiceForm) ?>", 'PHP gate flag exposed to JS');
has($svcSrc, 'if (SIMPLE_SERVICE_FORM) return; // Simple POS: both sections always shown together, no tabs', 'tab-toggle functions guarded (both add and edit)');
substr_count($svcSrc, 'if (SIMPLE_SERVICE_FORM) return;') === 2 ? pass('both toggleSvcAddStep and toggleSvcEditStep guarded') : fail('expected exactly 2 SIMPLE_SERVICE_FORM guards in the toggle functions');
has($svcSrc, "if (!select || select.tagName !== 'SELECT') return;", 'filterWarehouses() no-ops when the target is a hidden auto-assigned input, not a <select>');
has($svcSrc, "if (editWarehouseField && editWarehouseField.tagName === 'SELECT')", 'openEditSvcModal() never overwrites the auto-assigned single-shop hidden field with the record\'s possibly-null stored value');
has($svcSrc, "if (!\$el.length || !\$el.is('select')) return;", 'Select2 init skips the warehouse field when it is a hidden input, not a <select>');

// ─────────────────────────────────────────────────────────────────────────
section('3. Arithmetic — showShopPicker / onlyWarehouseId in isolation');
foreach ([
    [[], false, 0],
    [[['warehouse_id' => 42]], false, 42],
    [[['warehouse_id' => 5], ['warehouse_id' => 9]], true, 0],
] as [$warehouses, $expectPicker, $expectOnly]) {
    $showShopPicker  = count($warehouses) > 1;
    $onlyWarehouseId = (count($warehouses) === 1) ? (int)$warehouses[0]['warehouse_id'] : 0;
    $n = count($warehouses);
    ($showShopPicker === $expectPicker) ? pass("count=$n: showShopPicker=" . var_export($expectPicker, true)) : fail("count=$n: showShopPicker mismatch");
    ($onlyWarehouseId === $expectOnly) ? pass("count=$n: onlyWarehouseId=$expectOnly") : fail("count=$n: onlyWarehouseId mismatch, got $onlyWarehouseId");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Rendered HTML — three states, the real page');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$simpleModeBefore   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$advancedProdBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_advanced_product'")->fetchColumn();

if (!$uid) {
    pass('no admin user fixture available — section 4 skipped (n/a)');
} else {
    // State A: Simple POS on, Advanced Product off.
    _svc_set_settings($root, '1', '0');
    $simple = _svc_render($root, $uid);
    (strpos($simple, 'id="svc_add_tab1"') === false) ? pass('Simple POS render: Add nav tabs absent') : fail('Add nav tabs still present');
    (strpos($simple, 'id="svc_edit_tab1"') === false) ? pass('Simple POS render: Edit nav tabs absent') : fail('Edit nav tabs still present');
    has($simple, 'Amount to Sell', 'Simple POS render: "Amount to Sell" label shown');
    (preg_match('/id="svcComponentTable"[\s\S]{0,5}/', $simple, $m0) && strpos(substr($simple, max(0, strpos($simple,'svcComponentTable')-400), 400), 'd-none') !== false)
        ? pass('Simple POS render: Add Materials/BOM table wrapper carries d-none')
        : fail('Add Materials/BOM table wrapper missing d-none');
    (strpos($simple, 'svc_add_step2') !== false && strpos($simple, 'id="svc_add_step2" style=""') !== false)
        ? pass('Simple POS render: Add step2 forced visible (no display:none)')
        : fail('Add step2 still display:none in Simple POS');

    // State B: normal mode — fully unchanged.
    _svc_set_settings($root, '0', '0');
    $normal = _svc_render($root, $uid);
    has($normal, 'id="svc_add_tab1"', 'Normal mode render: Add nav tabs present');
    has($normal, 'id="svc_edit_tab1"', 'Normal mode render: Edit nav tabs present');
    has($normal, 'Selling Price', 'Normal mode render: original "Selling Price" label unchanged');
    (strpos($normal, 'id="svc_add_step2" style="display:none;"') !== false) ? pass('Normal mode render: Add step2 still starts hidden (tabbed)') : fail('Normal mode step2 default visibility changed');
    (strpos($normal, '<div class="col-12">') !== false) ? pass('Normal mode render: Description field wrapper unaffected (no stray d-none)') : fail('Normal mode Description wrapper unexpectedly altered');

    // State C: Simple POS + Advanced Product override — full form restored.
    _svc_set_settings($root, '1', '1');
    $override = _svc_render($root, $uid);
    has($override, 'id="svc_add_tab1"', 'Simple POS + Advanced Product: full tabbed form restored');
    has($override, 'Selling Price', 'Simple POS + Advanced Product: "Selling Price" label restored');

    _svc_set_settings($root, (string)($simpleModeBefore === false ? '0' : $simpleModeBefore), (string)($advancedProdBefore === false ? '0' : $advancedProdBefore));
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — real Simple POS create payload, end to end');

$wh = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — section 5 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];
    $svcName = 'CLI Simple Service ' . time() . '-' . rand(1000, 9999);

    // Exactly what the Simple POS Add Service form submits: name + selling
    // price + shop, nothing else — description/tax/project/components never
    // sent at all, unit/status/is_service/track_inventory are hidden defaults.
    $out = _svc_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_POST = [
            'is_service'      => '1',
            'track_inventory' => '0',
            'unit'            => 'job',
            'status'          => 'active',
            'product_name'    => " . var_export($svcName, true) . ",
            'selling_price'   => '25000',
            'warehouse_id'    => '$wid',
        ];
        ob_start();
        include '$root/api/create_nip_product.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $res = json_decode($out, true);

    if (empty($res['success']) || empty($res['product_id'])) {
        fail('service creation failed: ' . ($res['message'] ?? $out));
    } else {
        pass('service created via the real endpoint with the Simple POS payload shape');
        $pid = (int)$res['product_id'];

        $p = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $p->execute([$pid]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);

        ((int)$prod['is_service'] === 1) ? pass('is_service = 1') : fail('is_service not set');
        ((int)$prod['track_inventory'] === 0) ? pass('track_inventory = 0') : fail('track_inventory unexpectedly set');
        ($prod['description'] === null) ? pass('description left null (never collected)') : fail('description unexpectedly set: ' . $prod['description']);
        ($prod['tax_id'] === null) ? pass('tax_id left null (no tax, hidden field)') : fail('tax_id unexpectedly set');
        ((float)$prod['cost_price'] === 0.0) ? pass('cost_price defaults to 0 (no materials)') : fail('cost_price unexpectedly set: ' . $prod['cost_price']);
        ((float)$prod['selling_price'] === 25000.0) ? pass('selling_price (Amount to Sell) saved correctly') : fail('selling_price mismatch: ' . $prod['selling_price']);
        ($prod['unit'] === 'job') ? pass('unit defaults to job') : fail('unit mismatch: ' . $prod['unit']);
        ((float)$prod['assembly_quantity'] === 1.0) ? pass('assembly_quantity defaults to 1') : fail('assembly_quantity mismatch');
        ((int)$prod['warehouse_id'] === $wid) ? pass('warehouse_id saved exactly as submitted — required for the product to appear in the Simple POS grid (api/pos/simple_products.php)') : fail('warehouse_id mismatch: ' . var_export($prod['warehouse_id'], true));
        (!empty($prod['contract_item_no'])) ? pass('contract_item_no auto-generated') : fail('contract_item_no missing');

        $compCount = (int)$pdo->query("SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = $pid")->fetchColumn();
        ($compCount === 0) ? pass('no BOM components created (none were submitted)') : fail("unexpected $compCount component rows created");

        // Cleanup
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
        $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $pid")->fetchColumn();
        ($left === 0) ? pass('test service fully cleaned up') : fail('test service not cleaned up');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — Simple POS edit must never wipe fields the full form set earlier');

$componentProduct = $pdo->query("SELECT product_id FROM products WHERE is_service = 0 AND status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$taxRate = $pdo->query("SELECT rate_id FROM tax_rates WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$uid || !$wh || !$componentProduct) {
    pass('no admin/warehouse/component-product fixture available — section 6 skipped (n/a)');
} else {
    $compPid = (int)$componentProduct['product_id'];
    $taxId = $taxRate ? (int)$taxRate['rate_id'] : null;
    $svcName = 'CLI Full Service ' . time() . '-' . rand(1000, 9999);

    // Step 1: create the service the way the FULL (normal-mode) form would —
    // description, tax, and one real material component set.
    $createOut = _svc_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_POST = [
            'is_service'      => '1',
            'track_inventory' => '0',
            'unit'            => 'job',
            'status'          => 'active',
            'product_name'    => " . var_export($svcName, true) . ",
            'description'     => 'Full description set by the advanced form',
            'selling_price'   => '10000',
            'cost_price'      => '3000',
            " . ($taxId ? "'tax_id' => '$taxId'," : '') . "
            'warehouse_id'    => '$wid',
            'components'      => [[ 'product_id' => '$compPid', 'unit' => 'pcs', 'qty_per_unit' => '2', 'total_qty' => '2' ]],
        ];
        ob_start();
        include '$root/api/create_nip_product.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $createRes = json_decode($createOut, true);

    if (empty($createRes['success']) || empty($createRes['product_id'])) {
        fail('setup: full-form service creation failed: ' . ($createRes['message'] ?? $createOut));
    } else {
        $pid = (int)$createRes['product_id'];
        $before = $pdo->query("SELECT * FROM products WHERE product_id = $pid")->fetch(PDO::FETCH_ASSOC);
        $beforeComp = (int)$pdo->query("SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = $pid")->fetchColumn();
        ($beforeComp === 1) ? pass('setup: one BOM component recorded before the Simple POS edit') : fail("setup: expected 1 component, found $beforeComp");
        ((float)$before['cost_price'] === 3000.0) ? pass('setup: cost_price = 3000 before edit') : fail('setup: cost_price mismatch');
        ($before['description'] !== null) ? pass('setup: description set before edit') : fail('setup: description missing before edit');

        // Step 2: edit through the Simple POS field set — name + amount +
        // shop only, plus the hidden-but-still-present component row the
        // (visually hidden) BOM table carries forward untouched, exactly as
        // openEditSvcModal()'s unconditional component fetch/populate does.
        $newName = $svcName . ' EDITED';
        $editOut = _svc_run_php("
            \$_SESSION = [];
            \$_SERVER['REQUEST_METHOD'] = 'POST';
            require '$root/roots.php';
            \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
            \$_POST = [
                'product_id'      => '$pid',
                'is_service'      => '1',
                'track_inventory' => '0',
                'unit'            => 'job',
                'status'          => 'active',
                'product_name'    => " . var_export($newName, true) . ",
                'selling_price'   => '12000',
                'warehouse_id'    => '$wid',
                'description'     => " . var_export($before['description'], true) . ",
                'cost_price'      => '" . $before['cost_price'] . "',
                " . ($before['tax_id'] ? "'tax_id' => '" . $before['tax_id'] . "'," : '') . "
                'components'      => [[ 'product_id' => '$compPid', 'unit' => 'pcs', 'qty_per_unit' => '2', 'total_qty' => '2' ]],
            ];
            ob_start();
            include '$root/api/update_nip_product.php';
            \$out = ob_get_clean();
            \$pos = strpos(\$out, '{');
            echo \$pos === false ? \$out : substr(\$out, \$pos);
        ");
        $editRes = json_decode($editOut, true);

        if (empty($editRes['success'])) {
            fail('Simple POS edit failed: ' . ($editRes['message'] ?? $editOut));
        } else {
            pass('Simple POS edit accepted');
            $after = $pdo->query("SELECT * FROM products WHERE product_id = $pid")->fetch(PDO::FETCH_ASSOC);
            $afterComp = (int)$pdo->query("SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = $pid")->fetchColumn();

            ($after['product_name'] === $newName) ? pass('product_name actually updated') : fail('product_name did not update');
            ((float)$after['selling_price'] === 12000.0) ? pass('selling_price (Amount to Sell) actually updated') : fail('selling_price did not update');
            ($after['description'] === $before['description']) ? pass('description PRESERVED across a Simple POS edit') : fail('description WIPED by Simple POS edit — data loss bug');
            ((float)$after['cost_price'] === (float)$before['cost_price']) ? pass('cost_price PRESERVED across a Simple POS edit') : fail('cost_price WIPED by Simple POS edit — data loss bug');
            ((string)$after['tax_id'] === (string)$before['tax_id']) ? pass('tax_id PRESERVED across a Simple POS edit') : fail('tax_id WIPED by Simple POS edit — data loss bug');
            ($afterComp === $beforeComp) ? pass('BOM component count PRESERVED across a Simple POS edit') : fail("BOM components WIPED by Simple POS edit — had $beforeComp, now $afterComp");
            ((int)$after['warehouse_id'] === $wid) ? pass('warehouse_id preserved/correct after edit') : fail('warehouse_id mismatch after edit');
        }

        // Cleanup
        $pdo->prepare("DELETE FROM product_assembly_components WHERE parent_product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
        $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $pid")->fetchColumn();
        ($left === 0) ? pass('test service fully cleaned up') : fail('test service not cleaned up');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Entitlement — Service create/edit must survive Procurement being off');

has(src($root, 'api/create_nip_product.php'), "canCreate('products')", "create_nip_product.php gates on canCreate('products')");
has(src($root, 'api/update_nip_product.php'), "canEdit('products')", "update_nip_product.php gates on canEdit('products')");

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — section 7 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];
    $svcName = 'CLI Procurement-Off Service ' . time() . '-' . rand(1000, 9999);

    // Simulate the exact tenant setup from the bug report: every feature on
    // EXCEPT Procurement (the owner of the 'nip_materials' page key) — the
    // same $GLOBALS['__bms_features'] override core/feature_registry.php's
    // own tenantFeatures() reads, used the same way by
    // tests/test_pos_phase13_entitlement_cli.php.
    $out = _svc_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$GLOBALS['__bms_features'] = array_fill_keys(allFeatureKeys(), true);
        \$GLOBALS['__bms_features']['procurement'] = false;

        \$checks = [
            'nip_materials_create_blocked' => canCreate('nip_materials') === false,
            'products_create_allowed'      => canCreate('products') === true,
        ];

        \$_POST = [
            'is_service'      => '1',
            'track_inventory' => '0',
            'unit'            => 'job',
            'status'          => 'active',
            'product_name'    => " . var_export($svcName, true) . ",
            'selling_price'   => '15000',
            'warehouse_id'    => '$wid',
        ];
        ob_start();
        include '$root/api/create_nip_product.php';
        \$createOut = ob_get_clean();
        \$pos = strpos(\$createOut, '{');
        \$createOut = \$pos === false ? \$createOut : substr(\$createOut, \$pos);
        \$createRes = json_decode(\$createOut, true);

        \$editOut = null;
        if (!empty(\$createRes['success']) && !empty(\$createRes['product_id'])) {
            \$_POST = [
                'product_id'      => \$createRes['product_id'],
                'is_service'      => '1',
                'track_inventory' => '0',
                'unit'            => 'job',
                'status'          => 'active',
                'product_name'    => " . var_export($svcName . ' EDITED', true) . ",
                'selling_price'   => '18000',
                'warehouse_id'    => '$wid',
            ];
            ob_start();
            include '$root/api/update_nip_product.php';
            \$editOut = ob_get_clean();
            \$pos = strpos(\$editOut, '{');
            \$editOut = \$pos === false ? \$editOut : substr(\$editOut, \$pos);
        }

        echo json_encode([
            'checks'    => \$checks,
            'create'    => \$createRes,
            'edit'      => json_decode(\$editOut, true),
        ]);
    ");
    $res = json_decode($out, true);

    if (!is_array($res)) {
        fail('section 7 subprocess did not return valid JSON: ' . $out);
    } else {
        ($res['checks']['nip_materials_create_blocked'] ?? null) === true
            ? pass("canCreate('nip_materials') is false with Procurement off — proves the simulated tenant setup matches the bug report")
            : fail("canCreate('nip_materials') should be false with Procurement off — test setup invalid");
        ($res['checks']['products_create_allowed'] ?? null) === true
            ? pass("canCreate('products') is true with Procurement off — Services are not gated by Procurement")
            : fail("canCreate('products') should be true with Procurement off");

        $pid = $res['create']['product_id'] ?? null;
        (!empty($res['create']['success']) && $pid)
            ? pass('Service CREATE succeeds with Procurement disabled for the tenant (regression: was "Access Denied: you do not have permission to create NIP products")')
            : fail('Service CREATE was blocked with Procurement off: ' . ($res['create']['message'] ?? json_encode($res['create'])));

        if ($pid) {
            (!empty($res['edit']['success']))
                ? pass('Service EDIT succeeds with Procurement disabled for the tenant')
                : fail('Service EDIT was blocked with Procurement off: ' . ($res['edit']['message'] ?? json_encode($res['edit'])));

            $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
            $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = " . (int)$pid)->fetchColumn();
            ($left === 0) ? pass('test service fully cleaned up') : fail('test service not cleaned up');
        }
    }
}
