<?php
/**
 * migrations/tenant/2026_09_08_seed_geography_reference_data.php
 *
 * Reported live 2026-09-08: a newly created company has the countries/regions/
 * districts/wards/villages TABLES (schema/tenant_schema_template.sql already
 * creates them) but zero ROWS in them — so the address dropdowns (country ->
 * region -> district -> ward -> village) used on Customer, Supplier, and
 * Sub-Contractor forms are empty for every new tenant. Root cause: this data
 * was never included when Chart-of-Accounts/permissions seeding was built
 * (schema/tenant_seed_defaults.sql, multi-tenancy Phase 2) — a gap, not a bug
 * introduced by a change.
 *
 * core/tenant_provisioner.php now applies schema/tenant_geography_seed.sql
 * (~33,700 rows of public Tanzania administrative geography — no tenant data,
 * no privacy concern, correct identically for every company) for every FUTURE
 * signup. This migration backfills every tenant that already exists and is
 * still missing it.
 *
 * Idempotent: skips entirely if `countries` already has any rows — covers
 * both "already backfilled by this migration" and "provisioned after the
 * provisioner fix, so already seeded at creation time".
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: seed geography reference data (countries/regions/districts/wards/villages)...\n";

try {
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM countries")->fetchColumn();
    if ($existing > 0) {
        echo "  . countries already has $existing row(s) — assuming already seeded, skipping.\n";
        echo "Migration complete (no-op).\n";
        exit(0);
    }

    $seedFile = __DIR__ . '/../../schema/tenant_geography_seed.sql';
    if (!is_file($seedFile)) {
        throw new RuntimeException('schema/tenant_geography_seed.sql is missing from the deployment.');
    }
    $sql = file_get_contents($seedFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('schema/tenant_geography_seed.sql could not be read or is empty.');
    }

    $pdo->exec($sql);

    $counts = [];
    foreach (['countries', 'regions', 'districts', 'wards', 'villages'] as $t) {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    echo "  + seeded: " . json_encode($counts) . "\n";

    if ($counts['countries'] < 1 || $counts['regions'] < 1) {
        throw new RuntimeException('Seed ran but countries/regions are still empty — aborting rather than leaving a half-seeded tenant.');
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
