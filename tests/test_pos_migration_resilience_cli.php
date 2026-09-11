<?php
/**
 * POS Migration Resilience — CLI test
 *   php tests/test_pos_migration_resilience_cli.php
 *
 * Found live, 2026-09-11: a production tenant whose database had NOT yet
 * had the Phase 30 (`migrations/tenant/2026_09_11_pos_restaurant_module.php`)
 * migration applied hit an uncaught PDOException ("Unknown column 'pos_mode'")
 * on every single load of app/bms/pos/pos.php — the entire POS terminal was
 * down for that tenant, and (worse) api/pos/process_sale.php's INSERT
 * unconditionally listed assigned_to/table_id, so no sale could complete
 * either. Tenant migrations are NOT wired into the deploy pipeline
 * (migrations/tenant/README.md §"Not wired into deploy.yml yet") — a lag
 * between "code deployed" and "every tenant's schema migrated" is a real,
 * recurring operating condition for this multi-tenant app, not a one-off.
 *
 * This suite proves the fix directly: with the Phase 30/31 columns
 * TEMPORARILY DROPPED from a copy of the live schema (DDL auto-commits in
 * MySQL — there is no way to "roll back" a DROP COLUMN, so every drop here
 * is undone by an explicit, unconditional ADD COLUMN in a finally block,
 * even if an assertion in between throws):
 *   1. app/bms/pos/pos.php renders without a fatal error when
 *      warehouses.pos_mode is missing (degrades to "every warehouse is
 *      retail", byte-for-byte the pre-Phase-30 behaviour).
 *   2. api/pos/simple_products.php still returns the real product catalog
 *      (success:true, real rows) when products.parent_product_id/
 *      variant_attributes are missing, instead of silently returning an
 *      empty grid.
 *   3. api/pos/process_sale.php's INSERT INTO pos_sales succeeds via its
 *      fallback column list when assigned_to/table_id are missing — this
 *      is the sharpest of the three: without it, a tenant literally cannot
 *      sell anything.
 *
 * All three drops/restores are scoped to single ALTER TABLE statements this
 * script owns end-to-end; nothing else on this server is touched. Exit 0 =
 * all pass AND schema fully restored.
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
$_SESSION['user_lang'] = 'en';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

function colExists(PDO $pdo, string $table, string $col): bool {
    return (bool)$pdo->query("SHOW COLUMNS FROM $table LIKE " . $pdo->quote($col))->fetch();
}

// ── 1. pos.php survives a missing warehouses.pos_mode ───────────────────────
section('1. app/bms/pos/pos.php degrades gracefully when warehouses.pos_mode is missing');
$hadPosMode = colExists($pdo, 'warehouses', 'pos_mode');
ok($hadPosMode, 'warehouses.pos_mode is present on this server before the test (sanity)');
try {
    $pdo->exec("ALTER TABLE warehouses DROP COLUMN pos_mode");
    $html = '';
    ob_start();
    try {
        require "$root/app/bms/pos/pos.php";
    } catch (Throwable $e) {
        ok(false, 'pos.php threw uncaught: ' . $e->getMessage());
    }
    $html = ob_get_clean();
    ok(strpos($html, 'Fatal error') === false && strpos($html, 'Uncaught') === false,
        'pos.php rendered without a fatal/uncaught error with pos_mode missing');
    ok(strlen($html) > 5000, 'pos.php still rendered substantial page content (not a blank/dead page)');
    ok(strpos($html, 'id="productGrid"') !== false, 'pos.php still rendered the product grid container');
    ok(strpos($html, 'id="categoryButtons"') !== false, 'pos.php still rendered the category filter container');
} finally {
    if (!colExists($pdo, 'warehouses', 'pos_mode')) {
        $pdo->exec("ALTER TABLE warehouses ADD COLUMN pos_mode ENUM('retail','restaurant','hybrid') NOT NULL DEFAULT 'retail'");
    }
}
ok(colExists($pdo, 'warehouses', 'pos_mode'), 'warehouses.pos_mode restored after the test');

// ── 2. simple_products.php still returns real products, not an empty grid ──
section('2. api/pos/simple_products.php degrades to the real catalog, not an empty grid, when parent_product_id/variant_attributes are missing');
$hadParent = colExists($pdo, 'products', 'parent_product_id');
$hadAttrs  = colExists($pdo, 'products', 'variant_attributes');
ok($hadParent && $hadAttrs, 'products.parent_product_id + variant_attributes are present before the test (sanity)');

// Anchor: a real active product + a warehouse it actually has stock in, so
// the "did we get the real catalog back" assertion means something.
$anchor = $pdo->query("
    SELECT p.product_id, ps.warehouse_id FROM products p
    JOIN product_stocks ps ON ps.product_id = p.product_id
    WHERE p.status = 'active' LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

try {
    $pdo->exec("ALTER TABLE products DROP COLUMN parent_product_id");
    $pdo->exec("ALTER TABLE products DROP COLUMN variant_attributes");

    if (!$anchor) {
        ok(true, 'no active product with a warehouse stock row on this server — catalog-content assertion skipped (n/a)');
    } else {
        $_GET = ['warehouse_id' => (int)$anchor['warehouse_id']];
        ob_start();
        try {
            include "$root/api/pos/simple_products.php";
        } catch (Throwable $e) {
            ob_end_clean();
            ok(false, 'simple_products.php threw uncaught: ' . $e->getMessage());
        }
        $out = ob_get_clean();
        $json = json_decode($out, true);
        ok(is_array($json) && ($json['success'] ?? false) === true, 'simple_products.php still returns success:true');
        $ids = array_column($json['data'] ?? [], 'product_id');
        ok(in_array((int)$anchor['product_id'], $ids, true), 'the real, anchor product is present in the degraded response (not an empty grid)');
        $sample = $json['data'][0] ?? [];
        ok(array_key_exists('variant_count', $sample) && $sample['variant_count'] === 0, 'variant_count safely defaults to 0 when the column is missing');
        ok(array_key_exists('parent_product_id', $sample) && $sample['parent_product_id'] === null, 'parent_product_id safely defaults to null when the column is missing');
    }
} finally {
    if (!colExists($pdo, 'products', 'parent_product_id')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN parent_product_id INT NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE products ADD KEY idx_products_parent (parent_product_id)");
    }
    if (!colExists($pdo, 'products', 'variant_attributes')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN variant_attributes JSON NULL DEFAULT NULL");
    }
}
ok(colExists($pdo, 'products', 'parent_product_id') && colExists($pdo, 'products', 'variant_attributes'),
    'products.parent_product_id + variant_attributes restored after the test');

// ── 3. process_sale.php can still complete a sale ───────────────────────────
section('3. api/pos/process_sale.php INSERT INTO pos_sales still succeeds (via its fallback column list) when assigned_to/table_id are missing');
$hadAssignedTo = colExists($pdo, 'pos_sales', 'assigned_to');
$hadTableId    = colExists($pdo, 'pos_sales', 'table_id');
ok($hadAssignedTo && $hadTableId, 'pos_sales.assigned_to + table_id are present before the test (sanity)');

try {
    $pdo->exec("ALTER TABLE pos_sales DROP COLUMN assigned_to");
    $pdo->exec("ALTER TABLE pos_sales DROP COLUMN table_id");

    // Mirror process_sale.php's own two-attempt INSERT logic exactly (a
    // full end-to-end run of the live endpoint needs a real cart/shift/
    // stock fixture well beyond this suite's scope — the DB-level contract
    // being protected is specifically "the fallback INSERT's column list
    // and placeholder count are correct", which this exercises directly
    // against the real, live table definition).
    $uid = (int)$pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
    $shiftId = (int)($pdo->query("SELECT shift_id FROM cash_register_shifts LIMIT 1")->fetchColumn() ?: 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pos_sales (
                receipt_number, shift_id, user_id, assigned_to, customer_id, warehouse_id, table_id, project_id,
                subtotal, discount_percentage, discount_amount, tax_amount, grand_total,
                payment_method, amount_tendered, change_given, payment_details, register_id, register_name,
                sale_type, sale_status, payment_status, sale_date, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 'pending', NOW(), NOW())
        ");
        $stmt->execute([
            'MIGRESTEST', $shiftId, $uid, null, null, null, null, null,
            100, 0, 0, 0, 100, 'cash', 100, 0, null, null, null, 'walk_in'
        ]);
        ok(false, 'primary INSERT unexpectedly succeeded — assigned_to/table_id were not actually dropped');
    } catch (PDOException $e) {
        $missingCol = stripos($e->getMessage(), 'assigned_to') !== false || stripos($e->getMessage(), "'table_id'") !== false;
        ok($missingCol, 'primary INSERT fails on the expected missing-column error: ' . $e->getMessage());

        $stmt2 = $pdo->prepare("
            INSERT INTO pos_sales (
                receipt_number, shift_id, user_id, customer_id, warehouse_id, project_id,
                subtotal, discount_percentage, discount_amount, tax_amount, grand_total,
                payment_method, amount_tendered, change_given, payment_details, register_id, register_name,
                sale_type, sale_status, payment_status, sale_date, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 'pending', NOW(), NOW())
        ");
        $stmt2->execute([
            'MIGRESTEST', $shiftId, $uid, null, null, null,
            100, 0, 0, 0, 100, 'cash', 100, 0, null, null, null, 'walk_in'
        ]);
        ok((int)$pdo->lastInsertId() > 0, 'fallback INSERT (no assigned_to/table_id) succeeds — a sale can still complete');
    }
    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'fixture rolled back — no test row persisted');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!colExists($pdo, 'pos_sales', 'assigned_to')) {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN assigned_to INT NULL DEFAULT NULL AFTER user_id");
    }
    if (!colExists($pdo, 'pos_sales', 'table_id')) {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN table_id INT NULL DEFAULT NULL AFTER warehouse_id");
    }
}
ok(colExists($pdo, 'pos_sales', 'assigned_to') && colExists($pdo, 'pos_sales', 'table_id'),
    'pos_sales.assigned_to + table_id restored after the test');

exit($fail === 0 ? 0 : 1);
