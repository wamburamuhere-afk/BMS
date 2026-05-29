<?php
/**
 * 2026_05_28_asset_depreciation_runs.php
 * ---------------------------------------
 * Foundational depreciation feature — Phase 1 (migration 3 of 3).
 *
 * Creates the asset_depreciation_runs table — the immutable audit trail of
 * every depreciation period posted against an asset.
 *
 * Each run is a (asset_id, period_end_date) pair. The UNIQUE KEY prevents
 * posting depreciation twice for the same asset in the same period — the
 * key idempotency guarantee for the "Run Depreciation" button.
 *
 * journal_entry_id is reserved for the future when we wire GL posting
 * (Phase 2 ships in "computed only" mode per the agreed plan, so this stays
 * NULL until that mode is enabled).
 *
 * Idempotent: re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_depreciation_runs...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asset_depreciation_runs (
            run_id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            asset_id            INT            NOT NULL,
            period_end_date     DATE           NOT NULL,
            period_label        VARCHAR(30)    NOT NULL,
            period_amount       DECIMAL(15,2)  NOT NULL,
            accumulated_after   DECIMAL(15,2)  NOT NULL,
            method_used         ENUM('straight_line','reducing_balance') NOT NULL,
            opening_nbv         DECIMAL(15,2)  NOT NULL,
            closing_nbv         DECIMAL(15,2)  NOT NULL,
            posted_by           INT UNSIGNED   NOT NULL,
            posted_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            journal_entry_id    INT UNSIGNED   NULL,
            notes               TEXT           NULL,
            PRIMARY KEY (run_id),
            UNIQUE KEY uq_asset_period (asset_id, period_end_date),
            KEY ix_asset (asset_id),
            KEY ix_period (period_end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + Table asset_depreciation_runs created (or already exists).\n";

    // ── Align asset_id type with assets.asset_id (some MySQL versions
    // create INT UNSIGNED above; assets.asset_id is INT signed, so the FK
    // would be rejected with "Referencing column ... incompatible".)
    $col = $pdo->query("SHOW COLUMNS FROM asset_depreciation_runs WHERE Field='asset_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'], 'unsigned') !== false) {
        $pdo->exec("ALTER TABLE asset_depreciation_runs MODIFY asset_id INT NOT NULL");
        echo "  · asset_id was INT UNSIGNED — aligned to INT to match assets.asset_id.\n";
    }

    // Optional FK to assets — wrapped because some MySQL configs are strict.
    try {
        $existsFk = $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'asset_depreciation_runs'
               AND CONSTRAINT_NAME = 'fk_dep_runs_asset_id'
        ")->fetchColumn();
        if (!$existsFk) {
            $pdo->exec("ALTER TABLE `asset_depreciation_runs`
                        ADD CONSTRAINT fk_dep_runs_asset_id
                        FOREIGN KEY (asset_id) REFERENCES assets(asset_id)
                        ON DELETE CASCADE");
            echo "  + added FK fk_dep_runs_asset_id (CASCADE on asset delete).\n";
        } else {
            echo "  · FK fk_dep_runs_asset_id already present.\n";
        }
    } catch (PDOException $e) {
        echo "  ! Could not add FK (non-fatal): " . $e->getMessage() . "\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
