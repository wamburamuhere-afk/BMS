<?php
/**
 * BMS — DepreciationService (Asset Register & PPE Schedule, Phase 4)
 *
 * Pure calculation of accumulated depreciation / net book value for a single
 * depreciation area, implementing the document §4 formulas:
 *
 *   §4.1 Straight line:   annual = (cost - salvage) / useful_life
 *   §4.2 Reducing balance: nbv = nbv * (1 - rate%) each elapsed year
 *   §4.3 Existing-asset continuation: start from (cost - opening_accum_bf) at
 *        the take-on date and depreciate FORWARD — never recalculated from
 *        purchase. The brought-forward accumulated depreciation is preserved.
 *
 * Guardrails (§4.3): straight line never falls below salvage; reducing balance
 * never below zero; accumulated never drops below the brought-forward figure.
 *
 * The same function powers the register's live NBV, the run's period charges,
 * and mirrors the client-side preview in the asset form.
 */

if (!function_exists('depYearsElapsed')) {
    /**
     * Years elapsed between a start date and an as-of date.
     *   full_year → whole years (anniversary based, matches §4 literal)
     *   pro_rata  → fractional years (days / 365.25)
     * Returns 0 when as-of is on/before the start date.
     */
    function depYearsElapsed(string $start, string $asOf, string $timing = 'full_year'): float
    {
        $s = strtotime($start);
        $e = strtotime($asOf);
        if ($s === false || $e === false || $e <= $s) return 0.0;

        if ($timing === 'pro_rata') {
            return ($e - $s) / (365.25 * 86400);
        }

        // Whole years (full_year): count completed anniversaries.
        $years = (int)date('Y', $e) - (int)date('Y', $s);
        $anniv = mktime(0, 0, 0, (int)date('n', $s), (int)date('j', $s), (int)date('Y', $s) + $years);
        if ($anniv > $e) $years--;
        return (float)max(0, $years);
    }
}

if (!function_exists('applyDepreciation')) {
    /**
     * Pure §4 formula: accumulated + NBV for an area after a given number of
     * depreciation years (which may be fractional for pro-rata). Year-counting
     * is the caller's concern — the engine passes financial-year counts, the
     * date-based wrapper passes anniversary/pro-rata years.
     *
     * @return array{accumulated:float, nbv:float}
     */
    function applyDepreciation(array $area, float $cost, float $years): array
    {
        $method  = $area['method'] ?? 'straight_line';
        $salvage = (float)($area['salvage_value'] ?? 0);
        $bf      = (float)($area['opening_accum_bf'] ?? 0);
        $openNbv = $cost - $bf;                     // value carried in at start

        if ($method === 'none') {
            return ['accumulated' => round($bf, 2), 'nbv' => round($openNbv, 2)];
        }

        if ($years < 0) $years = 0.0;

        if ($method === 'reducing_balance') {
            $rate = (float)($area['rate'] ?? 0) / 100.0;
            $nbv  = ($rate > 0 && $years > 0) ? $openNbv * pow(1 - $rate, $years) : $openNbv;
            $nbv  = max(0.0, $nbv);                 // never below zero (§4.3)
        } else {
            $life   = (int)($area['useful_life'] ?? 0);
            $annual = $life > 0 ? ($cost - $salvage) / $life : 0.0;
            $nbv    = max($salvage, $openNbv - ($annual * $years)); // never below salvage (§4.3)
        }

        $accumulated = $cost - $nbv;
        if ($accumulated < $bf) $accumulated = $bf; // never below brought-forward
        return ['accumulated' => round($accumulated, 2), 'nbv' => round($cost - $accumulated, 2)];
    }
}

if (!function_exists('calcAreaDepreciation')) {
    /**
     * Date-based accumulated + NBV for an area as at $asOf (used by the form
     * preview and as a register fallback). Counts years from the start date by
     * anniversary (full_year) or fraction of a year (pro_rata).
     *
     * The authoritative period figures come from the DepreciationRun, which
     * counts whole financial-year periods (acquisition FY = a full year under
     * full_year timing). This wrapper is the lightweight "as of a date" view.
     *
     * @return array{accumulated:float, nbv:float, years:float}
     */
    function calcAreaDepreciation(array $area, float $cost, string $asOf, string $timing = 'full_year'): array
    {
        $start = $area['start_date'] ?? null;
        if (($area['method'] ?? 'straight_line') === 'none' || !$start) {
            $bf = (float)($area['opening_accum_bf'] ?? 0);
            return ['accumulated' => round($bf, 2), 'nbv' => round($cost - $bf, 2), 'years' => 0.0];
        }
        $years = depYearsElapsed($start, $asOf, $timing);
        $r = applyDepreciation($area, $cost, $years);
        $r['years'] = $years;
        return $r;
    }
}

if (!function_exists('calculateAssetAreaDepreciation')) {
    /**
     * Convenience wrapper: load an asset's cost + one area and compute as at
     * $asOf. Returns null if the area doesn't exist.
     */
    function calculateAssetAreaDepreciation($pdo, int $assetId, string $area, string $asOf, ?string $timing = null): ?array
    {
        $stmt = $pdo->prepare("SELECT a.cost, d.method, d.useful_life, d.rate, d.salvage_value,
                                      d.start_date, d.opening_accum_bf
                                 FROM asset_depreciation_areas d
                                 JOIN assets a ON a.asset_id = d.asset_id
                                WHERE d.asset_id = ? AND d.area = ?");
        $stmt->execute([$assetId, $area]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        if ($timing === null) {
            require_once __DIR__ . '/asset_settings.php';
            $timing = getAssetSettings($pdo)['depreciation_timing'];
        }
        return calcAreaDepreciation($row, (float)$row['cost'], $asOf, $timing);
    }
}
