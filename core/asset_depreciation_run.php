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
require_once __DIR__ . '/asset_audit_service.php';
require_once __DIR__ . '/asset_gl_service.php';

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
    function runDepreciation($pdo, int $fyYear, int $userId, ?int $onlyAssetId = null, ?string $onlyCategory = null): array
    {
        $settings = getAssetSettings($pdo);
        $timing   = $settings['depreciation_timing'];

        $sql = "SELECT a.asset_id, a.asset_code, a.cost, a.status, a.disposal_date,
                       d.area, d.method, d.useful_life, d.rate, d.salvage_value,
                       d.start_date, d.opening_accum_bf,
                       c.gl_expense_account, c.gl_accum_account
                  FROM asset_depreciation_areas d
                  JOIN assets a ON a.asset_id = d.asset_id
                  LEFT JOIN asset_categories c ON c.category_id = a.category_id
                 WHERE a.status != 'deleted'
                   AND d.method <> 'none'
                   AND d.start_date IS NOT NULL";
        $params = [];
        if ($onlyAssetId)  { $sql .= " AND a.asset_id = ?"; $params[] = $onlyAssetId; }
        if ($onlyCategory) { $sql .= " AND a.category = ?"; $params[] = $onlyCategory; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $written = 0; $skippedPosted = 0; $assetIds = []; $areaCount = 0;
        $glPosted = 0;
        $writtenByAsset = [];

        $check  = $pdo->prepare("SELECT id, posted, journal_entry_id FROM depreciation_entries
                                  WHERE asset_id = ? AND area = ? AND period_end = ?");
        $setJe  = $pdo->prepare("UPDATE depreciation_entries SET journal_entry_id = ? WHERE id = ?");
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

                // Idempotency for the SCHEDULE: never re-write an already-posted
                // period. We still consider GL posting below for rows that were
                // written before GL wiring existed (journal_entry_id IS NULL).
                $check->execute([$row['asset_id'], $row['area'], $pEnd]);
                $existing = $check->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($existing && (int)$existing['posted'] === 1) {
                    $skippedPosted++;
                    $entryRowId = (int)$existing['id'];
                    $alreadyHasJe = !empty($existing['journal_entry_id']);
                } else {
                    $upsert->execute([
                        $row['asset_id'], $row['area'], $pStart, $pEnd,
                        $openingVal, $charge, $accEnd, $closingNbv,
                    ]);
                    $written++;
                    $writtenByAsset[$row['asset_id']] = ($writtenByAsset[$row['asset_id']] ?? 0) + 1;
                    // Re-read to get the row id + (null) journal link reliably,
                    // since ON DUPLICATE KEY UPDATE doesn't give a usable lastInsertId.
                    $check->execute([$row['asset_id'], $row['area'], $pEnd]);
                    $r2 = $check->fetch(PDO::FETCH_ASSOC) ?: null;
                    $entryRowId   = $r2 ? (int)$r2['id'] : 0;
                    $alreadyHasJe = $r2 ? !empty($r2['journal_entry_id']) : false;
                }

                // §9.1/§9.2 — post the book charge to the GL (Dr Depreciation
                // Expense / Cr Accumulated Depreciation) so the expense ties to the
                // schedule's "Charge for year". Idempotent on the journal_entry_id
                // anchor: posts once per period, and backfills periods written
                // before GL wiring. Best-effort — a missing account never breaks
                // the run; the period just stays unlinked for a later re-run.
                if ($row['area'] === 'book' && $charge > 0 && $entryRowId > 0 && !$alreadyHasJe) {
                    $jeId = postAssetDepreciationGl($pdo, (int)$row['asset_id'], (string)$row['asset_code'],
                        $row['gl_expense_account'] ?? null, $row['gl_accum_account'] ?? null,
                        $charge, $pEnd, $userId);
                    if ($jeId) {
                        $setJe->execute([$jeId, $entryRowId]);
                        $glPosted = ($glPosted ?? 0) + 1;
                    }
                }
            }
        }

        // §8.1 — audit each asset that had depreciation posted this run.
        foreach ($writtenByAsset as $aid => $n) {
            logAssetAudit($pdo, (int)$aid, 'depreciate', 'period(s)', null,
                "FY {$fyYear}: {$n} period(s) posted", $userId);
        }

        return [
            'fy_year'                => $fyYear,
            'periods_written'        => $written,
            'periods_skipped_posted' => $skippedPosted,
            'gl_entries_posted'      => $glPosted,
            'assets'                 => count($assetIds),
            'areas'                  => $areaCount,
        ];
    }
}

