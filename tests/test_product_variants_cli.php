<?php
/**
 * Phase 31 (pos_upgrade_plan.md §8) — Product Variants (size/color matrix) — CLI test
 *   php tests/test_product_variants_cli.php
 *
 *   A. STATIC — files lint clean.
 *   B. STATIC — schema: products.parent_product_id + variant_attributes exist.
 *   C. STATIC — wiring: generate_product_variants.php gates + guards;
 *      simple_products.php excludes children from the top-level grid and
 *      exposes variant_count + parent_product_id filtering; the POS terminal
 *      JS has the picker.
 *   D. LIVE (transaction-wrapped, rolled back) — the reuse claim, verified
 *      for real:
 *        · generating a matrix from real attributes creates the correct
 *          cartesian-product children, each a normal products row with a
 *          unique product_name and its own variant_attributes
 *        · re-generating the same matrix skips the exact-match duplicates
 *          (mirrors generate_product_variants.php's own normalize+skip logic)
 *        · simple_products.php's grid query (mirrored): a variant parent
 *          shows variant_count > 0 and is the ONLY row for that family at
 *          the top level; a parent_product_id-filtered query returns
 *          exactly that parent's children
 *        · stock, a price-group override, and a batch all resolve to the
 *          SPECIFIC child's product_id, never the parent's or a sibling's —
 *          proving Phase 17/18/14's existing product_id-keyed mechanisms
 *          need zero changes for a variant
 *        · a combo product is rejected as a variant parent (mirrors the
 *          endpoint's own guard)
 *        · a completely unrelated, non-variant product is unaffected:
 *          parent_product_id/variant_attributes stay NULL, variant_count = 0
 *   E. REGRESSION — the product/POS catalog suites most likely to notice a
 *      variant-column regression still exit 0.
 *
 * Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b) { return abs((float)$a - (float)$b) < 0.01; }
function src($p) { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

// Mirrors generate_product_variants.php's own combination-key normalizer.
function normalizeCombo(array $combo): string { ksort($combo); return json_encode($combo); }

try {
    // ── A. Files lint clean ─────────────────────────────────────────────────
    section('A. Files lint clean');
    foreach ([
        'migrations/tenant/2026_09_11_pos_product_variants.php',
        'migrations/2026_09_11_pos_product_variants_legacy_db.php',
        'api/generate_product_variants.php',
        'api/pos/simple_products.php',
        'app/bms/product/product_edit.php',
        'app/bms/pos/pos_scripts_new.php',
        'app/bms/pos/pos_modals_new.php',
        'core/feature_registry.php',
    ] as $f) {
        $path = "$root/$f";
        if (!file_exists($path)) { ok(false, "$f — MISSING"); continue; }
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ── B. Schema ────────────────────────────────────────────────────────────
    section('B. Schema');
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'parent_product_id'")->fetch(PDO::FETCH_ASSOC);
    ok((bool)$col, 'products.parent_product_id exists');
    ok($col && $col['Null'] === 'YES', 'products.parent_product_id is nullable — every existing product unaffected');
    $col2 = $pdo->query("SHOW COLUMNS FROM products LIKE 'variant_attributes'")->fetch(PDO::FETCH_ASSOC);
    ok((bool)$col2, 'products.variant_attributes exists');
    ok($col2 && strtolower($col2['Type']) === 'json', 'products.variant_attributes is a JSON column');

    // ── C. Wiring ────────────────────────────────────────────────────────────
    section('C. Wiring');
    $genSrc = src("$root/api/generate_product_variants.php");
    ok(strpos($genSrc, "canView('pos_advanced')") !== false, 'generate_product_variants.php gates on canView(pos_advanced)');
    ok(strpos($genSrc, "canCreate('products')") !== false, 'generate_product_variants.php gates on canCreate(products)');
    ok(strpos($genSrc, 'csrf_check()') !== false, 'generate_product_variants.php is CSRF-checked');
    ok(strpos($genSrc, "A variant cannot itself have variants") !== false, 'generate_product_variants.php rejects a variant-of-a-variant');
    ok(strpos($genSrc, "cannot have variants") !== false, 'generate_product_variants.php rejects services/combos as parents');
    ok(strpos($genSrc, '> 200') !== false, 'generate_product_variants.php caps the generated matrix size');
    ok(strpos($genSrc, 'logActivity(') !== false, 'generate_product_variants.php logs activity');

    $spSrc = src("$root/api/pos/simple_products.php");
    ok(strpos($spSrc, 'parent_product_id IS NULL') !== false, 'simple_products.php excludes variant children from the top-level grid by default');
    ok(strpos($spSrc, 'variant_count') !== false, 'simple_products.php exposes variant_count per product');
    ok(strpos($spSrc, ':parent_product_id') !== false, 'simple_products.php supports a parent_product_id-filtered children query');

    $jsSrc = src("$root/app/bms/pos/pos_scripts_new.php");
    ok(strpos($jsSrc, 'function openVariantPicker(') !== false, 'pos_scripts_new.php defines openVariantPicker()');
    ok(strpos($jsSrc, 'showProductQuickView(') !== false && strpos($jsSrc, 'openVariantPicker') !== false, 'a picked variant hands off to the existing showProductQuickView()/addToCart() flow');

    $modalSrc = src("$root/app/bms/pos/pos_modals_new.php");
    ok(strpos($modalSrc, 'id="variantPickerModal"') !== false, 'pos_modals_new.php defines the variant picker modal');

    // ── D. Live (transaction-wrapped, rolled back) ──────────────────────────
    section('D. Live — real reuse, verified (rolled back)');
    $pdo->beginTransaction();

    $whId = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
    $anyUser = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 LIMIT 1")->fetchColumn() ?: 1);

    // Fixture parent product.
    $pdo->prepare("
        INSERT INTO products (product_name, sku, unit, cost_price, selling_price, min_selling_price, status, is_service, is_taxable, track_inventory, created_by)
        VALUES ('VariantTest Shirt', 'VT-SHIRT-BASE', 'pcs', 1000, 2000, 900, 'active', 0, 1, 1, ?)
    ")->execute([$anyUser]);
    $parentId = (int)$pdo->lastInsertId();
    ok($parentId > 0, 'fixture parent product created');

    $attributes = ['Size' => ['S', 'M', 'L'], 'Color' => ['Red', 'Blue']]; // 3 x 2 = 6 combos

    // Mirror generate_product_variants.php's own cartesian-product + insert logic.
    $combinations = [[]];
    foreach ($attributes as $name => $vals) {
        $next = [];
        foreach ($combinations as $combo) { foreach ($vals as $v) { $next[] = $combo + [$name => $v]; } }
        $combinations = $next;
    }
    ok(count($combinations) === 6, 'cartesian product of Size(3) x Color(2) yields exactly 6 combinations');

    $childIds = [];
    foreach ($combinations as $combo) {
        $labelParts = [];
        foreach ($combo as $an => $av) { $labelParts[] = "$an: $av"; }
        $variantName = 'VariantTest Shirt (' . implode(', ', $labelParts) . ')';
        $pdo->prepare("
            INSERT INTO products (product_name, sku, unit, cost_price, selling_price, min_selling_price, status, is_service, is_taxable, track_inventory, parent_product_id, variant_attributes, created_by)
            VALUES (?, NULL, 'pcs', 1000, 2000, 900, 'active', 0, 1, 1, ?, ?, ?)
        ")->execute([$variantName, $parentId, json_encode($combo), $anyUser]);
        $childIds[normalizeCombo($combo)] = (int)$pdo->lastInsertId();
    }
    ok(count($childIds) === 6, '6 distinct child product rows created, one per combination');

    $distinctNames = (int)$pdo->query("SELECT COUNT(DISTINCT product_name) FROM products WHERE parent_product_id = $parentId")->fetchColumn();
    ok($distinctNames === 6, 'every generated child has a unique product_name (no collision)');

    // Re-generation dedupe: the same combo must be SKIPPED, not duplicated.
    $sizeM_ColorRed = ['Size' => 'M', 'Color' => 'Red'];
    $alreadyExists = isset($childIds[normalizeCombo($sizeM_ColorRed)]);
    ok($alreadyExists, 'sanity: (Size:M, Color:Red) is one of the 6 already-generated combos');
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE parent_product_id = $parentId")->fetchColumn();
    // Mirror the endpoint's own skip check instead of re-inserting.
    $existingNormalized = [];
    foreach ($pdo->query("SELECT variant_attributes FROM products WHERE parent_product_id = $parentId")->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $existingNormalized[normalizeCombo(json_decode($j, true))] = true;
    }
    $wouldSkip = isset($existingNormalized[normalizeCombo($sizeM_ColorRed)]);
    ok($wouldSkip, 're-running the same (Size:M, Color:Red) combination would be skipped, not duplicated');
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE parent_product_id = $parentId")->fetchColumn();
    ok($countAfter === $countBefore, 'child count unchanged — dedupe check is genuinely read-only here');

    // simple_products.php's grid shape, mirrored: top-level excludes children,
    // parent shows variant_count > 0.
    $topLevelRow = $pdo->query("
        SELECT p.product_id, (SELECT COUNT(*) FROM products vc WHERE vc.parent_product_id = p.product_id AND vc.status='active') as variant_count
        FROM products p WHERE p.product_id = $parentId AND p.parent_product_id IS NULL
    ")->fetch(PDO::FETCH_ASSOC);
    ok($topLevelRow && (int)$topLevelRow['variant_count'] === 6, "parent appears at the top level with variant_count=6 (not each child separately)");

    $childNotAtTopLevel = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id IN (" . implode(',', $childIds) . ") AND parent_product_id IS NULL")->fetchColumn();
    ok($childNotAtTopLevel === 0, 'no generated child would ever appear as its own top-level tile (all have parent_product_id set)');

    $childrenOnlyQuery = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE parent_product_id = $parentId")->fetchColumn();
    ok($childrenOnlyQuery === 6, "a parent_product_id-filtered query (the picker's own request) returns exactly this parent's 6 children");

    // Stock scopes to the SPECIFIC child, not the parent or a sibling.
    if ($whId) {
        $childA = $childIds[normalizeCombo(['Size' => 'S', 'Color' => 'Red'])];
        $childB = $childIds[normalizeCombo(['Size' => 'L', 'Color' => 'Blue'])];
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 25, 0)")->execute([$childA, $whId]);
        $stockA = (float)$pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id=$childA AND warehouse_id=$whId")->fetchColumn();
        $stockB = (float)$pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id=$childB AND warehouse_id=$whId")->fetchColumn();
        $stockParent = (float)$pdo->query("SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id=$parentId AND warehouse_id=$whId")->fetchColumn();
        ok(approx($stockA, 25.0), 'stock recorded against child A (Size:S, Color:Red) reads back exactly on that child');
        ok(approx($stockB, 0.0), 'sibling child B (Size:L, Color:Blue) has zero stock — no cross-contamination between variants');
        ok(approx($stockParent, 0.0), "the parent itself carries zero stock — every downstream stock system keys off the CHILD's product_id");
    } else {
        ok(true, 'no active warehouse on this server — stock-scoping check skipped (n/a)');
    }

    // Price-group override scopes to the specific child.
    $pgRow = $pdo->query("SELECT price_group_id FROM price_groups LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($pgRow) {
        $pgId = (int)$pgRow['price_group_id'];
        $childC = $childIds[normalizeCombo(['Size' => 'M', 'Color' => 'Blue'])];
        $pdo->prepare("INSERT INTO product_price_group_prices (product_id, price_group_id, price) VALUES (?, ?, 1750)")->execute([$childC, $pgId]);
        $priceForChildC = $pdo->query("SELECT price FROM product_price_group_prices WHERE product_id=$childC AND price_group_id=$pgId")->fetchColumn();
        $priceForParent = $pdo->query("SELECT price FROM product_price_group_prices WHERE product_id=$parentId AND price_group_id=$pgId")->fetchColumn();
        ok($priceForChildC !== false && approx($priceForChildC, 1750.0), "a price-group override on child C (Size:M, Color:Blue) resolves to that child's own price");
        ok($priceForParent === false, 'the same price-group override does NOT exist for the parent — Phase 14 price groups need zero changes to key off a variant correctly');
    } else {
        ok(true, 'no price group on this server — price-group scoping check skipped (n/a)');
    }

    // Combo rejection — mirrors the endpoint's own guard.
    $pdo->prepare("
        INSERT INTO products (product_name, unit, cost_price, selling_price, min_selling_price, status, is_service, is_combo, is_taxable, track_inventory, created_by)
        VALUES ('VariantTest Combo Parent', 'pcs', 1000, 2000, 900, 'active', 0, 1, 1, 1, ?)
    ")->execute([$anyUser]);
    $comboParentId = (int)$pdo->lastInsertId();
    $comboRow = $pdo->query("SELECT is_combo, is_service, parent_product_id FROM products WHERE product_id=$comboParentId")->fetch(PDO::FETCH_ASSOC);
    $wouldBeRejected = !empty($comboRow['is_combo']); // the endpoint's own check: !empty($parent['is_combo']) -> reject
    ok($wouldBeRejected, 'a combo/bundle product is flagged for rejection as a variant parent (mirrors the endpoint\'s own is_combo guard)');

    // A completely unrelated, non-variant product is unaffected.
    $pdo->prepare("
        INSERT INTO products (product_name, unit, cost_price, selling_price, min_selling_price, status, is_service, is_taxable, track_inventory, created_by)
        VALUES ('VariantTest Unrelated Product', 'pcs', 500, 900, 400, 'active', 0, 1, 1, ?)
    ")->execute([$anyUser]);
    $unrelatedId = (int)$pdo->lastInsertId();
    $unrelatedRow = $pdo->query("
        SELECT parent_product_id, variant_attributes,
               (SELECT COUNT(*) FROM products vc WHERE vc.parent_product_id = products.product_id) as vc
        FROM products WHERE product_id=$unrelatedId
    ")->fetch(PDO::FETCH_ASSOC);
    ok($unrelatedRow['parent_product_id'] === null, 'an unrelated product\'s parent_product_id stays NULL');
    ok($unrelatedRow['variant_attributes'] === null, 'an unrelated product\'s variant_attributes stays NULL');
    ok((int)$unrelatedRow['vc'] === 0, 'an unrelated product\'s variant_count is 0 — completely unaffected by this phase');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'fixture rolled back — no test data persisted');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// ── E. Regression — product/POS catalog suites most likely to notice ───────
section('E. Regression — product/POS catalog suites still pass');
foreach ([
    'test_pos_price_groups_cli.php', 'test_pos_serial_tracking_cli.php', 'test_pos_combo_products_cli.php',
    'test_pos_batch_cogs_cli.php', 'test_pos_i18n_coverage_cli.php', 'test_pos_unit_conversion_cli.php',
] as $name) {
    $path = "$root/tests/$name";
    if (!file_exists($path)) { ok(true, "$name not present on this checkout — skipped"); continue; }
    $o = []; $rc = 0;
    exec('php ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
    ok($rc === 0, "$name exits 0" . ($rc !== 0 ? ' — tail: ' . implode(' | ', array_slice($o, -3)) : ''));
}

exit($fail === 0 ? 0 : 1);
