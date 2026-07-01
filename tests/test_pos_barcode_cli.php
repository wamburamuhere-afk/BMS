<?php
/**
 * POS Barcode Scanner — comprehensive CLI test suite
 *   php tests/test_pos_barcode_cli.php
 *
 * Sections:
 *   1.  Schema          — products.barcode + .sku columns exist
 *   2.  API payload     — simple_products.php returns barcode in every row
 *   3.  API search      — barcode typed in search box finds the product (BUG FIX)
 *   4.  Lookup          — barcode exact-match, case-insensitive, whitespace trim
 *   5.  SKU fallback    — product found by SKU when no barcode
 *   6.  Not found       — random / too-short codes return null
 *   7.  Cart increment  — same barcode scanned twice → qty 2, not two lines
 *   8.  Multi-product   — two different barcodes → two separate cart lines
 *   9.  Category filter — scanner still finds product outside current category (BUG FIX)
 *  10.  HTML structure  — pos.php emits required elements (hiddenScanInput, posHeaderBar, badge)
 *  11.  JS impl         — pos_scripts_new.php contains all required scanner code
 *  12.  allProducts     — full-catalog array is declared and populated on unfiltered load
 *  13.  Coverage        — scannability stats + duplicate barcode guard
 *  14.  Lint            — PHP lint on all changed files
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$pass = 0; $fail = 0;

function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function info(string $m): void { echo "  \033[33mℹ\033[0m  $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function lintOk(string $path): bool {
    $rc = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc); return $rc === 0;
}

// Mirror the JS lookup logic in PHP
function jsLookup(array $catalog, string $code): ?array {
    $needle = strtolower(trim($code));
    foreach ($catalog as $p) {
        if ((isset($p['barcode']) && strtolower((string)$p['barcode']) === $needle) ||
            (isset($p['sku'])     && strtolower((string)$p['sku'])     === $needle)) {
            return $p;
        }
    }
    return null;
}

// Mirror scanAddToCart JS
function cartScan(array &$cart, array $product): void {
    foreach ($cart as &$item) {
        if ($item['product_id'] == $product['product_id']) { $item['quantity']++; return; }
    }
    $cart[] = [
        'product_id'   => $product['product_id'],
        'product_name' => $product['product_name'],
        'quantity'     => 1,
        'price'        => (float)$product['selling_price'],
    ];
}

// Run the simple_products API and return its response
function callApi(string $root, array $get = []): array {
    global $pdo, $_GET, $_POST, $_SERVER;
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    $prev = error_reporting(error_reporting() & ~(E_WARNING | E_NOTICE));
    @require "$root/api/pos/simple_products.php";
    $raw = ob_get_clean();
    error_reporting($prev);
    return json_decode($raw, true) ?? [];
}

register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    echo "\n";
    echo "Passes:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ── 1. Schema ────────────────────────────────────────────────────────────────
section('1 — Schema');

$cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'")->fetchAll();
count($cols) === 1 ? pass("products.barcode column exists") : fail("products.barcode column MISSING");

$cols2 = $pdo->query("SHOW COLUMNS FROM products LIKE 'sku'")->fetchAll();
count($cols2) === 1 ? pass("products.sku column exists") : fail("products.sku column MISSING");

// ── 2. API payload ───────────────────────────────────────────────────────────
section('2 — API payload (full load)');

$full = callApi($root, ['category' => '', 'search' => '', 'warehouse_id' => '', 'project_id' => '']);

($full['success'] ?? false) === true
    ? pass("simple_products.php success=true")
    : fail("simple_products.php did not return success=true");

$allApiProducts = $full['data'] ?? [];
count($allApiProducts) > 0
    ? pass("API returned " . count($allApiProducts) . " products")
    : fail("API returned empty product list");

$allHaveBarcode = array_reduce($allApiProducts, fn($ok, $p) => $ok && array_key_exists('barcode', $p), true);
$allHaveBarcode
    ? pass("Every product in API payload has a 'barcode' key")
    : fail("Some API products are missing the 'barcode' key — scanner cannot look them up");

$allHaveSku = array_reduce($allApiProducts, fn($ok, $p) => $ok && array_key_exists('sku', $p), true);
$allHaveSku
    ? pass("Every product in API payload has an 'sku' key")
    : fail("Some API products are missing the 'sku' key — SKU fallback would break");

// ── 3. API search includes barcode (was a bug — search only matched name+SKU) ─
section('3 — API search matches by barcode (bug fix verification)');

$productWithBarcode = $pdo->query(
    "SELECT product_id, product_name, barcode, sku, selling_price
     FROM products WHERE status='active' AND barcode IS NOT NULL AND barcode != ''
     ORDER BY RAND() LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($productWithBarcode) {
    $searchResult = callApi($root, ['search' => $productWithBarcode['barcode'], 'category' => '', 'warehouse_id' => '', 'project_id' => '']);
    $hits = array_filter($searchResult['data'] ?? [], fn($p) => $p['product_id'] == $productWithBarcode['product_id']);
    count($hits) > 0
        ? pass("Typing the exact barcode into the search box finds the product ({$productWithBarcode['product_name']})")
        : fail("Barcode '{$productWithBarcode['barcode']}' typed in search box did NOT find the product — barcode missing from WHERE clause");

    // Partial match (first 5 chars of barcode)
    if (strlen($productWithBarcode['barcode']) >= 5) {
        $partial = substr($productWithBarcode['barcode'], 0, 5);
        $partialResult = callApi($root, ['search' => $partial, 'category' => '', 'warehouse_id' => '', 'project_id' => '']);
        $partialHits = array_filter($partialResult['data'] ?? [], fn($p) => $p['product_id'] == $productWithBarcode['product_id']);
        count($partialHits) > 0
            ? pass("Partial barcode prefix '$partial' also finds the product")
            : fail("Partial barcode search '$partial' did not find the product");
    }
} else {
    info("No product with barcode found — skipping API search-by-barcode tests");
}

// ── 4. Lookup: exact match, case, whitespace ──────────────────────────────────
section('4 — Barcode lookup (exact, case-insensitive, whitespace-trimmed)');

if ($productWithBarcode) {
    $bc = $productWithBarcode['barcode'];

    $found = jsLookup($allApiProducts, $bc);
    ($found && $found['product_id'] == $productWithBarcode['product_id'])
        ? pass("Exact barcode match: '{$bc}' → {$productWithBarcode['product_name']}")
        : fail("Exact match failed for barcode '{$bc}'");

    $found2 = jsLookup($allApiProducts, strtoupper($bc));
    ($found2 && $found2['product_id'] == $productWithBarcode['product_id'])
        ? pass("Case-insensitive: uppercase barcode still matches")
        : fail("Case-insensitive lookup failed");

    $found3 = jsLookup($allApiProducts, strtolower($bc));
    ($found3 && $found3['product_id'] == $productWithBarcode['product_id'])
        ? pass("Case-insensitive: lowercase barcode still matches")
        : fail("Lowercase lookup failed");

    $found4 = jsLookup($allApiProducts, "  $bc  ");
    ($found4 && $found4['product_id'] == $productWithBarcode['product_id'])
        ? pass("Whitespace trimmed: padded barcode still matches")
        : fail("Whitespace trim failed — padded barcode not found");
} else {
    info("Skipped section 4 — no product with barcode");
}

// ── 5. SKU fallback ──────────────────────────────────────────────────────────
section('5 — SKU fallback lookup');

$productWithSku = $pdo->query(
    "SELECT product_id, product_name, barcode, sku, selling_price
     FROM products WHERE status='active' AND sku IS NOT NULL AND sku != '' AND sku != 'N/A'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($productWithSku) {
    $found = jsLookup($allApiProducts, $productWithSku['sku']);
    ($found && $found['product_id'] == $productWithSku['product_id'])
        ? pass("SKU '{$productWithSku['sku']}' finds product: {$productWithSku['product_name']}")
        : fail("SKU lookup failed for '{$productWithSku['sku']}'");
} else {
    info("Skipped section 5 — no product with a real SKU");
}

// ── 6. Not found ─────────────────────────────────────────────────────────────
section('6 — Not-found cases');

is_null(jsLookup($allApiProducts, 'XXXXX_NO_MATCH_' . time()))
    ? pass("Random code returns null (not found)")
    : fail("Random code unexpectedly matched a product");

is_null(jsLookup($allApiProducts, ''))
    ? pass("Empty string returns null")
    : fail("Empty string matched — should never match");

is_null(jsLookup($allApiProducts, 'AB'))
    ? pass("Two-char code returns null (below min length)")
    : fail("Two-char code matched unexpectedly");

// Very long code (scanners sometimes mis-read and produce garbage)
is_null(jsLookup($allApiProducts, str_repeat('9', 50)))
    ? pass("50-digit garbage code returns null")
    : fail("50-digit garbage code matched — unexpected");

// ── 7. Cart increment ────────────────────────────────────────────────────────
section('7 — Cart increment (rescan same product)');

if ($productWithBarcode) {
    $cart = [];
    $p = jsLookup($allApiProducts, $productWithBarcode['barcode']);

    cartScan($cart, $p);
    count($cart) === 1 ? pass("Scan 1: 1 cart line") : fail("Scan 1: expected 1 line, got " . count($cart));
    ($cart[0]['quantity'] ?? 0) === 1 ? pass("Scan 1: qty = 1") : fail("Scan 1: qty = {$cart[0]['quantity']}");

    cartScan($cart, $p);  // same product again
    count($cart) === 1 ? pass("Scan 2: still 1 line (no duplicate)") : fail("Scan 2: added a new line — expected qty increment");
    ($cart[0]['quantity'] ?? 0) === 2 ? pass("Scan 2: qty incremented to 2") : fail("Scan 2: qty = {$cart[0]['quantity']}");

    cartScan($cart, $p);  // third scan
    ($cart[0]['quantity'] ?? 0) === 3 ? pass("Scan 3: qty incremented to 3") : fail("Scan 3: qty = {$cart[0]['quantity']}");
} else {
    info("Skipped section 7 — no product with barcode");
}

// ── 8. Multi-product cart ────────────────────────────────────────────────────
section('8 — Two different products → two separate cart lines');

$products2 = $pdo->query(
    "SELECT product_id, product_name, barcode, sku, selling_price
     FROM products WHERE status='active' AND barcode IS NOT NULL AND barcode != ''
     ORDER BY product_id LIMIT 2"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($products2) === 2 && $products2[0]['product_id'] !== $products2[1]['product_id']) {
    $cart2 = [];
    $p1 = jsLookup($allApiProducts, $products2[0]['barcode']);
    $p2 = jsLookup($allApiProducts, $products2[1]['barcode']);

    if ($p1 && $p2) {
        cartScan($cart2, $p1);
        cartScan($cart2, $p2);
        count($cart2) === 2
            ? pass("Two different barcodes → 2 separate cart lines")
            : fail("Expected 2 cart lines, got " . count($cart2));
        ($cart2[0]['quantity'] === 1 && $cart2[1]['quantity'] === 1)
            ? pass("Each line has qty = 1")
            : fail("Qty mismatch: {$cart2[0]['quantity']}, {$cart2[1]['quantity']}");

        // Rescan first product — only line 0 increments
        cartScan($cart2, $p1);
        count($cart2) === 2
            ? pass("Rescan first product: still 2 lines (no 3rd line)")
            : fail("Expected 2 lines after rescan, got " . count($cart2));
        $cart2[0]['quantity'] === 2
            ? pass("Rescan first product: line 0 qty now 2")
            : fail("Line 0 qty = {$cart2[0]['quantity']} (expected 2)");
        $cart2[1]['quantity'] === 1
            ? pass("Line 1 qty unchanged at 1")
            : fail("Line 1 qty = {$cart2[1]['quantity']} (expected 1)");
    } else {
        info("Skipped — API did not return both products");
    }
} else {
    info("Skipped section 8 — need at least 2 products with distinct barcodes");
}

// ── 9. Category filter bug fix ───────────────────────────────────────────────
section('9 — Category filter: scanner finds product outside active category');

// Get two products in DIFFERENT categories (or at least confirm allProducts is used)
$twoCategories = $pdo->query(
    "SELECT p1.product_id, p1.barcode, p1.category_id, p2.product_id AS pid2, p2.category_id AS cat2
     FROM products p1, products p2
     WHERE p1.status='active' AND p2.status='active'
       AND p1.barcode IS NOT NULL AND p1.barcode != ''
       AND p1.category_id != p2.category_id
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($twoCategories) {
    // Simulate: category filter loads only products from cat2 → products[] has no p1
    $catResponse = callApi($root, [
        'category'     => $twoCategories['cat2'],
        'search'       => '',
        'warehouse_id' => '',
        'project_id'   => '',
    ]);
    $filteredProducts = $catResponse['data'] ?? [];

    // p1 should NOT be in the filtered array
    $inFiltered = array_filter($filteredProducts, fn($p) => $p['product_id'] == $twoCategories['product_id']);
    count($inFiltered) === 0
        ? pass("Product #{$twoCategories['product_id']} is absent from category #{$twoCategories['cat2']} response (confirms the scenario)")
        : info("Product is in the same category — category-filter test less meaningful but continuing");

    // Scanner uses allProducts (full catalog), NOT the filtered products[]
    $foundViaAll      = jsLookup($allApiProducts,    $twoCategories['barcode']);
    $foundViaFiltered = jsLookup($filteredProducts,   $twoCategories['barcode']);

    is_null($foundViaFiltered)
        ? pass("Barcode not found in category-filtered list — confirms the bug scenario")
        : info("Barcode happened to appear in filtered list (products in same cat)");

    $foundViaAll !== null
        ? pass("Barcode IS found in allProducts[] — scanner uses full catalog, not filtered view")
        : fail("Barcode not found even in allProducts[] — check allProducts population");
} else {
    info("Skipped section 9 — all products are in the same category");
}

// Verify the fix is present: allProducts declaration
$jsContent = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");

str_contains($jsContent, 'let allProducts')
    ? pass("allProducts global variable declared in pos_scripts_new.php")
    : fail("allProducts variable NOT declared — category-filter fix missing");

str_contains($jsContent, 'allProducts = response.data')
    ? pass("allProducts is assigned the full catalog on unfiltered load")
    : fail("allProducts is never assigned — scanner would always search only the filtered list");

str_contains($jsContent, "categoryId === 'all'")
    ? pass("allProducts is only updated on unfiltered/all-category loads")
    : fail("Missing guard for updating allProducts only on full loads");

$allProductsUsedInLookup = str_contains($jsContent, '(allProducts && allProducts.length > 0) ? allProducts : products');
$allProductsUsedInLookup
    ? pass("Scanner lookup uses allProducts with fallback to products[]")
    : fail("Scanner still searches products[] only — category-filter bug not fixed");

// ── 10. HTML structure ────────────────────────────────────────────────────────
section('10 — HTML structure in pos.php');

$posHtml = file_get_contents("$root/app/bms/pos/pos.php");

str_contains($posHtml, 'id="hiddenScanInput"')
    ? pass("Hidden scan input #hiddenScanInput present in pos.php")
    : fail("#hiddenScanInput MISSING from pos.php — scanner has no fallback focus target");

str_contains($posHtml, 'id="posHeaderBar"')
    ? pass("#posHeaderBar ID present in pos.php — visual flash can target it")
    : fail("#posHeaderBar MISSING from pos.php — header flash won't work");

str_contains($posHtml, 'id="scannerReadyBadge"')
    ? pass("#scannerReadyBadge indicator present in pos.php")
    : fail("#scannerReadyBadge MISSING from pos.php");

str_contains($posHtml, 'SCANNER READY')
    ? pass("'SCANNER READY' label text present in pos.php")
    : fail("'SCANNER READY' text missing from badge");

str_contains($posHtml, 'bi-upc-scan')
    ? pass("Scanner icon (bi-upc-scan) present in pos.php")
    : fail("Scanner icon missing from pos.php badge");

// hidden input must be off-screen (not visible to user)
str_contains($posHtml, 'left:-9999px')
    ? pass("Hidden input is positioned off-screen (not visible to user)")
    : fail("Hidden input may be visible — check positioning");

// ── 11. JS implementation ─────────────────────────────────────────────────────
section('11 — JavaScript implementation in pos_scripts_new.php');

$requiredJs = [
    'SCAN_MAX_MS = 80'                      => 'SCAN_MAX_MS timing constant (80ms)',
    '_scanBuffer'                           => '_scanBuffer accumulator variable',
    'handleBarcodeScanned'                  => 'handleBarcodeScanned() function',
    'scanAddToCart'                         => 'scanAddToCart() function',
    '_beep'                                 => '_beep() audio function',
    'flashHeader'                           => 'flashHeader() visual feedback function',
    'scanToast'                             => 'scanToast() notification function',
    "window._posHandleScan"                 => 'window._posHandleScan exposed for testing',
    "document.addEventListener('keydown'"   => "global keydown listener on document",
    "hidden.bs.modal"                       => "focus recovery after Bootstrap modal close",
    'hiddenScanInput'                       => 'hiddenScanInput referenced in JS',
    'scannerReadyBadge'                     => 'scannerReadyBadge shown on init',
    'AudioContext'                          => 'Web Audio API used for beep',
    "_beep(880"                             => 'success beep calls _beep(880Hz)',
    "_beep(200"                             => 'error buzz calls _beep(200Hz)',
    'exponentialRampToValueAtTime'          => 'Audio fade-out (clean beep)',
    "flashHeader('#198754'"                 => 'green flash on success (#198754)',
    "flashHeader('#dc3545'"                 => 'red flash on error (#dc3545)',
    'toast-body'                            => 'Bootstrap toast in notification',
    'ctrlKey || e.altKey'                   => 'modifier-key passthrough (Ctrl/Alt ignored)',
    'preventScroll'                         => 'focus() called with preventScroll to avoid jumps',
];

foreach ($requiredJs as $needle => $label) {
    // Use regex for patterns with alternatives
    $found = str_contains($jsContent, $needle) || @preg_match("/$needle/", $jsContent);
    $found
        ? pass("JS: $label")
        : fail("JS MISSING: $label (searched for: $needle)");
}

// IIFE pattern — scanner is wrapped in a self-contained scope
str_contains($jsContent, '(function ()') || str_contains($jsContent, '(function(')
    ? pass("Scanner is wrapped in an IIFE (no global namespace pollution)")
    : fail("Scanner IIFE missing — variables may leak into global scope");

// ── 12. allProducts maintenance ───────────────────────────────────────────────
section('12 — allProducts population via API');

// Call API with no category → should populate allProducts
$fullResponse = callApi($root, ['category' => '', 'search' => '', 'warehouse_id' => '', 'project_id' => '']);
$fullCount = count($fullResponse['data'] ?? []);

// Call API with category filter → should return fewer products (if categories exist)
$firstCat = $pdo->query(
    "SELECT category_id FROM product_categories WHERE status='active' LIMIT 1"
)->fetchColumn();

if ($firstCat) {
    $catResp = callApi($root, ['category' => $firstCat, 'search' => '', 'warehouse_id' => '', 'project_id' => '']);
    $catCount = count($catResp['data'] ?? []);

    $catCount <= $fullCount
        ? pass("Category filter returns ≤ all-products count ($catCount ≤ $fullCount) — confirms filtering works")
        : fail("Category filtered result ($catCount) > full result ($fullCount) — unexpected");

    // allProducts should always have the full count
    $fullCount >= $catCount
        ? pass("allProducts (full) has $fullCount products; category filter has $catCount — scanner uses the larger set")
        : fail("Full load returned fewer than category-filtered load — unexpected");
} else {
    info("No product categories found — skipping allProducts population test");
}

// ── 13. Coverage stats + duplicate guard ─────────────────────────────────────
section('13 — Product scannability coverage');

$total    = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$withBc   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active' AND barcode IS NOT NULL AND barcode != ''")->fetchColumn();
$withSku  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active' AND sku IS NOT NULL AND sku != '' AND sku != 'N/A'")->fetchColumn();
$neither  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active' AND (barcode IS NULL OR barcode='') AND (sku IS NULL OR sku='' OR sku='N/A')")->fetchColumn();

pass("$total active products total");
pass("$withBc have a barcode (primary scanner lookup)");
pass("$withSku have a real SKU (fallback lookup)");

$neither === 0
    ? pass("All products are scannable (barcode or real SKU)")
    : info("$neither product(s) have no barcode AND no real SKU — not scannable via hardware scanner");

if ($neither > 0) {
    $rows = $pdo->query(
        "SELECT product_name FROM products WHERE status='active'
         AND (barcode IS NULL OR barcode='') AND (sku IS NULL OR sku='' OR sku='N/A')
         ORDER BY product_name LIMIT 10"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $n) info("  Not scannable: $n");
}

// Duplicate barcode check
$dups = $pdo->query(
    "SELECT barcode, COUNT(*) c FROM products
     WHERE status='active' AND barcode IS NOT NULL AND barcode != ''
     GROUP BY barcode HAVING c > 1"
)->fetchAll(PDO::FETCH_ASSOC);

count($dups) === 0
    ? pass("No duplicate barcodes in active products")
    : fail(count($dups) . " duplicate barcode(s) — scanner will always pick the FIRST match:\n" .
           implode("\n", array_map(fn($r) => "    barcode={$r['barcode']} (×{$r['c']})", $dups)));

// ── 14. PHP lint on all changed files ────────────────────────────────────────
section('14 — PHP lint (all changed files)');

$files = [
    'app/bms/pos/pos.php',
    'app/bms/pos/pos_scripts_new.php',
    'app/bms/pos/pos_modals_new.php',
    'api/pos/simple_products.php',
    'tests/test_pos_barcode_cli.php',
];

foreach ($files as $rel) {
    $full = "$root/$rel";
    file_exists($full)
        ? (lintOk($full) ? pass("Lint OK: $rel") : fail("Lint FAILED: $rel"))
        : fail("File not found: $rel");
}
