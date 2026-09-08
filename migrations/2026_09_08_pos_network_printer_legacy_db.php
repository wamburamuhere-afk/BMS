<?php
/**
 * migrations/2026_09_08_pos_network_printer_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_08_pos_network_printer.php (Phase 21 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_price_groups_legacy_db.php for why this exists.
 *
 * Must run before 2026_09_08_pos_receipt_templates_legacy_db.php — that
 * migration's ADD COLUMN ... AFTER printer_port depends on the printer_port
 * column added here. Filename ordering (network_ < receipt_) preserves this.
 *
 * Extra caution here versus the tenant original: this file, unlike a tenant
 * migration, runs as part of migrations/runner.php — a failure here (exit 1)
 * is `script_stop: true` on the deploy script, which halts the release for
 * EVERY host, tenant databases included, not just this one. A tenant
 * database is guaranteed fresh from schema/tenant_schema_template.sql, so
 * the original file can safely assume `pos_registers`/`receipt_printer`
 * exist. A legacy database's exact history is less certain, so this version
 * degrades instead of failing: if `pos_registers` doesn't exist at all, that
 * is a separate, pre-existing gap outside this migration's job to fix, and
 * it logs that and exits 0 rather than blocking every other host's deploy
 * over it; if the AFTER-anchor column for a given ADD COLUMN is missing, it
 * appends the column without positioning rather than failing outright — the
 * column still lands, just possibly not in the exact same visual order.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS network printer support on the legacy database (Phase 21)...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'pos_registers'")->fetch();
    if (!$table) {
        echo "  · pos_registers does not exist on this database — nothing to add columns to. Skipping (not this migration's job to create it).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $cols = [
        'printer_connection_type' => ["ENUM('browser','network') NOT NULL DEFAULT 'browser'", 'receipt_printer'],
        'printer_ip_address'      => ["VARCHAR(45) DEFAULT NULL", 'printer_connection_type'],
        'printer_port'            => ["INT DEFAULT 9100", 'printer_ip_address'],
    ];
    foreach ($cols as $col => [$def, $afterCol]) {
        $exists = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE " . $pdo->quote($col))->fetch();
        if ($exists) {
            echo "  · pos_registers.$col already present.\n";
            continue;
        }
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE " . $pdo->quote($afterCol))->fetch();
        $position = $anchor ? " AFTER `$afterCol`" : "";
        $pdo->exec("ALTER TABLE pos_registers ADD COLUMN `$col` $def$position");
        echo "  + pos_registers.$col added" . ($position ? "" : " (anchor column '$afterCol' not found — appended at end instead)") . ".\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
