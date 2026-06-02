<?php
/**
 * 2026_06_01_asset_settings.php
 * ------------------------------
 * Asset Register & PPE Schedule — Phase 0 (Foundation & Settings).
 *
 * Creates the single-row asset_settings config table that later phases read:
 *   - financial year start / end          → defines the reporting period
 *   - global_take_on_date                 → go-live cut-off for existing assets
 *   - depreciation_frequency (annual/monthly)
 *   - depreciation_timing  (full_year/pro_rata)  → mid-year acquisition rule
 *
 * Seeds exactly one row (id = 1) with sensible defaults so services always
 * have config to read. The settings screen edits this row in place.
 *
 * Idempotent: re-run safe. The seed uses INSERT IGNORE so an existing row is
 * preserved.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_settings...\n";

try {
    // ── Create table ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_settings (
            id                      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            financial_year_start    DATE           NOT NULL,
            financial_year_end      DATE           NOT NULL,
            global_take_on_date     DATE           NULL,
            depreciation_frequency  ENUM('annual','monthly') NOT NULL DEFAULT 'annual',
            depreciation_timing     ENUM('full_year','pro_rata') NOT NULL DEFAULT 'full_year',
            created_at              TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                   ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + Table asset_settings created (or already exists).\n";

    // ── Seed the single config row (id = 1) ────────────────────────────────
    // Default financial year = current calendar year. The user adjusts this
    // and the take-on date from the settings screen before loading assets.
    $year = (int)date('Y');
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO asset_settings
            (id, financial_year_start, financial_year_end,
             global_take_on_date, depreciation_frequency, depreciation_timing)
        VALUES (1, ?, ?, NULL, 'annual', 'full_year')
    ");
    $stmt->execute(["{$year}-01-01", "{$year}-12-31"]);

    if ($stmt->rowCount() > 0) {
        echo "  + Seeded default settings row (FY {$year}-01-01 → {$year}-12-31).\n";
    } else {
        echo "  · Settings row already present — left untouched.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
