<?php
/**
 * migrations/tenant/2026_09_15_pos_default_register.php
 *
 * `pos_registers` has existed since day one but schema/tenant_schema_template.sql
 * is DDL-only (no seed rows) — its AUTO_INCREMENT=2 shows the source database
 * this template was captured from already had a register #1, but that row was
 * never carried into the template. Every tenant since has provisioned with a
 * genuinely EMPTY pos_registers table.
 *
 * That silently breaks Start Shift for any tenant without the 'pos_advanced'
 * entitlement: pos_config_settings.php promises "You still have one default
 * register/till to sign in at", and the Start Shift modal's JS falls back to a
 * hardcoded register_id=1 guess when it can't load the real list — but with no
 * seed row, that guess never resolves to a real register (and for a tenant that
 * DID create one manually, the first auto-increment id is 2, not 1, so the
 * guess is wrong even then).
 *
 * Criteria-based and idempotent: only inserts when the table has ZERO rows —
 * i.e. never seeded at all. A tenant with at least one register (however many,
 * whatever their status) is left completely untouched, so this never overrides
 * an admin's own register setup or resurrects a deliberately deactivated one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS default register seed...\n";

try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM pos_registers")->fetchColumn();
    if ($count > 0) {
        echo "  · pos_registers already has $count row(s) — leaving as-is.\n";
    } else {
        $pdo->prepare("
            INSERT INTO pos_registers (register_name, register_code, status)
            VALUES ('Main Counter', 'MAIN-01', 'active')
        ")->execute();
        echo "  + seeded default register 'Main Counter' (MAIN-01).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
