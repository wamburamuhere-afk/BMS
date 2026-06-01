<?php
/**
 * BMS — Asset Settings reader (Asset Register & PPE Schedule, Phase 0)
 *
 * Single source of truth for the asset_settings config row. Later phases
 * (depreciation engine, PPE schedule, registration form) read the financial
 * year, global take-on date, and depreciation policy through getAssetSettings().
 *
 * The table holds exactly one row (id = 1), seeded by the
 * 2026_06_01_asset_settings migration. This helper is defensive: if the row
 * is missing (e.g. migration not yet run on a given environment) it returns
 * safe calendar-year defaults rather than throwing.
 */

if (!function_exists('getAssetSettings')) {
    /**
     * Fetch the asset settings config row as an associative array.
     * Cached per-request so repeated calls within one page don't re-query.
     *
     * @param  PDO|null $pdo  Optional; falls back to the global $pdo.
     * @return array{
     *     id:int,
     *     financial_year_start:string,
     *     financial_year_end:string,
     *     global_take_on_date:?string,
     *     depreciation_frequency:string,
     *     depreciation_timing:string
     * }
     */
    function getAssetSettings($pdo = null): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        if ($pdo === null) {
            global $pdo;
        }

        $year = (int)date('Y');
        $defaults = [
            'id'                     => 1,
            'financial_year_start'   => "{$year}-01-01",
            'financial_year_end'     => "{$year}-12-31",
            'global_take_on_date'    => null,
            'depreciation_frequency' => 'annual',
            'depreciation_timing'    => 'full_year',
        ];

        try {
            $row = $pdo->query("SELECT * FROM asset_settings WHERE id = 1")
                       ->fetch(PDO::FETCH_ASSOC);
            $cache = $row ? array_merge($defaults, $row) : $defaults;
        } catch (PDOException $e) {
            // Table not present yet — fall back to defaults without crashing.
            $cache = $defaults;
        }

        return $cache;
    }
}
