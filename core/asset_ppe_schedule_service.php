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
                   MAX(COALESCE(c.is_depreciable,1)) AS is_depreciable
              FROM assets a
              LEFT JOIN asset_categories c ON c.category_id = a.category_id
             WHERE a.status != 'deleted' AND a.category IS NOT NULL AND a.category <> ''
          GROUP BY a.category
        ");
        foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['cat']] = [
                'category'       => $r['cat'],
                'is_depreciable' => (int)$r['is_depreciable'],
                'cost_opening' => 0.0, 'cost_additions' => 0.0, 'cost_disposals' => 0.0, 'cost_closing' => 0.0,
                'dep_opening' => 0.0, 'dep_charge' => 0.0, 'dep_disposal' => 0.0, 'dep_closing' => 0.0,
                'nbv' => 0.0,
            ];
        }
        $ensure = function ($cat) use (&$rows) {
            if (!isset($rows[$cat])) {
                $rows[$cat] = ['category'=>$cat,'is_depreciable'=>1,
                    'cost_opening'=>0.0,'cost_additions'=>0.0,'cost_disposals'=>0.0,'cost_closing'=>0.0,
                    'dep_opening'=>0.0,'dep_charge'=>0.0,'dep_disposal'=>0.0,'dep_closing'=>0.0,'nbv'=>0.0];
            }
            return $cat;
        };

        // ── COST opening / additions ────────────────────────────────────────
        // Opening = held at period start (capitalised before start, not disposed before start).
        $q = $pdo->prepare("
            SELECT a.category AS cat,
                   SUM(CASE WHEN a.capitalization_date < :s
                             AND (d.disposal_date IS NULL OR d.disposal_date >= :s2)
                            THEN a.cost ELSE 0 END) AS cost_opening,
                   SUM(CASE WHEN a.capitalization_date BETWEEN :s3 AND :e
                            THEN a.cost ELSE 0 END) AS cost_additions
              FROM assets a
              LEFT JOIN asset_disposals d ON d.asset_id = a.asset_id
             WHERE a.status != 'deleted' AND a.category IS NOT NULL AND a.category <> ''
          GROUP BY a.category
        ");
        $q->execute([':s'=>$periodStart, ':s2'=>$periodStart, ':s3'=>$periodStart, ':e'=>$periodEnd]);
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

        // Stable ordering by category name.
        ksort($rows);

        return [
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'area'         => $area,
            'rows'         => array_values($rows),
            'totals'       => $totals,
        ];
    }
}
