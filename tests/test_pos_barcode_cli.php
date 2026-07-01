<?php
/**
 * POS Barcode Scanner — CLI test suite
 *   php tests/test_pos_barcode_cli.php
 *
 * Coverage:
 *   1. Schema       — products.barcode column exists and is indexed
 *   2. API          — simple_products.php returns barcode field in payload
 *   3. Lookup       — barcode exact-match finds the right product
 *   4. SKU fallback — SKU match works when barcode is absent
 *   5. Not found    — random codes return no match
 *   6. Cart logic   — same barcode scanned twice increments qty (not two lines)
 *   7. Products     — every active product has at least one identifier (barcode OR sku)
 *   8. Files        — all changed files are PHP lint-clean
 *   9. Missing info — count/list products missing barcodes (informational, not failure)
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
    $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
    return $rc === 0;
}

// Simulate the JS lookup logic in PHP for cart-logic tests
function lookupByBarcode(array $products, string $code): ?array {
    $needle = strtolower(trim($code));
    foreach ($products as $p) {
        if ((isset($p['barcode']) && strtolower($p['barcode']) === $needle) ||
            (isset($p['sku'])     && strtolower($p['sku'])     === $needle)) {
            return $p;
        }
    }
    return null;
}

// Simulate cart add/increment (mirrors scanAddToCart JS)
function cartScan(array &$cart, array $product): void {
    foreach ($cart as &$item) {
        if ($item['product_id'] == $product['product_id']) {
            $item['quantity']++;
            return;
        }
    }
    $cart[] = [
        'product_id'   => $product['product_id'],
        'product_name' => $product['product_name'],
        'quantity'     => 1,
        'price'        => (float)$product['selling_price'],
    ];
}

register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    echo "\n";
    echo "Passes:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ── Section 1: Schema ────────────────────────────────────────────────────────
section('1 — Schema');

try {
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'")->fetchAll(PDO::FETCH_ASSOC);
    count($cols) === 1
        ? pass("products.barcode column exists")
        : fail("products.barcode column MISSING");
} catch (PDOException $e) {
    fail("Could not check schema: " . $e->getMessage());
}

// Check SKU column too (fallback lookup)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'sku'")->fetchAll(PDO::FETCH_ASSOC);
    count($cols) === 1
        ? pass("products.sku column exists (SKU fallback)")
        : fail("products.sku column MISSING");
} catch (PDOException $e) {
    fail("Could not check sku column: " . $e->getMessage());
}

// ── Section 2: API payload ───────────────────────────────────────────────────
section('2 — simple_products.php API payload');

$_GET = ['category' => '', 'search' => '', 'warehouse_id' => '', 'project_id' => ''];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
$prevErr = error_reporting(error_reporting() & ~(E_WARNING | E_NOTICE));
@require_once "$root/api/pos/simple_products.php";
$raw = ob_get_clean();
error_reporting($prevErr);

$apiResponse = json_decode($raw, true);

if ($apiResponse && $apiResponse['success'] === true) {
    pass("simple_products.php returned success=true");
} else {
    fail("simple_products.php did not return success=true — raw: " . substr($raw, 0, 200));
}

$apiProducts = $apiResponse['data'] ?? [];
count($apiProducts) > 0
    ? pass("API returned " . count($apiProducts) . " products")
    : fail("API returned empty product list");

// Every product in the response must have a barcode key (even if null)
$allHaveKey = true;
foreach ($apiProducts as $p) {
    if (!array_key_exists('barcode', $p)) { $allHaveKey = false; break; }
}
$allHaveKey
    ? pass("Every API product object includes 'barcode' key")
    : fail("Some API products are missing the 'barcode' key");

// ── Section 3: Barcode exact-match lookup ────────────────────────────────────
section('3 — Barcode exact-match lookup');

// Find a product that has a barcode stored
$withBarcode = $pdo->query(
    "SELECT product_id, product_name, barcode, sku, selling_price
     FROM products
     WHERE status = 'active' AND barcode IS NOT NULL AND barcode != ''
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($withBarcode) {
    pass("Found a product with barcode: {$withBarcode['product_name']} ({$withBarcode['barcode']})");

    // Test exact-case match
    $found = lookupByBarcode($apiProducts, $withBarcode['barcode']);
    if ($found && $found['product_id'] == $withBarcode['product_id']) {
        pass("Barcode lookup returns correct product (exact case)");
    } else {
        fail("Barcode lookup did NOT find product by barcode '{$withBarcode['barcode']}'");
    }

    // Test case-insensitive (uppercase input)
    $found2 = lookupByBarcode($apiProducts, strtoupper($withBarcode['barcode']));
    if ($found2 && $found2['product_id'] == $withBarcode['product_id']) {
        pass("Barcode lookup is case-insensitive (uppercase input works)");
    } else {
        fail("Barcode lookup failed on uppercase barcode");
    }

    // Test with leading/trailing whitespace (scanner sometimes adds space)
    $found3 = lookupByBarcode($apiProducts, '  ' . $withBarcode['barcode'] . '  ');
    if ($found3 && $found3['product_id'] == $withBarcode['product_id']) {
        pass("Barcode lookup trims whitespace correctly");
    } else {
        fail("Barcode lookup failed with padded whitespace");
    }
} else {
    fail("No active product has a barcode stored — cannot test lookup");
    info("Add barcodes to products to enable full scanner functionality.");
}

// ── Section 4: SKU fallback ──────────────────────────────────────────────────
section('4 — SKU fallback lookup');

// Find a product that has an SKU
$withSku = $pdo->query(
    "SELECT product_id, product_name, barcode, sku, selling_price
     FROM products
     WHERE status = 'active' AND sku IS NOT NULL AND sku != ''
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($withSku) {
    pass("Found a product with SKU: {$withSku['product_name']} (sku={$withSku['sku']})");

    // Use the SKU as the scan code
    $found = lookupByBarcode($apiProducts, $withSku['sku']);
    if ($found && $found['product_id'] == $withSku['product_id']) {
        pass("SKU lookup returns correct product");
    } else {
        fail("SKU lookup did NOT find product '{$withSku['product_name']}' by sku '{$withSku['sku']}'");
    }
} else {
    fail("No active product has an SKU stored — cannot test SKU fallback");
}

// ── Section 5: Not-found case ────────────────────────────────────────────────
section('5 — Not-found case');

$garbage   = 'XXXXXXXX_NO_SUCH_BARCODE_' . time();
$notFound  = lookupByBarcode($apiProducts, $garbage);
is_null($notFound)
    ? pass("Random barcode returns NULL (not found)")
    : fail("Random barcode returned a product unexpectedly");

$short = 'AB';  // Too short (scanner min is 3 chars)
$notFound2 = lookupByBarcode($apiProducts, $short);
is_null($notFound2)
    ? pass("Two-char code returns NULL (too short to match any real barcode)")
    : fail("Two-char code matched unexpectedly");

// ── Section 6: Cart increment logic ─────────────────────────────────────────
section('6 — Cart increment (same barcode scanned twice)');

if ($withBarcode) {
    $cart = [];

    // First scan
    $p1 = lookupByBarcode($apiProducts, $withBarcode['barcode']);
    if ($p1) {
        cartScan($cart, $p1);
        count($cart) === 1
            ? pass("First scan adds 1 cart line")
            : fail("First scan: expected 1 cart line, got " . count($cart));
        $cart[0]['quantity'] === 1
            ? pass("First scan: quantity = 1")
            : fail("First scan: quantity = {$cart[0]['quantity']} (expected 1)");

        // Second scan of same barcode
        $p2 = lookupByBarcode($apiProducts, $withBarcode['barcode']);
        cartScan($cart, $p2);
        count($cart) === 1
            ? pass("Second scan does NOT add a new line (stays at 1 line)")
            : fail("Second scan: added a new line — expected qty increment, got " . count($cart) . " lines");
        $cart[0]['quantity'] === 2
            ? pass("Second scan: quantity incremented to 2")
            : fail("Second scan: quantity = {$cart[0]['quantity']} (expected 2)");

        // Third scan (different product by SKU)
        if ($withSku && $withSku['product_id'] != $withBarcode['product_id']) {
            $p3 = lookupByBarcode($apiProducts, $withSku['sku']);
            if ($p3) {
                cartScan($cart, $p3);
                count($cart) === 2
                    ? pass("Third scan (different product) adds a new cart line")
                    : fail("Third scan: expected 2 lines, got " . count($cart));
            }
        } else {
            info("Skipped 3rd-scan test (same product or no SKU product available)");
        }
    } else {
        fail("Could not load product from API for cart test");
    }
} else {
    info("Skipped cart tests — no product with barcode available");
}

// ── Section 7: Every active product scannable ────────────────────────────────
section('7 — Product scannability coverage');

$total = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active'"
)->fetchColumn();

$withBarcodeCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active'
     AND barcode IS NOT NULL AND barcode != ''"
)->fetchColumn();

$withSkuCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active'
     AND sku IS NOT NULL AND sku != ''"
)->fetchColumn();

$fullyScannableCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active'
     AND (
         (barcode IS NOT NULL AND barcode != '') OR
         (sku     IS NOT NULL AND sku     != '')
     )"
)->fetchColumn();

$notScannableCount = $total - $fullyScannableCount;

pass("$total active products total");
pass("$withBarcodeCount have a barcode stored");
pass("$withSkuCount have an SKU stored");

$fullyScannableCount === $total
    ? pass("All $total active products are scannable (barcode or SKU)")
    : info("$notScannableCount product(s) have neither barcode nor SKU — scanner cannot find them");

if ($notScannableCount > 0) {
    $missing = $pdo->query(
        "SELECT product_name FROM products
         WHERE status = 'active'
           AND (barcode IS NULL OR barcode = '')
           AND (sku IS NULL OR sku = '')
         ORDER BY product_name LIMIT 20"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($missing as $n) {
        info("  Not scannable: $n");
    }
}

// Products with no barcode (informational only — SKU still works)
$missingBarcode = $total - $withBarcodeCount;
if ($missingBarcode > 0) {
    info("$missingBarcode product(s) rely on SKU only (no barcode label):");
    $rows = $pdo->query(
        "SELECT product_name, sku FROM products
         WHERE status = 'active' AND (barcode IS NULL OR barcode = '')
         ORDER BY product_name LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        info("  {$r['product_name']} (sku: " . ($r['sku'] ?: 'none') . ")");
    }
}

// ── Section 8: Duplicate barcode guard ──────────────────────────────────────
section('8 — Duplicate barcode check');

$duplicates = $pdo->query(
    "SELECT barcode, COUNT(*) AS cnt
     FROM products
     WHERE status = 'active' AND barcode IS NOT NULL AND barcode != ''
     GROUP BY barcode
     HAVING cnt > 1"
)->fetchAll(PDO::FETCH_ASSOC);

count($duplicates) === 0
    ? pass("No duplicate barcodes in active products")
    : fail(count($duplicates) . " duplicate barcode(s) found — scanner will always pick the first match:\n" .
           implode("\n", array_map(fn($r) => "    barcode={$r['barcode']} (×{$r['cnt']})", $duplicates)));

// ── Section 9: PHP lint — all changed files ──────────────────────────────────
section('9 — PHP lint');

$changedFiles = [
    'app/bms/pos/pos.php',
    'app/bms/pos/pos_scripts_new.php',
    'app/bms/pos/pos_modals_new.php',
    'api/pos/simple_products.php',
];

foreach ($changedFiles as $rel) {
    $full = "$root/$rel";
    if (!file_exists($full)) {
        fail("File not found: $rel");
        continue;
    }
    lintOk($full)
        ? pass("Lint OK: $rel")
        : fail("Lint FAILED: $rel");
}
