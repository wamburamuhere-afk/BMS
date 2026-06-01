<?php
/**
 * 2026_06_01_assets_register_columns.php
 * ---------------------------------------
 * Asset Register & PPE Schedule — Phase 1 (2 of 4).
 *
 * Extends the existing assets table into the document's full register master
 * record. The existing table already has asset_id (PK), asset_name, asset_code,
 * category_id, location, purchase_date, cost, salvage_value, status,
 * description, created_by. We add the identification / acquisition / assignment
 * fields the register needs:
 *
 *   parent_asset_id     → sub-asset / component support (self-FK)
 *   serial_number
 *   custodian_id        → responsible user (nullable, app-validated)
 *   supplier_id         → audit trail (nullable, app-validated)
 *   location_id         → optional structured location (keeps existing `location` text)
 *   invoice_ref         → PO / GRN / invoice reference
 *   acquisition_type    → 'new' | 'existing' (drives opening-balance logic)
 *   capitalization_date → depreciation starts here (defaults to purchase_date)
 *   take_on_date        → go-live cut-off, existing assets only
 *   condition           → 'excellent'|'good'|'fair'|'poor'|'eol' (auto-suggested)
 *   photo_path
 *   qr_code
 *   updated_by
 *
 * Existing depreciation columns (useful_life_years, annual_rate_percent,
 * depreciation_method, salvage_value, accumulated_depreciation, disposal_*)
 * stay in place but become legacy single-track fields — the new source of
 * truth is asset_depreciation_areas + depreciation_entries.
 *
 * Idempotent: each ALTER guarded by SHOW COLUMNS. Re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: assets register columns...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'assets'")->fetch()) {
        echo "  ! assets table not found — skipping.\n";
        exit(0);
    }

    $cols = [
        ['parent_asset_id',     "INT NULL COMMENT 'Sub-asset / component parent'"],
        ['serial_number',       "VARCHAR(100) NULL"],
        ['custodian_id',        "INT NULL COMMENT 'Responsible user'"],
        ['supplier_id',         "INT NULL"],
        ['location_id',         "INT NULL COMMENT 'Optional structured location'"],
        ['invoice_ref',         "VARCHAR(50) NULL COMMENT 'PO / GRN / invoice reference'"],
        ['acquisition_type',    "ENUM('new','existing') NOT NULL DEFAULT 'new'"],
        ['capitalization_date', "DATE NULL COMMENT 'Depreciation start; defaults to purchase_date'"],
        ['take_on_date',        "DATE NULL COMMENT 'Go-live cut-off, existing assets only'"],
        ['condition',           "ENUM('excellent','good','fair','poor','eol') NULL"],
        ['photo_path',          "VARCHAR(255) NULL"],
        ['qr_code',             "VARCHAR(100) NULL"],
        ['updated_by',          "INT NULL"],
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

    // Self-referential FK for parent_asset_id (wrapped — some MySQL configs strict).
    try {
        $existsFk = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'assets'
               AND CONSTRAINT_NAME = 'fk_assets_parent'
        ")->fetchColumn();
        if (!$existsFk) {
            $pdo->exec("ALTER TABLE `assets`
                        ADD CONSTRAINT fk_assets_parent
                        FOREIGN KEY (parent_asset_id) REFERENCES assets(asset_id)
                        ON DELETE SET NULL");
            echo "  + added self-FK fk_assets_parent.\n";
        } else {
            echo "  · self-FK fk_assets_parent already present.\n";
        }
    } catch (PDOException $e) {
        echo "  ! Could not add parent FK (non-fatal): " . $e->getMessage() . "\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
