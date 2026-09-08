<?php
/**
 * Tenant address reference data (country/region/district/ward/village) — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_tenant_geography_seed_cli.php
 *
 * Reported live 2026-09-08: a newly created company has the countries/regions/
 * districts/wards/villages TABLES (schema/tenant_schema_template.sql already
 * creates them for every tenant) but zero ROWS, so the Country -> Region ->
 * District -> Ward -> Village address dropdowns on Customer/Supplier/
 * Sub-Contractor forms are empty for every new tenant, even though they work
 * fine on the legacy/reference install. Root cause: this data was never
 * included when Chart-of-Accounts/permissions seeding was built
 * (schema/tenant_seed_defaults.sql) — a gap, not a regression.
 *
 * Fixed with two pieces, both verified here:
 *   1. schema/tenant_geography_seed.sql (new) — applied by
 *      core/tenant_provisioner.php as its own 'apply_geography' step, right
 *      after tenant_seed_defaults.sql, so every FUTURE signup gets this data.
 *   2. migrations/tenant/2026_09_08_seed_geography_reference_data.php (new) —
 *      backfills every tenant that already exists, idempotently.
 *
 * Verifies:
 *   1. Files lint-clean.
 *   2. Wiring: provisionTenant()'s own apply-step list includes
 *      'apply_geography' pointing at the new seed file; the migration file
 *      follows the required migrations/tenant/ shape (CLI-only guard, uses
 *      tenant_migration_bootstrap.php, never requires roots.php/config.php).
 *   3. Live-DB (real throwaway databases, dropped after):
 *      a) The seed file applies cleanly to a bare schema-only database and
 *         produces the EXACT row counts the source reference data has —
 *         proving the dump wasn't truncated/corrupted and every table landed.
 *      b) The migration is genuinely idempotent: first run seeds a
 *         synthetic "pre-existing tenant" (schema only, no geography — the
 *         exact shape of every company created before this fix), a second
 *         run is a true no-op, and neither run duplicates rows.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

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

$geoFile   = "$root/schema/tenant_geography_seed.sql";
$migFile   = "$root/migrations/tenant/2026_09_08_seed_geography_reference_data.php";
$provFile  = "$root/core/tenant_provisioner.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ([$migFile, $provFile] as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($f) . " 2>&1", $o, $rc);
    $rc === 0 ? pass(basename($f) . ' lint-clean') : fail(basename($f) . ' lint failed: ' . implode(' ', $o));
}
is_file($geoFile) ? pass('schema/tenant_geography_seed.sql exists') : fail('schema/tenant_geography_seed.sql is missing');

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$provSrc = file_get_contents($provFile);
$migSrc  = file_get_contents($migFile);

$checks = [
    [$provSrc, "'apply_geography' => __DIR__ . '/../schema/tenant_geography_seed.sql',",
        'provisionTenant() applies the new geography seed file as its own step, right after the existing ones'],
    [$migSrc, "require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';",
        'migration connects via tenant_migration_bootstrap.php (the ONE tenant this run is processing)'],
    [$migSrc, "if (PHP_SAPI !== 'cli')",
        'migration refuses to run over HTTP'],
    [$migSrc, "SELECT COUNT(*) FROM countries",
        'migration checks for existing data before seeding (idempotency guard)'],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}
(strpos($migSrc, "require_once __DIR__ . '/../../roots.php'") === false
    && strpos($migSrc, "require_once __DIR__ . '/../../includes/config.php'") === false)
    ? pass('migration never requires roots.php/config.php (would silently reconnect $pdo to the wrong database)')
    : fail('migration incorrectly requires roots.php or config.php — would reconnect to the main database mid-run');

// ─────────────────────────────────────────────────────────────────────────
section('3a. Live-DB — seed file applies cleanly with exact row counts');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$expected = ['countries' => 10, 'regions' => 31, 'districts' => 178, 'wards' => 3997, 'villages' => 29472];
$freshDb  = 'bms_geo_test_fresh_' . time();
try {
    $pdo->exec("CREATE DATABASE `$freshDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $tpdo = new PDO("mysql:host=localhost;dbname=$freshDb;charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tpdo->exec(file_get_contents("$root/schema/tenant_schema_template.sql"));
    pass('bare schema applied to a fresh throwaway database');

    $tpdo->exec(file_get_contents($geoFile));
    pass('geography seed file applied without error');

    $ok = true;
    $actual = [];
    foreach ($expected as $t => $expectedCount) {
        $actual[$t] = (int)$tpdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        if ($actual[$t] !== $expectedCount) $ok = false;
    }
    $ok ? pass('every table has the exact expected row count: ' . json_encode($actual))
        : fail('row counts do not match — expected ' . json_encode($expected) . ' got ' . json_encode($actual));

    // Spot-check real content, not just counts — proves the dump's actual
    // rows are intact, not just the right number of blank/malformed ones.
    $tz = $tpdo->query("SELECT country_id FROM countries WHERE country_name = 'Tanzania'")->fetchColumn();
    $tz ? pass('Tanzania is present in countries') : fail('Tanzania not found in countries');

    $tpdo = null;
} catch (Throwable $e) {
    fail('section 3a threw: ' . $e->getMessage());
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$freshDb`");
    pass('fresh throwaway database dropped');
}

// ─────────────────────────────────────────────────────────────────────────
section('3b. Live-DB — migration backfills an existing (pre-fix) tenant, idempotently');
// ─────────────────────────────────────────────────────────────────────────
$legacyDb = 'bms_geo_test_legacy_' . time();
try {
    // Mirrors the exact shape of every tenant created BEFORE this fix: schema
    // present, geography tables present but empty (no apply_geography step
    // existed yet at their creation time).
    $pdo->exec("CREATE DATABASE `$legacyDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $tpdo = new PDO("mysql:host=localhost;dbname=$legacyDb;charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tpdo->exec(file_get_contents("$root/schema/tenant_schema_template.sql"));
    $before = (int)$tpdo->query("SELECT COUNT(*) FROM countries")->fetchColumn();
    $before === 0 ? pass('synthetic pre-fix tenant starts with zero geography rows, as expected')
                  : fail("synthetic tenant unexpectedly has $before countries before the migration");

    putenv('TENANT_MIGRATION_DB_HOST=localhost');
    putenv("TENANT_MIGRATION_DB_NAME=$legacyDb");
    putenv('TENANT_MIGRATION_DB_USER=' . DB_USERNAME);
    putenv('TENANT_MIGRATION_DB_PASS=' . DB_PASSWORD);

    $out1 = []; $rc1 = 0;
    exec("php " . escapeshellarg($migFile) . " 2>&1", $out1, $rc1);
    $rc1 === 0 ? pass('first migration run exits 0') : fail('first run failed: ' . implode(' ', $out1));

    $afterFirst = [];
    foreach (array_keys($expected) as $t) { $afterFirst[$t] = (int)$tpdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    ($afterFirst == $expected)
        ? pass('first run seeded every table to the exact expected count: ' . json_encode($afterFirst))
        : fail('first run row counts wrong: ' . json_encode($afterFirst));

    $out2 = []; $rc2 = 0;
    exec("php " . escapeshellarg($migFile) . " 2>&1", $out2, $rc2);
    $rc2 === 0 ? pass('second (repeat) migration run also exits 0') : fail('second run failed: ' . implode(' ', $out2));
    (stripos(implode(' ', $out2), 'skipping') !== false)
        ? pass('second run correctly reports skipping (idempotency guard fired)')
        : fail('second run did not report skipping — output: ' . implode(' ', $out2));

    $afterSecond = [];
    foreach (array_keys($expected) as $t) { $afterSecond[$t] = (int)$tpdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    ($afterSecond == $afterFirst)
        ? pass('running the migration twice did not duplicate any rows')
        : fail('row counts changed on the second run — duplication risk: ' . json_encode($afterSecond));

    putenv('TENANT_MIGRATION_DB_HOST');
    putenv('TENANT_MIGRATION_DB_NAME');
    putenv('TENANT_MIGRATION_DB_USER');
    putenv('TENANT_MIGRATION_DB_PASS');
    $tpdo = null;
} catch (Throwable $e) {
    fail('section 3b threw: ' . $e->getMessage());
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$legacyDb`");
    pass('synthetic legacy-tenant database dropped');
}

exit($failures === 0 ? 0 : 1);