if (!function_exists('previewDepreciation')) {
    /**
     * Compute (but DO NOT post) the depreciation each asset would receive for the
     * target financial year — the "proposal" behind the Preview → Post safeguard.
     *
     * Read-only: writes nothing to depreciation_entries, the GL, or the audit log.
     * Uses the same per-period maths as runDepreciation() so the preview cannot
     * diverge from what posting actually produces. Previews the BOOK area (the
     * area that drives the PPE schedule and GL), one row per asset.
     *
     * @param array $scope ['type'=>'all'|'category'|'asset', 'value'=>string|int|null]
     * @return array{fy_year:int, period_start:string, period_end:string, rows:array, totals:array}
     */
    function previewDepreciation($pdo, int $fyYear, array $scope = ['type' => 'all', 'value' => null]): array
    {
        $settings = getAssetSettings($pdo);
        $timing   = $settings['depreciation_timing'];
        [$tStart, $tEnd] = fyBoundsForYear($settings, $fyYear);

        $sql = "SELECT a.asset_id, a.asset_code, a.asset_name, a.category, a.cost, a.disposal_date,
                       d.area, d.method, d.useful_life, d.rate, d.salvage_value,
                       d.start_date, d.opening_accum_bf
                  FROM asset_depreciation_areas d
                  JOIN assets a ON a.asset_id = d.asset_id
                 WHERE a.status != 'deleted'
                   AND d.area = 'book'
                   AND d.method <> 'none'
                   AND d.start_date IS NOT NULL";
        $params = [];
        if (($scope['type'] ?? 'all') === 'asset' && $scope['value']) {
            $sql .= " AND a.asset_id = ?"; $params[] = (int)$scope['value'];
        } elseif (($scope['type'] ?? 'all') === 'category' && $scope['value']) {
            $sql .= " AND a.category = ?"; $params[] = (string)$scope['value'];
        }
        $sql .= " ORDER BY a.category, a.asset_code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $postedChk = $pdo->prepare("SELECT posted FROM depreciation_entries
                                     WHERE asset_id = ? AND area = 'book' AND period_end = ?");

        $rows = [];
        $totals = ['cost' => 0.0, 'opening_accum' => 0.0, 'charge' => 0.0, 'closing_accum' => 0.0, 'nbv' => 0.0];

        foreach ($areas as $row) {
            $cost     = (float)$row['cost'];
            $start    = $row['start_date'];
            $disposal = $row['disposal_date'] ?: null;
            $firstFy  = firstFyYear($settings, $start);

            // Asset not yet in service by the target FY — no depreciation this year.
            if ($firstFy > $fyYear) continue;
            // Disposed before the target period began — nothing for this year.
            if ($disposal && strtotime($disposal) < strtotime($tStart)) continue;

            // Mirror runDepreciation()'s year-credit logic for the target FY.
            $idx = $fyYear - $firstFy + 1;
            if ($timing === 'pro_rata') {
                $asOfEnd    = ($disposal && strtotime($disposal) < strtotime($tEnd)) ? $disposal : $tEnd;
                $yearsEnd   = depYearsElapsed($start, $asOfEnd, 'pro_rata');
                $yearsStart = depYearsElapsed($start, date('Y-m-d', strtotime($tStart . ' -1 day')), 'pro_rata');
            } else {
                $disposedThisPeriod = $disposal && strtotime($disposal) < strtotime($tEnd);
                $yearsEnd   = $disposedThisPeriod ? (float)($idx - 1) : (float)$idx;
                $yearsStart = (float)($idx - 1);
            }

            $accEnd   = applyDepreciation($row, $cost, $yearsEnd)['accumulated'];
            $accStart = applyDepreciation($row, $cost, $yearsStart)['accumulated'];

            $charge       = round(max(0.0, $accEnd - $accStart), 2);
            $openingAccum = round($accStart, 2);
            $closingAccum = round($accEnd, 2);
            $nbv          = round($cost - $accEnd, 2);

            $postedChk->execute([$row['asset_id'], $tEnd]);
            $alreadyPosted = ((int)$postedChk->fetchColumn() === 1);

            $rows[] = [
                'asset_id'       => (int)$row['asset_id'],
                'asset_code'     => $row['asset_code'],
                'asset_name'     => $row['asset_name'],
                'category'       => $row['category'],
                'method'         => $row['method'],
                'cost'           => round($cost, 2),
                'opening_accum'  => $openingAccum,
                'charge'         => $charge,
                'closing_accum'  => $closingAccum,
                'nbv'            => $nbv,
                'already_posted' => $alreadyPosted,
            ];

            $totals['cost']          += round($cost, 2);
            $totals['opening_accum'] += $openingAccum;
            $totals['charge']        += $charge;
            $totals['closing_accum'] += $closingAccum;
            $totals['nbv']           += $nbv;
        }
        foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

        return [
            'fy_year'      => $fyYear,
            'period_start' => $tStart,
            'period_end'   => $tEnd,
            'rows'         => $rows,
            'totals'       => $totals,
        ];
    }
}
