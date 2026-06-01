<?php
/**
 * BMS — DepreciationRun (Asset Register & PPE Schedule, Phase 4)
 *
 * Batch engine that turns each asset's stored depreciation areas into
 * period-by-period rows in depreciation_entries (the output that powers the
 * register NBV and the PPE schedule).
 *
 * For a target financial year it generates one annual entry per asset, per
 * area, for every period from the area's first financial year up to the
 * target — so the schedule's "opening" (sum of prior charges) is always
 * available. Periods are derived from asset_settings.financial_year_start.
 *
 * Guardrails (§4.3): skips non-depreciable areas and deleted assets; stops at
 * the disposal date (no charge in periods after disposal).
 *
 * Idempotency (§4.4): a period already posted (posted = 1) is never re-posted;
 * unposted/missing periods are written and marked posted. Safe to re-run.
 */

require_once __DIR__ . '/asset_settings.php';
require_once __DIR__ . '/asset_depreciation_service.php';

if (!function_exists('fyBoundsForYear')) {
    /**
     * Financial-year bounds for a given FY-start year, derived from the
     * configured financial_year_start month/day. End = start + 1 year - 1 day.
     * @return array{0:string,1:string} [periodStart, periodEnd] yyyy-mm-dd
     */
    function fyBoundsForYear(array $settings, int $fyYear): array
    {
        $fyStart = $settings['financial_year_start'] ?: ($fyYear . '-01-01');
        $m = (int)date('n', strtotime($fyStart));
        $d = (int)date('j', strtotime($fyStart));
        $start = sprintf('%04d-%02d-%02d', $fyYear, $m, $d);
        $end   = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
        return [$start, $end];
    }
}

if (!function_exists('firstFyYear')) {
    /** The FY-start year whose period contains the given date. */
    function firstFyYear(array $settings, string $date): int
    {
        $y = (int)date('Y', strtotime($date));
        [$start] = fyBoundsForYear($settings, $y);
        if (strtotime($date) < strtotime($start)) $y--; // before this FY's start
        return $y;
    }
}

if (!function_exists('runDepreciation')) {
    /**
     * Post depreciation for every depreciable asset up to the target FY.
     *
     * @param PDO  $pdo
     * @param int  $fyYear  the FY-start year to run through (e.g. 2026)
     * @param int  $userId
     * @param int|null $onlyAssetId  restrict to one asset (optional)
     * @return array summary: periods_written, periods_skipped_posted, assets, areas
     */
    function runDepreciation($pdo, int $fyYear, int $userId, ?int $onlyAssetId = null): array
    {
        $settings = getAssetSettings($pdo);
        $timing   = $settings['depreciation_timing'];

        $sql = "SELECT a.asset_id, a.cost, a.status, a.disposal_date,
                       d.area, d.method, d.useful_life, d.rate, d.salvage_value,
                       d.start_date, d.opening_accum_bf
                  FROM asset_depreciation_areas d
                  JOIN assets a ON a.asset_id = d.asset_id
                 WHERE a.status != 'deleted'
                   AND d.method <> 'none'
                   AND d.start_date IS NOT NULL";
        $params = [];
        if ($onlyAssetId) { $sql .= " AND a.asset_id = ?"; $params[] = $onlyAssetId; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $written = 0; $skippedPosted = 0; $assetIds = []; $areaCount = 0;

        $check  = $pdo->prepare("SELECT posted FROM depreciation_entries
                                  WHERE asset_id = ? AND area = ? AND period_end = ?");
        $upsert = $pdo->prepare("
            INSERT INTO depreciation_entries
                (asset_id, area, period_start, period_end, opening_value, charge, accumulated, closing_nbv, posted)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                period_start = VALUES(period_start), opening_value = VALUES(opening_value),
                charge = VALUES(charge), accumulated = VALUES(accumulated),
                closing_nbv = VALUES(closing_nbv), posted = 1
        ");

        foreach ($areas as $row) {
            $cost     = (float)$row['cost'];
            $start    = $row['start_date'];
            $disposal = $row['disposal_date'] ?: null;
            $areaCount++;
            $assetIds[$row['asset_id']] = true;

            $firstFy = firstFyYear($settings, $start);
            for ($y = $firstFy; $y <= $fyYear; $y++) {
                [$pStart, $pEnd] = fyBoundsForYear($settings, $y);

                // Stop once the asset was disposed before this period began.
                if ($disposal && strtotime($disposal) < strtotime($pStart)) break;

                // Years credited at the start/end of this financial period.
                //   full_year → acquisition FY counts as a full year (period index)
                //   pro_rata  → fraction of a year from the start date
                $idx = $y - $firstFy + 1;          // 1-based period number
                if ($timing === 'pro_rata') {
                    $asOfEnd  = ($disposal && strtotime($disposal) < strtotime($pEnd)) ? $disposal : $pEnd;
                    $yearsEnd   = depYearsElapsed($start, $asOfEnd, 'pro_rata');
                    $yearsStart = depYearsElapsed($start, date('Y-m-d', strtotime($pStart . ' -1 day')), 'pro_rata');
                } else {
                    // Disposal in-period stops further depreciation: hold at prior level.
                    $disposedThisPeriod = $disposal && strtotime($disposal) < strtotime($pEnd);
                    $yearsEnd   = $disposedThisPeriod ? (float)($idx - 1) : (float)$idx;
                    $yearsStart = (float)($idx - 1);
                }

                $accEnd   = applyDepreciation($row, $cost, $yearsEnd)['accumulated'];
                $accStart = applyDepreciation($row, $cost, $yearsStart)['accumulated'];

                $charge      = round(max(0.0, $accEnd - $accStart), 2);
                $openingVal  = round($cost - $accStart, 2);
                $closingNbv  = round($cost - $accEnd, 2);

                // Idempotency: never re-post an already-posted period.
                $check->execute([$row['asset_id'], $row['area'], $pEnd]);
                if ((int)$check->fetchColumn() === 1) { $skippedPosted++; continue; }

                $upsert->execute([
                    $row['asset_id'], $row['area'], $pStart, $pEnd,
                    $openingVal, $charge, $accEnd, $closingNbv,
                ]);
                $written++;
            }
        }

        return [
            'fy_year'                => $fyYear,
            'periods_written'        => $written,
            'periods_skipped_posted' => $skippedPosted,
            'assets'                 => count($assetIds),
            'areas'                  => $areaCount,
        ];
    }
}
