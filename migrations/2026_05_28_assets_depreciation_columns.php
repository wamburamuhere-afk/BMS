<?php
/**
 * 2026_05_28_assets_depreciation_columns.php
 * -------------------------------------------
 * Foundational depreciation feature — Phase 1 (migration 2 of 3).
 *
 * Extends the existing `assets` table with the columns required to compute
 * straight-line and reducing-balance depreciation, plus disposal tracking.
 *
 * Existing assets are unaffected — all new columns default to NULL / 0,
 * which means "depreciation not yet configured". Users fill them in via
 * the asset form (auto-populated from the chosen category). Until set,
 * the depreciation engine treats those assets as "no schedule" and skips
 * them in the run.
 *
 * Idempotent: each ALTER guarded by SHOW COLUMNS LIKE. Re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: extend assets with depreciation columns...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'assets'")->fetch()) {
        echo "  ! `assets` table not found — skipping.\n";
        exit(0);
    }

    $cols = [
        // [name, definition (without column name), placement-hint]
        ['category_id',              "INT UNSIGNED NULL"],
        ['useful_life_years',        "INT NULL COMMENT 'Used by straight_line method'"],
        ['annual_rate_percent',      "DECIMAL(5,2) NULL COMMENT 'Used by reducing_balance, e.g. 25.00'"],
        ['depreciation_method',      "ENUM('straight_line','reducing_balance') NULL"],
        ['salvage_value',            "DECIMAL(15,2) NOT NULL DEFAULT 0.00"],
        ['depreciation_start_date',  "DATE NULL"],
        ['accumulated_depreciation', "DECIMAL(15,2) NOT NULL DEFAULT 0.00"],
        ['last_depreciation_date',   "DATE NULL"],
        ['disposal_date',            "DATE NULL"],
        ['disposal_proceeds',        "DECIMAL(15,2) NULL"],
        ['disposal_gain_loss',       "DECIMAL(15,2) NULL"],
    ];

    foreach ($cols as [$col, $def]) {
        $exists = $pdo->query("SHOW COLUMNS FROM `assets` LIKE '{$col}'")->fetch();
        if ($exists) {
            echo "  · column {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE `assets` ADD COLUMN `{$col}` {$def}");
            echo "  + added column {$col}.\n";
        }
    }

    // FK to asset_categories (idempotent — wrapped in try because some MySQL
    // versions reject IF NOT EXISTS on FK and there's no SHOW FK helper).
    try {
        $existsFk = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'assets'
               AND CONSTRAINT_NAME = 'fk_assets_category_id'
        ")->fetchColumn();
        if (!$existsFk) {
            $pdo->exec("ALTER TABLE `assets`
                        ADD CONSTRAINT fk_assets_category_id
                        FOREIGN KEY (category_id) REFERENCES asset_categories(category_id)");
            echo "  + added FK fk_assets_category_id.\n";
        } else {
            echo "  · FK fk_assets_category_id already present.\n";
        }
    } catch (PDOException $e) {
        echo "  ! Could not add FK (non-fatal): " . $e->getMessage() . "\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
