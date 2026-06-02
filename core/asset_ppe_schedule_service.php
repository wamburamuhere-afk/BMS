<?php
/**
 * BMS — PpeScheduleService (Asset Register & PPE Schedule, Phase 7)
 *
 * Builds the grouped Property, Plant & Equipment movement schedule for a period
 * and a depreciation area (book or tax), implementing the document §5 mapping.
 * Every figure traces back to register data (assets, asset_disposals,
 * depreciation_entries) — the schedule is generated, never hand-keyed.
 *
 *   COST          Opening   = Σ cost of assets held at period start
 *                 Additions = Σ cost capitalised within the period
 *                 Disposals = Σ original_cost disposed within the period
 *                 Closing   = Opening + Additions − Disposals
 *   DEPRECIATION  Opening   = Σ posted charge before period start (held assets)
 *                 Charge    = Σ posted charge within the period
 *                 Disposal  = Σ accumulated dep at disposal within the period
 *                 Closing   = Opening + Charge − Disposal
 *   NET BOOK VALUE         = Cost Closing − Depreciation Closing
 *
 * Grouped by category with a TOTAL. Non-depreciable categories (e.g. Land) have
 * no depreciation_entries, so depreciation stays 0 and NBV = cost.
 */

if (!function_exists('buildPpeSchedule')) {
    /**
     * @param PDO    $pdo
     * @param string $periodStart yyyy-mm-dd
     * @param string $periodEnd   yyyy-mm-dd
     * @param string $area        'book' | 'tax'
     * @return array{period_start:string,period_end:string,area:string,rows:array,totals:array}
     */
    function buildPpeSchedule($pdo, string $periodStart, string $periodEnd, string $area = 'book'): array
    {
        $area    = $area === 'tax' ? 'tax' : 'book';
        $dispCol = $area === 'tax' ? 'accum_dep_tax_at_disposal' : 'accum_dep_book_at_disposal';

        // Seed the category map with every category that has non-deleted assets,
        // preserving is_depreciable for display.
        $rows = [];
        $catStmt = $pdo->query("
            SELECT a.category AS cat,
                   MAX(COALESCE(c.is_depreciable,1)) AS is_depreciable,
                   MIN(COALESCE(c.sort_order, 99999)) AS sort_order
              FROM assets a
              LEFT JOIN asset_categories c ON c.category_id = a.category_id
             WHERE a.status != 'deleted' AND a.category IS NOT NULL AND a.category <> ''
          GROUP BY a.category
        ");
        foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['cat']] = [
                'category'       => $r['cat'],
                'is_depreciable' => (int)$r['is_depreciable'],
                'sort_order'     => (int)$r['sort_order'],
                'cost_opening' => 0.0, 'cost_additions' => 0.0, 'cost_disposals' => 0.0, 'cost_closing' => 0.0,
                'dep_opening' => 0.0, 'dep_charge' => 0.0, 'dep_disposal' => 0.0, 'dep_closing' => 0.0,
                'nbv' => 0.0,
            ];
        }
        $ensure = function ($cat) use (&$rows) {
            if (!isset($rows[$cat])) {
                $rows[$cat] = ['category'=>$cat,'is_depreciable'=>1,'sort_order'=>99999,
                    'cost_opening'=>0.0,'cost_additions'=>0.0,'cost_disposals'=>0.0,'cost_closing'=>0.0,
                    'dep_opening'=>0.0,'dep_charge'=>0.0,'dep_disposal'=>0.0,'dep_closing'=>0.0,'nbv'=>0.0];
            }
            return $cat;
        };

        // ── COST opening / additions ────────────────────────────────────────
        // Opening  = held at period start. An EXISTING (brought-forward) asset is
        //            always an opening balance — never a current-year addition —
        //            keyed off its take_on_date (go-live), not capitalisation. A
        //            NEW asset is opening only if it was capitalised before the
        //            period start.
        // Additions = genuine NEW acquisitions capitalised within the period.
        // (#2) The old logic used capitalization_date alone, so an existing asset
        // taken on at 01.01 wrongly showed under Additions.
        $q = $pdo->prepare("
            SELECT a.category AS cat,
                   SUM(CASE WHEN (
                            (a.acquisition_type =  'existing' AND COALESCE(a.take_on_date, a.capitalization_date) <= :e1)
                         OR (a.acquisition_type <> 'existing' AND a.capitalization_date < :s1)
                       ) AND (d.disposal_date IS NULL OR d.disposal_date >= :s2)
                        THEN a.cost ELSE 0 END) AS cost_opening,
                   SUM(CASE WHEN a.acquisition_type <> 'existing'
                             AND a.capitalization_date BETWEEN :s3 AND :e2
                            THEN a.cost ELSE 0 END) AS cost_additions
              FROM assets a
              LEFT JOIN asset_disposals d ON d.asset_id = a.asset_id
             WHERE a.status != 'deleted' AND a.category IS NOT NULL AND a.category <> ''
          GROUP BY a.category
        ");
        $q->execute([':e1'=>$periodEnd, ':s1'=>$periodStart, ':s2'=>$periodStart, ':s3'=>$periodStart, ':e2'=>$periodEnd]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = $ensure($r['cat']);
            $rows[$c]['cost_opening']   = (float)$r['cost_opening'];
            $rows[$c]['cost_additions'] = (float)$r['cost_additions'];
        }

        // ── COST disposals + DEP less-on-disposal (within period) ───────────
        $q = $pdo->prepare("
            SELECT a.category AS cat,
                   SUM(d.original_cost) AS cost_disposals,
                   SUM(d.$dispCol)      AS dep_disposal
              FROM asset_disposals d
              JOIN assets a ON a.asset_id = d.asset_id
             WHERE d.disposal_date BETWEEN ? AND ?
          GROUP BY a.category
        ");
        $q->execute([$periodStart, $periodEnd]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = $ensure($r['cat']);
            $rows[$c]['cost_disposals'] = (float)$r['cost_disposals'];
            $rows[$c]['dep_disposal']   = (float)$r['dep_disposal'];
        }

        // ── DEP opening / charge from posted entries ────────────────────────
        $q = $pdo->prepare("
            SELECT a.category AS cat,
                   SUM(CASE WHEN de.period_end < :s
                             AND (d.disposal_date IS NULL OR d.disposal_date >= :s2)
                            THEN de.charge ELSE 0 END) AS dep_opening,
                   SUM(CASE WHEN de.period_end BETWEEN :s3 AND :e
                            THEN de.charge ELSE 0 END) AS dep_charge
              FROM depreciation_entries de
              JOIN assets a ON a.asset_id = de.asset_id
              LEFT JOIN asset_disposals d ON d.asset_id = a.asset_id
             WHERE de.area = :area
          GROUP BY a.category
        ");
        $q->execute([':s'=>$periodStart, ':s2'=>$periodStart, ':s3'=>$periodStart, ':e'=>$periodEnd, ':area'=>$area]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = $ensure($r['cat']);
            $rows[$c]['dep_opening'] = (float)$r['dep_opening'];
            $rows[$c]['dep_charge']  = (float)$r['dep_charge'];
        }

        // ── DEP opening: add brought-forward accumulated depreciation ───────
        // (#5) opening_accum_bf is the accumulated depreciation carried in for an
        // existing asset; it is NOT a posted 'charge', so the charge-sum above
        // misses it. accumulated_at_start = opening_accum_bf + Σ(charges before
        // start), so add the b/f of every asset held at period start. Without
        // this, opening depreciation is understated and NBV overstated.
        $qbf = $pdo->prepare("
            SELECT a.category AS cat, SUM(d.opening_accum_bf) AS bf
              FROM asset_depreciation_areas d
              JOIN assets a ON a.asset_id = d.asset_id
              LEFT JOIN asset_disposals ad ON ad.asset_id = a.asset_id
             WHERE d.area = :area
               AND a.status != 'deleted' AND a.category IS NOT NULL AND a.category <> ''
               AND (
                    (a.acquisition_type =  'existing' AND COALESCE(a.take_on_date, a.capitalization_date) <= :e1)
                 OR (a.acquisition_type <> 'existing' AND a.capitalization_date < :s1)
               )
               AND (ad.disposal_date IS NULL OR ad.disposal_date >= :s2)
          GROUP BY a.category
        ");
        $qbf->execute([':area'=>$area, ':e1'=>$periodEnd, ':s1'=>$periodStart, ':s2'=>$periodStart]);
        foreach ($qbf->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = $ensure($r['cat']);
            $rows[$c]['dep_opening'] += (float)$r['bf'];
        }

        // ── Derive closing + NBV; build totals ──────────────────────────────
        $totals = ['cost_opening'=>0.0,'cost_additions'=>0.0,'cost_disposals'=>0.0,'cost_closing'=>0.0,
                   'dep_opening'=>0.0,'dep_charge'=>0.0,'dep_disposal'=>0.0,'dep_closing'=>0.0,'nbv'=>0.0];
        foreach ($rows as &$row) {
            $row['cost_closing'] = round($row['cost_opening'] + $row['cost_additions'] - $row['cost_disposals'], 2);
            $row['dep_closing']  = round($row['dep_opening'] + $row['dep_charge'] - $row['dep_disposal'], 2);
            $row['nbv']          = round($row['cost_closing'] - $row['dep_closing'], 2);
            foreach (['cost_opening','cost_additions','cost_disposals','cost_closing','dep_opening','dep_charge','dep_disposal','dep_closing','nbv'] as $k) {
                $row[$k] = round($row[$k], 2);
                $totals[$k] += $row[$k];
            }
        }
        unset($row);
        foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

        // Order by the category's configured sort_order (statutory layout),
        // then by name as a tie-breaker.
        $ordered = array_values($rows);
        usort($ordered, fn($a, $b) => [$a['sort_order'], $a['category']] <=> [$b['sort_order'], $b['category']]);

        return [
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'area'         => $area,
            'rows'         => $ordered,
            'totals'       => $totals,
        ];
    }
}
