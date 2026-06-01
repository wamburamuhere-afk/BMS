<?php
/**
 * 2026_06_01_asset_ppe_tables.php
 * --------------------------------
 * Asset Register & PPE Schedule — Phase 1 (3 of 4).
 *
 * Creates the five new tables of the parallel book/tax model:
 *
 *   asset_depreciation_areas → one row per asset per area (book + tax)
 *   depreciation_entries     → engine output: per asset, per area, per period
 *   asset_disposals          → disposal snapshots + gain/loss
 *   asset_maintenance        → service history + next-due reminders
 *   asset_audit_log          → immutable change log
 *
 * All reference assets(asset_id), which is INT (signed). asset_id columns are
 * declared INT (signed) to keep FK types compatible.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS; FKs added via guarded checks.
 * Re-run safe. No transactions (DDL auto-commits).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset PPE tables...\n";

/** Add a FK only if it isn't already present (DDL-safe, non-fatal on failure). */
function add_fk(PDO $pdo, string $table, string $name, string $definition): void
{
    try {
        $exists = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$table}'
               AND CONSTRAINT_NAME = '{$name}'
        ")->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT {$name} {$definition}");
            echo "  + added FK {$name} on {$table}.\n";
        } else {
            echo "  · FK {$name} already present on {$table}.\n";
        }
    } catch (PDOException $e) {
        echo "  ! Could not add FK {$name} (non-fatal): " . $e->getMessage() . "\n";
    }
}

try {
    // ── asset_depreciation_areas ───────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_depreciation_areas (
            id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            asset_id         INT           NOT NULL,
            area             ENUM('book','tax') NOT NULL,
            method           ENUM('straight_line','reducing_balance','none') NOT NULL DEFAULT 'straight_line',
            useful_life      INT           NULL COMMENT 'Years, for straight_line',
            rate             DECIMAL(5,2)  NULL COMMENT '%, for reducing_balance',
            salvage_value    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            start_date       DATE          NOT NULL COMMENT '= capitalization_date (or take_on_date for existing)',
            opening_accum_bf DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Brought-forward accumulated dep (existing assets)',
            created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_asset_area (asset_id, area),
            KEY ix_area_asset (asset_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table asset_depreciation_areas ready.\n";
    add_fk($pdo, 'asset_depreciation_areas', 'fk_dep_area_asset',
        'FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE');

    // ── depreciation_entries ───────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS depreciation_entries (
            id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            asset_id     INT           NOT NULL,
            area         ENUM('book','tax') NOT NULL,
            period_start DATE          NOT NULL,
            period_end   DATE          NOT NULL,
            opening_value DECIMAL(15,2) NOT NULL COMMENT 'NBV at start of period',
            charge       DECIMAL(15,2) NOT NULL COMMENT 'Depreciation for the period',
            accumulated  DECIMAL(15,2) NOT NULL COMMENT 'Cumulative dep to period end',
            closing_nbv  DECIMAL(15,2) NOT NULL COMMENT 'opening - charge',
            posted       TINYINT(1)    NOT NULL DEFAULT 0,
            created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_asset_area_period (asset_id, area, period_end),
            KEY ix_entry_asset (asset_id),
            KEY ix_entry_period (period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table depreciation_entries ready.\n";
    add_fk($pdo, 'depreciation_entries', 'fk_dep_entry_asset',
        'FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE');

    // ── asset_disposals ────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_disposals (
            id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            asset_id      INT           NOT NULL,
            disposal_date DATE          NOT NULL,
            method        ENUM('sold','scrapped','donated','written_off') NOT NULL,
            original_cost              DECIMAL(15,2) NOT NULL COMMENT 'Snapshot — removed from Cost section',
            accum_dep_book_at_disposal DECIMAL(15,2) NOT NULL COMMENT 'Snapshot — removed from Depreciation section',
            accum_dep_tax_at_disposal  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            proceeds      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            nbv_at_disposal DECIMAL(15,2) NOT NULL COMMENT 'cost - accum_dep_book',
            gain_loss     DECIMAL(15,2) NOT NULL COMMENT 'proceeds - nbv (P&L, NOT the PPE schedule)',
            notes         TEXT          NULL,
            created_by    INT           NULL,
            created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_disposal_asset (asset_id),
            KEY ix_disposal_date (disposal_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table asset_disposals ready.\n";
    add_fk($pdo, 'asset_disposals', 'fk_disposal_asset',
        'FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE');

    // ── asset_maintenance ──────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_maintenance (
            id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            asset_id         INT           NOT NULL,
            maintenance_date DATE          NOT NULL,
            description      TEXT          NULL,
            cost             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            performed_by     VARCHAR(100)  NULL,
            next_due_date    DATE          NULL COMMENT 'Drives reminders',
            created_by       INT           NULL,
            created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_maint_asset (asset_id),
            KEY ix_maint_next_due (next_due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table asset_maintenance ready.\n";
    add_fk($pdo, 'asset_maintenance', 'fk_maint_asset',
        'FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE');

    // ── asset_audit_log (immutable) ────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_audit_log (
            id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            asset_id      INT           NOT NULL,
            action        VARCHAR(30)   NOT NULL COMMENT 'create / update / dispose / depreciate',
            field_changed VARCHAR(60)   NULL,
            old_value     TEXT          NULL,
            new_value     TEXT          NULL,
            changed_by    INT           NULL,
            changed_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_audit_asset (asset_id),
            KEY ix_audit_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table asset_audit_log ready.\n";
    add_fk($pdo, 'asset_audit_log', 'fk_audit_asset',
        'FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE');

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
