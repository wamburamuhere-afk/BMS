<?php
/**
 * Products — Simple POS View page simplification — CLI regression suite
 *   php tests/test_product_view_simple_pos_cli.php
 *
 * Covers products_simple_pos_plan.md §6: app/bms/product/product_view.php hides
 * the same fields Create/Edit hide (SKU, Barcode, Description, Wholesale Price)
 * and the entire "Additional Details" tab when Simple POS is on and the
 * "Advanced Product" override is off — consistency across Create/Edit/View
 * (products_simple_pos_plan.md §4/§6). Stock Information (with the Batches/Lots
 * table) stays the default, primary tab in every mode; Sales Performance and
 * Stock Movements are generic business data, not "advanced fields", so they
 * are NOT hidden. The Batches/Lots table also picks up a Manufacturing Date
 * column, completing Phase 1's manufacturing_date wiring with something the
 * user can actually see.
 *
 *   A. STATIC   — file lints clean; source wiring for both branches present.
 *   B. RENDERED — the real page, three states: Simple POS, normal, and
 *                 Simple POS + Advanced Product override (full view back).
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

function _pvs_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'prodview_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _pvs_set_settings(string $root, string $simple, string $advanced): void {
    _pvs_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_product', " . var_export($advanced, true) . "); echo 'SAVED';");
}
function _pvs_render(string $root, int $uid, int $pid): string {
    return _pvs_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_GET['id'] = $pid;
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/app/bms/product/product_view.php';
        echo ob_get_clean();
    ");
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach (['app/bms/product/product_view.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$viewSrc = src($root, 'app/bms/product/product_view.php');
has($viewSrc, '$simpleProductForm = posSimpleModeEnabled() && !advancedProductEnabled();', 'gating flag ANDs Simple POS with the Advanced Product override');
has($viewSrc, "pb.manufacturing_date", 'Batches query now selects manufacturing_date');
has($viewSrc, '<th>Manufactured</th>', 'Batches table has a Manufactured column');
// The button itself must sit strictly between a `!$simpleProductForm` guard
// opening and its matching endif — checked precisely via the rendered HTML
// in section 3 below; here we just confirm the guard text exists near it.
$detailsTabPos = strpos($viewSrc, 'id="details-tab"');
$guardPos = strrpos(substr($viewSrc, 0, $detailsTabPos), 'if (!$simpleProductForm)');
($detailsTabPos !== false && $guardPos !== false && ($detailsTabPos - $guardPos) < 200)
    ? pass('Additional Details tab BUTTON conditionally wrapped')
    : fail('Additional Details tab BUTTON conditional guard not found nearby');

// ─────────────────────────────────────────────────────────────────────────
section('3. Rendered HTML — three states, the real page, a real product');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wh  = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$simpleModeBefore   = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$advancedProdBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_advanced_product'")->fetchColumn();

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — section 3 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];
    $pid = (int)$pdo->prepare("INSERT INTO products (product_name, sku, barcode, description, unit, cost_price, selling_price, min_selling_price, wholesale_price, status, is_service, created_by) VALUES (?, ?, ?, ?, 'pcs', 100, 150, 150, 120, 'active', 0, 1)")
        ->execute(['CLI View Simple Test ' . time() . '-' . rand(1000, 9999), 'VIEWSKU' . rand(100000, 999999), '69' . rand(1000000000, 2000000000), 'A visible description'])
        ? (int)$pdo->lastInsertId() : 0;

    if (!$pid) {
        fail('could not seed a test product for section 3');
    } else {
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 20, 0)")->execute([$pid, $wid]);
        $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, manufacturing_date, quantity_received, quantity_remaining, unit_cost, created_at) VALUES (?, ?, 'CLIBATCH1', '2027-06-01', '2026-06-01', 20, 20, 100, NOW())")->execute([$pid, $wid]);

        // State A: Simple POS on, Advanced Product off.
        _pvs_set_settings($root, '1', '0');
        $simple = _pvs_render($root, $uid, $pid);

        lacks($simple, 'SKU:</small>', 'Simple POS render: SKU label absent from Basic Information');
        lacks($simple, 'Barcode:</small>', 'Simple POS render: Barcode label absent');
        lacks($simple, '>Description:<', 'Simple POS render: Description block absent');
        lacks($simple, '>Wholesale:<', 'Simple POS render: Wholesale price absent');
        lacks($simple, 'id="details-tab"', 'Simple POS render: Additional Details tab button absent');
        lacks($simple, 'id="details" role="tabpanel"', 'Simple POS render: Additional Details tab pane absent');
        has($simple, 'id="sales-tab"', 'Simple POS render: Sales Performance tab still present (generic business data)');
        has($simple, 'id="movements-tab"', 'Simple POS render: Stock Movements tab still present (generic business data)');
        has($simple, 'id="stock-tab"', 'Simple POS render: Stock Information tab still present (primary view)');
        has($simple, 'Batches / Lots', 'Simple POS render: Batches/Lots table still present');
        has($simple, '>Manufactured<', 'Simple POS render: Batches table shows Manufactured column header');
        has($simple, '01 Jun 2026', 'Simple POS render: seeded manufacturing_date value rendered');

        // State B: normal mode — fully unchanged.
        _pvs_set_settings($root, '0', '0');
        $normal = _pvs_render($root, $uid, $pid);
        has($normal, 'SKU:</small>', 'Normal mode render: SKU label present');
        has($normal, 'id="details-tab"', 'Normal mode render: Additional Details tab present');
        has($normal, '>Description:<', 'Normal mode render: Description block present');

        // State C: Simple POS + Advanced Product override — full view restored.
        _pvs_set_settings($root, '1', '1');
        $override = _pvs_render($root, $uid, $pid);
        has($override, 'SKU:</small>', 'Simple POS + Advanced Product: SKU label restored');
        has($override, 'id="details-tab"', 'Simple POS + Advanced Product: Additional Details tab restored');

        _pvs_set_settings($root, (string)($simpleModeBefore === false ? '0' : $simpleModeBefore), (string)($advancedProdBefore === false ? '0' : $advancedProdBefore));

        // Cleanup
        $pdo->prepare("DELETE FROM product_batches WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
        $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $pid")->fetchColumn();
        ($left === 0) ? pass('test product fully cleaned up') : fail('test product not cleaned up');
    }
}
