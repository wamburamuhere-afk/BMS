<?php
/**
 * migrations/tenant/2026_10_01_mm_simple_mode_default.php
 *
 * Sets mm_simple_mode = '1' (Simple Mode ON) for any tenant that has not yet
 * had the setting written. This is a safe, idempotent upsert — tenants that
 * have already been explicitly set to '0' (advanced GL mode) are not changed.
 *
 * Simple Mode ON means:
 *   - MM transactions are saved directly as status='posted' with journal_entry_id=NULL
 *   - No GL accounts need to be configured on mm_networks
 *   - All operational dashboards, shift summaries and reports work normally
 *
 * To enable Advanced GL mode for a tenant: use the superadmin Tenant Detail
 * page → Mobile Money → More → uncheck "Simple Mode".
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../roots.php';
global $pdo;

echo "Starting migration: MM Simple Mode default...\n";

try {
    $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_at)
         VALUES ('mm_simple_mode', '1', NOW())
         ON DUPLICATE KEY UPDATE updated_at = updated_at"
    )->execute();

    $action = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
    if ($action > 0) {
        echo "  [set] mm_simple_mode = '1' (was not previously set)\n";
    } else {
        echo "  [skip] mm_simple_mode already set — not changed\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
