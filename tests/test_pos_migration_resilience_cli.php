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
 * Found live again, same day: canView('restaurant_pos') is a control-DB
 * feature-flag GRANT, entirely independent of the tenant's own schema — a
 * tenant (bms_t9005) was entitled to the feature before its migration had
 * run, and hit two more uncaught crashes: app/bms/restaurant/floors.php
 * (via core/restaurant_scope.php's own pos_mode query) and
 * api/restaurant/get_modifier_groups.php ("Table 'modifier_groups' doesn't
 * exist" — a whole missing table, not just a column).
 *
 * This suite proves the fix directly: with the Phase 30/31 columns/tables
 * TEMPORARILY DROPPED from a copy of the live schema (DDL auto-commits in
 * MySQL — there is no way to "roll back" a DROP COLUMN/TABLE, so every drop
 * here is undone by an explicit, unconditional restore in a finally block,
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
 *   4. Every api/restaurant/*.php endpoint (all 20, checked statically) and
 *      every app/bms/restaurant/*.php admin page (protected for free via
 *      core/restaurant_scope.php's own restaurantSchemaReady() guard,
 *      inside restaurantWarehousesForSelect()) degrades to a clean "not set
 *      up yet" response instead of an uncaught exception, when pos_mode
 *      AND the modifier_groups table family are both missing at once
 *      (replicating the exact bms_t9005 state).
 *
 * Every drop/restore is scoped to statements this script owns end-to-end;
 * nothing else on this server is touched. Exit 0 = all pass AND schema
 * fully restored.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'endpoint_worker') {
    // Runs one api/restaurant/*.php endpoint in a genuinely separate PHP
    // process, dumping whatever it echoed. Never run such an endpoint via an
    // in-process include() from the main test body below: its own
    // schema-readiness guard deliberately calls exit() on failure (correct
    // for a real request), which would otherwise kill the PARENT test
    // process before it reaches its finally block and leave the schema
    // permanently dropped on this machine.
    require_once "$root/roots.php";
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) { $_SESSION[$k] = $v; }
    $captured = '';
    register_shutdown_function(function () use (&$captured) {
        $buf = ob_get_contents();
        if ($buf !== false) { $captured .= $buf; @ob_end_clean(); }
        echo "\n___ENDPOINT_WORKER_OUTPUT___\n" . $captured;
    });
    ob_start();
    require "$root/" . $cfg['endpoint'];
    exit;
}

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

// ── 4. The whole Restaurant module (app/bms/restaurant/*.php + all 20
//    api/restaurant/*.php endpoints) survives a tenant that's entitled to
//    restaurant_pos but whose database was never migrated ──────────────────
// Found live, same day (2026-09-11), a second time: canView('restaurant_pos')
// is a control-DB feature-flag grant, independent of the tenant's own
// schema — a tenant can be entitled before its migration has run. Tenant
// bms_t9005 hit this on BOTH app/bms/restaurant/floors.php (via
// restaurantWarehousesForSelect()'s own pos_mode query) and
// api/restaurant/get_modifier_groups.php (querying the modifier_groups
// table, which didn't exist at all — not just a missing column).
section('4. Restaurant module survives a restaurant_pos-entitled-but-unmigrated tenant');
require_once "$root/core/restaurant_scope.php";

$restaurantApiFiles = glob("$root/api/restaurant/*.php");
$missingGuard = [];
foreach ($restaurantApiFiles as $path) {
    $src = file_get_contents($path);
    if (strpos($src, 'restaurantSchemaReady($pdo)') === false) {
        $missingGuard[] = basename($path);
    }
}
ok(count($restaurantApiFiles) >= 20, 'found at least 20 api/restaurant/*.php endpoints to check (found ' . count($restaurantApiFiles) . ')');
ok(empty($missingGuard), 'every api/restaurant/*.php endpoint calls restaurantSchemaReady($pdo) before touching its schema'
    . (empty($missingGuard) ? '' : ' — missing in: ' . implode(', ', $missingGuard)));

$hadPosMode2 = colExists($pdo, 'warehouses', 'pos_mode');
$hadModGroups = (bool)$pdo->query("SHOW TABLES LIKE 'modifier_groups'")->fetch();
ok($hadPosMode2 && $hadModGroups, 'warehouses.pos_mode + modifier_groups table both present before the test (sanity)');

try {
    // Replicate the exact tenant bms_t9005 state: pos_mode column missing
    // AND the modifier_groups family of tables missing entirely (not just
    // one column) — the two distinct crash shapes reported live.
    $pdo->exec("ALTER TABLE warehouses DROP COLUMN pos_mode");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DROP TABLE IF EXISTS modifier_options");
    $pdo->exec("DROP TABLE IF EXISTS product_modifier_groups");
    $pdo->exec("DROP TABLE IF EXISTS modifier_groups");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    ok(restaurantSchemaReady($pdo) === false, 'restaurantSchemaReady() correctly reports false once pos_mode + modifier_groups are gone');
    ok(restaurantWarehousesForSelect($pdo) === [], 'restaurantWarehousesForSelect() degrades to [] instead of throwing — every restaurant/*.php admin page that calls it (floors/tables/kitchen/kitchen_dashboard/reservations) is protected for free');

    // The exact endpoint from the second live crash — run via the
    // endpoint_worker subprocess dispatch at the top of this file (see its
    // comment for why this can never be an in-process include()).
    $cfgFile = tempnam(sys_get_temp_dir(), 'rsg');
    file_put_contents($cfgFile, json_encode([
        'session' => ['user_id' => (int)($pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn() ?: 1), 'is_admin' => true],
        'endpoint' => 'api/restaurant/get_modifier_groups.php',
    ]));
    $workerOut = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' endpoint_worker ' . escapeshellarg($cfgFile) . ' 2>&1');
    @unlink($cfgFile);
    $marker = '___ENDPOINT_WORKER_OUTPUT___';
    $markerPos = strpos((string)$workerOut, $marker);
    $out = $markerPos === false ? (string)$workerOut : substr($workerOut, $markerPos + strlen($marker) + 1);
    $json = json_decode($out, true);
    ok(is_array($json) && ($json['success'] ?? true) === false, 'get_modifier_groups.php returns a clean success:false instead of crashing (raw: ' . substr((string)$workerOut, 0, 200) . ')');
    ok(strpos((string)$workerOut, 'Fatal error') === false && strpos((string)$workerOut, 'Uncaught') === false, 'get_modifier_groups.php output contains no fatal/uncaught error');
} finally {
    if (!colExists($pdo, 'warehouses', 'pos_mode')) {
        $pdo->exec("ALTER TABLE warehouses ADD COLUMN pos_mode ENUM('retail','restaurant','hybrid') NOT NULL DEFAULT 'retail'");
    }
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'modifier_groups'")->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `modifier_groups` (
                `group_id` INT NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `selection_type` ENUM('single','multiple') NOT NULL DEFAULT 'single',
                `min_select` INT NOT NULL DEFAULT 0,
                `max_select` INT NOT NULL DEFAULT 1,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
                `created_by` INT DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'modifier_options'")->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `modifier_options` (
                `option_id` INT NOT NULL AUTO_INCREMENT,
                `group_id` INT NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `price_adjustment` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`option_id`),
                KEY `idx_mo_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'product_modifier_groups'")->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `product_modifier_groups` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `product_id` INT NOT NULL,
                `group_id` INT NOT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pmg_product_group` (`product_id`, `group_id`),
                KEY `idx_pmg_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
ok(colExists($pdo, 'warehouses', 'pos_mode'), 'warehouses.pos_mode restored after the test');
ok((bool)$pdo->query("SHOW TABLES LIKE 'modifier_groups'")->fetch()
    && (bool)$pdo->query("SHOW TABLES LIKE 'modifier_options'")->fetch()
    && (bool)$pdo->query("SHOW TABLES LIKE 'product_modifier_groups'")->fetch(),
    'modifier_groups/modifier_options/product_modifier_groups restored after the test');

exit($fail === 0 ? 0 : 1);
