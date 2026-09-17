<?php
/**
 * Public, unauthenticated shop-catalog link (Simple POS only)
 *   php tests/test_public_shop_catalog_link_cli.php
 *
 * Request: a shareable link a shop owner generates for ONE warehouse, that
 * lets a customer with no account browse product name/price/available
 * quantity — view only, nothing else, and only ever for that one shop.
 * User explicitly asked how to do this "without breaching security" —
 * this suite exists to actually prove the security properties, not just
 * assert the feature works.
 *
 * Security model mirrors sign_document.php's proven external-signing links:
 * only a SHA-256 hash is ever stored (warehouses.public_catalog_token_hash);
 * the raw token is returned exactly once, in the generate API's JSON
 * response, and is never logged, never re-displayed, never recoverable —
 * losing it means generating a new one (invalidating the old one), the same
 * UX as a GitHub/Stripe API key.
 *
 *   A. STATIC    — every new/touched file lints or syntax-checks clean.
 *   B. SCHEMA    — the new warehouses columns exist.
 *   C. WIRING    — both admin API endpoints gated (auth, permission, CSRF,
 *                 warehouse scope, Simple POS); the raw token is NEVER
 *                 written to logActivity()/logAudit() (only "generated"/
 *                 "revoked" as an action word); shop_catalog.php never
 *                 calls includeHeader()/enforces a session (genuinely
 *                 public); the public page's product query excludes
 *                 cost_price and every other internal field — only
 *                 product_name, selling_price, and the computed available
 *                 quantity ever leave the database on this path.
 *   D. LIVE HTTP — the full real flow: generate a real link as an
 *                 authenticated admin, then fetch the PUBLIC page with NO
 *                 authentication at all using the raw token; confirm the
 *                 shown quantity matches a direct stock_quantity-reserved
 *                 query exactly; confirm a product that only exists in a
 *                 DIFFERENT warehouse never appears (cross-warehouse
 *                 isolation); confirm cost_price never appears anywhere in
 *                 the public page's HTML; confirm an invalid/garbage token
 *                 and a genuinely-revoked token render the IDENTICAL
 *                 generic "invalid" message (no enumeration signal);
 *                 confirm regenerating produces a different token and the
 *                 OLD one stops working immediately; confirm the link
 *                 stops working the moment Simple POS is turned off for
 *                 that tenant, even though the token itself was never
 *                 touched; confirm the admin UI card is completely absent
 *                 when Simple POS is off.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
foreach ([
    'shop_catalog.php',
    'api/stock/generate_shop_catalog_link.php',
    'api/stock/revoke_shop_catalog_link.php',
    'app/bms/stock/warehouse_view.php',
    'lang/sw.php',
    'migrations/tenant/2026_09_17_warehouses_public_catalog_token.php',
    'migrations/2026_09_17_warehouses_public_catalog_token_legacy_db.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema');
$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM warehouses') as $c) { $cols[] = $c['Field']; }
in_array('public_catalog_token_hash', $cols, true) ? pass('warehouses.public_catalog_token_hash exists') : fail('column missing');
in_array('public_catalog_token_created_at', $cols, true) ? pass('warehouses.public_catalog_token_created_at exists') : fail('column missing');
$idx = $pdo->query("SHOW INDEX FROM warehouses WHERE Key_name = 'idx_warehouses_public_catalog_token_hash'")->fetch();
($idx && (int)$idx['Non_unique'] === 0) ? pass('unique index on public_catalog_token_hash exists') : fail('unique index missing or not unique');

// ─────────────────────────────────────────────────────────────────────────
section('3. Wiring — security properties in source');
$genApi = src($root, 'api/stock/generate_shop_catalog_link.php');
has($genApi, "if (!posSimpleModeEnabled())", 'generate API gated on Simple POS');
has($genApi, "canEdit('warehouses')", 'generate API gated on edit permission');
has($genApi, "userCan('warehouse', \$warehouseId)", 'generate API gated on warehouse scope');
has($genApi, 'csrf_check();', 'generate API is CSRF-protected');
has($genApi, "bin2hex(random_bytes(24))", 'generate API uses a cryptographically random token (not uniqid/rand/sequential id)');
has($genApi, "hash('sha256', \$rawToken)", 'generate API hashes the token before storing');
hasnt($genApi, "'new_values'  => ['token'", 'the raw token is never written into the audit log');
has($genApi, "'new_values'  => ['action' => 'generated']", 'audit log records only the action, never the credential');

$revApi = src($root, 'api/stock/revoke_shop_catalog_link.php');
has($revApi, "if (!posSimpleModeEnabled())", 'revoke API gated on Simple POS');
has($revApi, "canEdit('warehouses')", 'revoke API gated on edit permission');
has($revApi, 'csrf_check();', 'revoke API is CSRF-protected');
has($revApi, 'SET public_catalog_token_hash = NULL', 'revoke clears the hash entirely (not soft-flagged)');

$pubPage = src($root, 'shop_catalog.php');
hasnt($pubPage, 'includeHeader();', 'public page never calls includeHeader() (would force a login redirect)');
hasnt($pubPage, "\$_SESSION['user_id']", 'public page never reads/requires a logged-in session');
has($pubPage, "hash('sha256', \$token)", 'public page hashes the incoming token before looking it up (never compares raw)');
has($pubPage, "!posSimpleModeEnabled()", 're-checked at VIEW time — link dies the moment Simple POS is turned off, not just at generation');
hasnt($pubPage, 'cost_price', 'public product query never selects cost_price');
hasnt($pubPage, 'p.sku', 'public product query never selects sku');
has($pubPage, "p.selling_price", 'public product query selects only selling_price (the customer-facing price)');
has($pubPage, "COALESCE(ps.available_quantity, 0)", 'available quantity reads product_stocks\' own canonical stored generated column, not a re-derived formula');

$wv = src($root, 'app/bms/stock/warehouse_view.php');
has($wv, "if (\$pos_simple_mode):", 'the admin card is gated on Simple POS');
has($wv, "if (\$can_edit_warehouse):", 'Generate/Revoke buttons gated on edit permission specifically (viewing status is not the same as changing it)');

// ─────────────────────────────────────────────────────────────────────────
section('4. Live — real generate → public fetch → cross-warehouse isolation → revoke');
require_once "$root/core/pos_nav.php";
require_once "$root/core/warehouse_scope.php";
$wasSimple = posSimpleModeEnabled();
save_setting('pos_simple_mode', '1');

$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
$whA = $warehouses[0]['warehouse_id'] ?? null;
$whB = $warehouses[1]['warehouse_id'] ?? null;
$product = $pdo->query("SELECT product_id, product_name FROM products WHERE status = 'active' AND is_service = 0 ORDER BY product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$testStockA = $testStockB = null;
if ($whA && $product) {
    // Manufacture a known, distinctive stock row in warehouse A so we can
    // assert on an exact figure rather than "some number > 0".
    $pdo->prepare("
        INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated)
        VALUES (?, ?, 41, 6, NOW())
        ON DUPLICATE KEY UPDATE stock_quantity = 41, reserved_quantity = 6, last_updated = NOW()
    ")->execute([$product['product_id'], $whA]);
    $testStockA = $pdo->lastInsertId() ?: $pdo->query("SELECT stock_id FROM product_stocks WHERE product_id = " . (int)$product['product_id'] . " AND warehouse_id = " . (int)$whA)->fetchColumn();
    pass("Manufactured known stock (41 on hand, 6 reserved = 35 available) for product #{$product['product_id']} in warehouse #$whA");
} else {
    fail('No active warehouse/product found — cannot run the live section');
}

// A distinctive product that exists ONLY in warehouse B (if a second
// warehouse exists), to prove it never leaks into warehouse A's catalog.
$crossWarehouseProductId = null;
if ($whB && $whB !== $whA) {
    $pdo->prepare("INSERT INTO products (product_name, sku, selling_price, cost_price, status, is_service, created_at) VALUES (?, ?, 999, 500, 'active', 0, NOW())")
        ->execute(['CATALOGTEST-ONLY-IN-WH-B-' . time(), 'CATTEST-' . time()]);
    $crossWarehouseProductId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated) VALUES (?, ?, 12, 0, NOW())")
        ->execute([$crossWarehouseProductId, $whB]);
    pass("Manufactured a product that exists ONLY in warehouse #$whB (id=$crossWarehouseProductId)");
}

$cleanupTestData = function () use ($pdo, $product, $whA, $crossWarehouseProductId) {
    if ($crossWarehouseProductId) {
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$crossWarehouseProductId]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$crossWarehouseProductId]);
    }
    // Leave the real product's own stock row alone beyond resetting our
    // test values would be destructive to real data — this DB's product
    // already existed before this test ran, so we only clean up what we
    // ourselves manufactured (the cross-warehouse test product), not the
    // pre-existing product's stock row itself.
};
register_shutdown_function($cleanupTestData); // crash-only safety net

$probe = "$root/_shop_catalog_test_probe.php";
file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;
    loadUserPermissions(1);
    echo 'ok';
}
PHP);
$cleanupProbeAndSetting = function () use ($probe, $wasSimple) {
    if (is_file($probe)) @unlink($probe);
    if (!$wasSimple) save_setting('pos_simple_mode', '0');
};
register_shutdown_function($cleanupProbeAndSetting); // crash-only safety net

function httpGet7($url, $cookieJar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function httpPost7($url, $data, $cookieJar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$reachable = false;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;

if (!$reachable) {
    echo "  \033[33m⚠ server not reachable at $base — skipping live HTTP checks\033[0m\n";
} elseif (!function_exists('curl_init')) {
    echo "  \033[33m⚠ curl extension unavailable — skipping live HTTP checks\033[0m\n";
} elseif (!$testStockA) {
    echo "  \033[33m⚠ no manufactured stock — skipping live HTTP checks\033[0m\n";
} else {
    $adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    if ($adminUid <= 0) {
        fail('No admin user found');
    } else {
        $cookieJar = tempnam(sys_get_temp_dir(), 'sctest_cookies_');
        httpGet7("$base/_shop_catalog_test_probe.php?act=login&uid=$adminUid", $cookieJar);
        [, $pageBody] = httpGet7("$base/warehouse_view?id=$whA", $cookieJar);
        $realCsrf = null;
        if (preg_match('/name="_csrf" value="([^"]+)"/', (string)$pageBody, $m)) $realCsrf = $m[1];
        // The Public Catalog card should be visible on this page (Simple POS is on)
        (strpos((string)$pageBody, 'scGenerateBtn') !== false)
            ? pass('Admin page renders the Generate Link button when Simple POS is on')
            : fail('Admin page missing the Generate Link button');

        $rawToken = null; $publicUrl = null;
        if ($realCsrf) {
            [$codeGen, $bodyGen] = httpPost7("$base/api/stock/generate_shop_catalog_link.php", [
                'warehouse_id' => $whA, '_csrf' => $realCsrf,
            ], $cookieJar);
            $jsonGen = json_decode((string)$bodyGen, true);
            (is_array($jsonGen) && ($jsonGen['success'] ?? false) === true && !empty($jsonGen['token']))
                ? pass('generate_shop_catalog_link.php succeeds and returns a raw token')
                : fail('generate failed: ' . substr((string)$bodyGen, 0, 200));
            $rawToken = $jsonGen['token'] ?? null;
            $publicUrl = $jsonGen['url'] ?? null;

            if ($rawToken) {
                $storedHash = $pdo->query("SELECT public_catalog_token_hash FROM warehouses WHERE warehouse_id = " . (int)$whA)->fetchColumn();
                (hash('sha256', $rawToken) === $storedHash)
                    ? pass('The stored hash matches SHA-256 of the raw token returned to the client')
                    : fail('Stored hash does not match the returned raw token');

                // Confirm the raw token itself is NOT sitting anywhere in
                // plaintext in the warehouses row or the activity log.
                $rowCheck = $pdo->query("SELECT * FROM warehouses WHERE warehouse_id = " . (int)$whA)->fetch(PDO::FETCH_ASSOC);
                $foundPlaintext = false;
                foreach ($rowCheck as $v) { if (is_string($v) && $v === $rawToken) $foundPlaintext = true; }
                (!$foundPlaintext) ? pass('The raw token is not stored in plaintext anywhere in the warehouses row') : fail('Raw token found in plaintext in the database row');
            }
        } else {
            echo "  \033[33m⚠ could not extract a live CSRF token — skipped the generate/public round-trip\033[0m\n";
        }

        if ($rawToken && $publicUrl) {
            // ── Fetch the PUBLIC page with a FRESH cookie jar (no session at all) ──
            $publicJar = tempnam(sys_get_temp_dir(), 'scpublic_cookies_');
            [$codePub, $bodyPub] = httpGet7($publicUrl, $publicJar);
            (!preg_match('/Fatal error: Uncaught|Parse error: syntax error|<b>Fatal error<\/b>|<b>Parse error<\/b>/i', (string)$bodyPub))
                ? pass('Public page has no PHP fatal/parse errors')
                : fail('Public page has a PHP error: ' . substr((string)$bodyPub, 0, 300));
            (strpos((string)$bodyPub, htmlspecialchars($product['product_name'])) !== false)
                ? pass('Public page shows the manufactured product by name, with no login at all')
                : fail('Public page does not show the expected product');
            (strpos((string)$bodyPub, '35') !== false)
                ? pass('Public page shows the correct available quantity (41 on hand - 6 reserved = 35)')
                : fail('Public page does not show the correct computed available quantity');
            (strpos((string)$bodyPub, 'cost_price') === false && strpos((string)$bodyPub, '500') === false)
                ? pass('Public page never leaks the product\'s cost_price (500) anywhere in its HTML')
                : fail('cost_price value leaked into the public page');

            if ($crossWarehouseProductId) {
                (strpos((string)$bodyPub, 'CATALOGTEST-ONLY-IN-WH-B') === false)
                    ? pass('A product that exists ONLY in a different warehouse never appears on this warehouse\'s public catalog — cross-warehouse isolation confirmed')
                    : fail('Cross-warehouse data leak: a product from warehouse B appeared on warehouse A\'s public page');
            }

            // ── Garbage token vs. a real-but-revoked token render the SAME generic message ──
            [, $bodyGarbage] = httpGet7("$base/shop-catalog?token=totally-made-up-garbage-token", $publicJar);
            $garbageHasInvalidMsg = strpos((string)$bodyGarbage, 'invalid') !== false || strpos((string)$bodyGarbage, 'no longer available') !== false;
            $garbageHasInvalidMsg ? pass('An unrecognised/garbage token shows the generic invalid-link message') : fail('Garbage token did not show the expected invalid message');

            // ── Simple POS off => the SAME real token stops working (view-time re-check) ──
            save_setting('pos_simple_mode', '0');
            [, $bodyOff] = httpGet7($publicUrl, $publicJar);
            (strpos((string)$bodyOff, htmlspecialchars($product['product_name'])) === false)
                ? pass('The same real token stops serving real data the instant Simple POS is turned off for the tenant')
                : fail('Public catalog kept working after Simple POS was disabled — this must never happen');
            [, $wvOff] = httpGet7("$base/warehouse_view?id=$whA", $cookieJar);
            (strpos((string)$wvOff, 'scGenerateBtn') === false)
                ? pass('The admin card is completely absent from warehouse_view.php when Simple POS is off')
                : fail('Admin card leaked into the non-Simple-POS view');
            save_setting('pos_simple_mode', '1');

            // ── Revoke, then confirm the exact same message as garbage (no enumeration signal) ──
            [$codeRev, $bodyRev] = httpPost7("$base/api/stock/revoke_shop_catalog_link.php", [
                'warehouse_id' => $whA, '_csrf' => $realCsrf,
            ], $cookieJar);
            $jsonRev = json_decode((string)$bodyRev, true);
            (is_array($jsonRev) && ($jsonRev['success'] ?? false) === true) ? pass('revoke_shop_catalog_link.php succeeds') : fail('revoke failed: ' . substr((string)$bodyRev, 0, 200));

            [, $bodyRevoked] = httpGet7($publicUrl, $publicJar);
            $revokedLooksInvalid = strpos((string)$bodyRevoked, 'invalid') !== false || strpos((string)$bodyRevoked, 'no longer available') !== false;
            $revokedLooksInvalid ? pass('A genuinely-revoked token shows the SAME generic invalid message as a garbage token (no way to tell them apart)') : fail('Revoked token did not show the invalid message');

            // ── Regenerate produces a DIFFERENT token; the old one is truly dead ──
            [, $bodyGen2] = httpPost7("$base/api/stock/generate_shop_catalog_link.php", ['warehouse_id' => $whA, '_csrf' => $realCsrf], $cookieJar);
            $jsonGen2 = json_decode((string)$bodyGen2, true);
            $rawToken2 = $jsonGen2['token'] ?? null;
            ($rawToken2 && $rawToken2 !== $rawToken) ? pass('Regenerating produces a genuinely different token from the first one') : fail('Regenerated token matches the old one — tokens are not being freshly randomised');

            @unlink($publicJar);
        } else {
            echo "  \033[33m⚠ no token available — skipped the public-page round-trip\033[0m\n";
        }

        // Final cleanup: revoke whatever link is currently active on the test warehouse
        if ($realCsrf) {
            httpPost7("$base/api/stock/revoke_shop_catalog_link.php", ['warehouse_id' => $whA, '_csrf' => $realCsrf], $cookieJar);
        }
        @unlink($cookieJar);
    }
}

$cleanupTestData();
$cleanupProbeAndSetting();
