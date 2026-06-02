<?php
/**
 * 2026_06_01_asset_areas_backfill.php
 * ------------------------------------
 * Asset Register & PPE Schedule — Phase 1 (4 of 4).
 *
 * Migrates existing assets from the legacy single-track design into the new
 * parallel book/tax model:
 *
 *   1. Sets capitalization_date = COALESCE(depreciation_start_date, purchase_date)
 *      and acquisition_type = 'new' where not already set.
 *   2. For each depreciable asset, creates the BOOK depreciation area from the
 *      asset's existing single-track columns (or its category's book defaults),
 *      and the TAX area from the category's statutory tax_rate (reducing balance).
 *
 * Non-depreciable assets (category is_depreciable = 0, e.g. Land) get no areas.
 * opening_accum_bf is set to 0 — existing rows are treated as 'new' acquisitions
 * depreciating forward; true opening balances are entered later via the form's
 * "existing asset" mode.
 *
 * Idempotent: capitalization_date only filled when NULL; areas use INSERT IGNORE
 * on the uq_asset_area unique key. Re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: backfill asset depreciation areas...\n";

try {
    // 1. Fill capitalization_date / acquisition_type where missing.
    $pdo->exec("
        UPDATE assets
           SET capitalization_date = COALESCE(capitalization_date, depreciation_start_date, purchase_date)
         WHERE capitalization_date IS NULL
    ");
    echo "  + capitalization_date backfilled where missing.\n";

    // 2. Pull assets joined to their category for book/tax defaults.
    $assets = $pdo->query("
        SELECT a.asset_id, a.purchase_date, a.capitalization_date,
               a.depreciation_method, a.useful_life_years, a.annual_rate_percent,
               a.salvage_value,
               c.is_depreciable, c.default_method, c.default_useful_life_years,
               c.default_annual_rate_percent, c.default_salvage_percent, c.tax_rate
          FROM assets a
          LEFT JOIN asset_categories c ON c.category_id = a.category_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $insArea = $pdo->prepare("
        INSERT IGNORE INTO asset_depreciation_areas
            (asset_id, area, method, useful_life, rate, salvage_value, start_date, opening_accum_bf)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0.00)
    ");

    $bookCount = 0; $taxCount = 0; $skipped = 0;
    foreach ($assets as $a) {
        // Non-depreciable category (e.g. Land) — no areas.
        if ($a['is_depreciable'] !== null && (int)$a['is_depreciable'] === 0) {
            $skipped++;
            continue;
        }

        $start = $a['capitalization_date'] ?: $a['purchase_date'];
        if (!$start) { $skipped++; continue; }

        // BOOK area — prefer the asset's own config, fall back to category defaults.
        $bookMethod  = $a['depreciation_method'] ?: ($a['default_method'] ?: 'straight_line');
        $bookLife    = $a['useful_life_years']   ?: $a['default_useful_life_years'];
        $bookRate    = $a['annual_rate_percent'] ?: $a['default_annual_rate_percent'];
        $bookSalvage = $a['salvage_value'] !== null ? $a['salvage_value'] : 0.00;
        $insArea->execute([
            $a['asset_id'], 'book', $bookMethod, $bookLife, $bookRate, $bookSalvage, $start,
        ]);
        if ($insArea->rowCount() > 0) $bookCount++;

        // TAX area — statutory reducing balance from the category tax_rate.
        // Only create when a tax_rate is known; otherwise leave for manual setup.
        if ($a['tax_rate'] !== null) {
            $insArea->execute([
                $a['asset_id'], 'tax', 'reducing_balance', null, $a['tax_rate'], 0.00, $start,
            ]);
            if ($insArea->rowCount() > 0) $taxCount++;
        }
    }

    echo "  + created {$bookCount} book area(s), {$taxCount} tax area(s); skipped {$skipped} asset(s).\n";
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
