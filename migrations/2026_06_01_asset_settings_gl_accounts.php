<?php
/**
 * 2026_06_01_asset_settings_gl_accounts.php
 * ------------------------------------------
 * Asset Register & PPE Schedule — Phase 9 (GL Integration).
 *
 * Adds two GL account-code columns to asset_settings used for the offset legs
 * that category accounts don't cover:
 *   gl_clearing_account   — cash/clearing leg for acquisition & disposal proceeds
 *   gl_gain_loss_account  — P&L account for disposal gain/(loss)
 *
 * Codes map to accounts.account_code. Nullable — GL posting is skipped when the
 * needed accounts aren't configured. Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_settings GL accounts...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'asset_settings'")->fetch()) {
        echo "  ! asset_settings table not found — run the Phase 0 migration first.\n";
        exit(1);
    }
    foreach (['gl_clearing_account', 'gl_gain_loss_account'] as $col) {
        if ($pdo->query("SHOW COLUMNS FROM asset_settings LIKE '{$col}'")->fetch()) {
            echo "  · column {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE asset_settings ADD COLUMN `{$col}` VARCHAR(20) NULL");
            echo "  + added column {$col}.\n";
        }
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
