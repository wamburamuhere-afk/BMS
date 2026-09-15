<?php
/**
 * migrations/2026_09_15_pos_default_register_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_15_pos_default_register.php onto the
 * LEGACY / non-tenant database. See 2026_09_08_pos_network_printer_legacy_db.php
 * for why this pairing exists.
 *
 * Degrades instead of failing: this file, unlike a tenant migration, runs as
 * part of migrations/runner.php — a failure here (exit 1) is `script_stop:
 * true` on the deploy script, halting the release for EVERY host. If
 * `pos_registers` doesn't exist at all on this database, that's a separate,
 * pre-existing gap outside this migration's job to fix — log it and exit 0
 * rather than blocking every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS default register seed on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'pos_registers'")->fetch();
    if (!$table) {
        echo "  · pos_registers does not exist on this database — nothing to seed. Skipping (not this migration's job to create it).\n";
        echo "Migration complete.\n";
        exit(0);
    }

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
