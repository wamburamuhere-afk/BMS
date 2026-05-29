<?php
/**
 * Asset Depreciation Phase 1 — CLI test
 * --------------------------------------
 *   php tests/test_asset_depreciation_phase1_cli.php
 *
 * Phase 1 ships the foundation:
 *   - asset_categories table + TRA-class seed
 *   - depreciation columns on assets
 *   - asset_depreciation_runs table with UNIQUE KEY guard
 *   - CRUD APIs for categories
 *   - Asset form fields wired through save_asset.php
 *   - Asset Categories admin page
 *
 * Tests assert the schema state + source-code contracts + a quick CRUD round-
 * trip against the live DB.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
// ─────────────────────────────────────────────────────────────────────────
$files = [
    'migrations/2026_05_28_asset_categories.php',
    'migrations/2026_05_28_assets_depreciation_columns.php',
    'migrations/2026_05_28_asset_depreciation_runs.php',
    'api/assets/get_asset_categories.php',
    'api/assets/save_asset_category.php',
    'api/operations/save_asset.php',
    'app/constant/settings/asset_categories.php',
    'app/bms/operations/assets.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0;
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    if ($rc === 0) pass($f); else fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema state (live DB)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'asset_categories'")->fetch();
$exists ? pass('asset_categories table exists') : fail('asset_categories missing');

$exists = (bool)$pdo->query("SHOW TABLES LIKE 'asset_depreciation_runs'")->fetch();
$exists ? pass('asset_depreciation_runs table exists') : fail('asset_depreciation_runs missing');

// New columns on assets
$expected_cols = [
    'category_id', 'useful_life_years', 'annual_rate_percent',
    'depreciation_method', 'salvage_value', 'depreciation_start_date',
    'accumulated_depreciation', 'last_depreciation_date',
    'disposal_date', 'disposal_proceeds', 'disposal_gain_loss',
];
$asset_cols = array_column($pdo->query("SHOW COLUMNS FROM assets")->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach ($expected_cols as $c) {
    in_array($c, $asset_cols, true) ? pass("assets.$c present") : fail("assets.$c missing");
}

// asset_id types align so FK works
$a = $pdo->query("SHOW COLUMNS FROM assets WHERE Field='asset_id'")->fetch(PDO::FETCH_ASSOC);
$r = $pdo->query("SHOW COLUMNS FROM asset_depreciation_runs WHERE Field='asset_id'")->fetch(PDO::FETCH_ASSOC);
strtolower($a['Type']) === strtolower($r['Type'])
    ? pass('asset_id types match between assets and asset_depreciation_runs')
    : fail("asset_id type mismatch: assets={$a['Type']}, runs={$r['Type']}");

// UNIQUE KEY guard on (asset_id, period_end_date)
$idx = $pdo->query("SHOW INDEX FROM asset_depreciation_runs WHERE Key_name = 'uq_asset_period'")->fetchAll();
count($idx) === 2
    ? pass('UNIQUE KEY uq_asset_period (asset_id, period_end_date) in place')
    : fail('uq_asset_period missing — would allow double-posting depreciation');

// FK present
$hasFk = (bool)$pdo->query("
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'asset_depreciation_runs'
       AND CONSTRAINT_NAME = 'fk_dep_runs_asset_id'
")->fetchColumn();
$hasFk ? pass('FK fk_dep_runs_asset_id present') : fail('FK fk_dep_runs_asset_id missing');

// Seeded categories
$n = (int)$pdo->query("SELECT COUNT(*) FROM asset_categories")->fetchColumn();
$n >= 5 ? pass("asset_categories seeded ($n rows)") : fail("asset_categories should have >=5 rows, has $n");

foreach (['Buildings & Structures','Heavy Machinery & Plant','Office Equipment & Furniture','Vehicles','Computer Hardware & Software'] as $name) {
    $found = $pdo->prepare("SELECT 1 FROM asset_categories WHERE category_name=?");
    $found->execute([$name]);
    $found->fetch() ? pass("category seeded: $name") : fail("category missing: $name");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. API source contracts');
// ─────────────────────────────────────────────────────────────────────────
$apiSrc = readSrc($root, 'api/assets/save_asset_category.php');
$checks = [
    "canEdit('assets')"          => 'edit-permission check present',
    "canCreate('assets')"        => 'create-permission check present',
    "category_name is required"  => 'validates name',
    "straight_line"              => 'accepts straight_line method',
    "reducing_balance"           => 'accepts reducing_balance method',
    "0..100"                     => 'validates 0-100 ranges',
    "23000"                      => 'handles unique-violation cleanly',
];
foreach ($checks as $needle => $label) {
    strpos($apiSrc, $needle) !== false ? pass($label) : fail("$label — missing `$needle`");
}

$saveSrc = readSrc($root, 'api/operations/save_asset.php');
foreach (['category_id', 'useful_life_years', 'annual_rate_percent', 'depreciation_method', 'salvage_value', 'depreciation_start_date'] as $col) {
    strpos($saveSrc, $col) !== false ? pass("save_asset.php handles $col") : fail("save_asset.php missing $col handling");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. CRUD round-trip against live DB');
// ─────────────────────────────────────────────────────────────────────────
// Insert via API
$testName = '__test_cat_' . time();
$_GET = [];
$_POST = [
    'category_name'              => $testName,
    'tra_class'                  => 'TestClass',
    'default_method'             => 'straight_line',
    'default_useful_life_years'  => 7,
    'default_annual_rate_percent'=> 15,
    'default_salvage_percent'    => 10,
    'status'                     => 'active',
];
$_SERVER['REQUEST_METHOD'] = 'POST';

$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require "$root/api/assets/save_asset_category.php";
$raw = ob_get_clean();
error_reporting($prevErr);
$resp = json_decode($raw, true);

if ($resp && !empty($resp['success']) && !empty($resp['category_id'])) {
    pass('save_asset_category: insert succeeded');
    $new_id = (int)$resp['category_id'];
} else {
    fail('save_asset_category: insert failed — ' . substr($raw, 0, 200));
    $new_id = 0;
}

// Read via API
if ($new_id) {
    $_GET = ['include_archived' => '1'];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); require "$root/api/assets/get_asset_categories.php";
    $raw = ob_get_clean();
    error_reporting($prevErr);
    $resp = json_decode($raw, true);
    if ($resp && !empty($resp['success']) && is_array($resp['categories'])) {
        $found = false;
        foreach ($resp['categories'] as $c) {
            if ((int)$c['category_id'] === $new_id) { $found = true; break; }
        }
        $found ? pass('get_asset_categories: round-trip readback found new row')
               : fail('get_asset_categories: new row not in response');
    } else {
        fail('get_asset_categories: bad response');
    }

    // Cleanup test row (duplicate-name path is covered by the source-code
    // grep above + the DB-level UNIQUE constraint, so no runtime re-test).
    $pdo->prepare("DELETE FROM asset_categories WHERE category_id = ?")->execute([$new_id]);
    pass('test category cleaned up');
}

exit($failures === 0 ? 0 : 1);
