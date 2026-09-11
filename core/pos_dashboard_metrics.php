<?php
/**
 * core/pos_dashboard_metrics.php
 * -------------------------------
 * Phase 29 (pos_upgrade_plan.md §9) — POS Dashboard Intelligence tiles.
 * Extracted so each aggregate is independently unit-testable and reconciles
 * to direct SQL, matching the extraction pattern of core/pos_shift_reporting.php.
 */

if (!function_exists('damageShrinkageSummary')) {
    /**
     * Damage/Shrinkage tile — a read-only aggregate over stock_movements for
     * the 'damaged','expired','theft' movement types, which the
     * adjustment/GRN flows already write but no dashboard anywhere surfaces.
     * Zero schema change — reads data that already exists.
     *
     * @param string $scopeSql  Pre-built scope clause (warehouse + project),
     *                          e.g. scopeFilterSqlNullable('warehouse','sm') . scopeFilterSqlNullable('project','sm')
     * @return array{damaged:float,expired:float,theft:float,total:float}
     */
    function damageShrinkageSummary(PDO $pdo, string $scopeSql, string $from, string $to): array
    {
        $out = ['damaged' => 0.0, 'expired' => 0.0, 'theft' => 0.0, 'total' => 0.0];
        $sql = "SELECT sm.movement_type, SUM(ABS(sm.quantity)) AS qty
                  FROM stock_movements sm
                 WHERE sm.movement_type IN ('damaged','expired','theft')
                   AND DATE(sm.movement_date) BETWEEN ? AND ? $scopeSql
              GROUP BY sm.movement_type";
        $st = $pdo->prepare($sql);
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = round((float)$row['qty'], 3);
            $out[$row['movement_type']] = $qty;
            $out['total'] += $qty;
        }
        $out['total'] = round($out['total'], 3);
        return $out;
    }
}

if (!function_exists('topCashiers')) {
    /**
     * Top Performing Cashiers — completed POS sales only (mirrors the
     * existing "Best Selling Products" tile's recognition/scope pattern).
     *
     * @param string $scopeSql Pre-built scope clause, e.g. scopeFilterSqlNullable('project','ps') . scopeFilterSqlNullable('warehouse','ps')
     * @return array<int, array{user_id:int,name:string,total:float,count:int}>
     */
    function topCashiers(PDO $pdo, string $scopeSql, string $from, string $to, int $limit = 5): array
    {
        $sql = "SELECT ps.user_id,
                       COALESCE(NULLIF(ps.cashier_name,''), CONCAT('#', ps.user_id)) AS name,
                       SUM(ps.grand_total - ps.tax_amount) AS total,
                       COUNT(*) AS cnt
                  FROM pos_sales ps
                 WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0
                   AND DATE(ps.sale_date) BETWEEN ? AND ? $scopeSql
              GROUP BY ps.user_id, name
              ORDER BY total DESC
                 LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute([$from, $to]);
        return array_map(fn($r) => [
            'user_id' => (int)$r['user_id'],
            'name'    => $r['name'],
            'total'   => round((float)$r['total'], 2),
            'count'   => (int)$r['cnt'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('salesTargetAchievementBand')) {
    /**
     * The four achievement bands, computed at render time from actual/target.
     * Boundaries are inclusive on the lower edge (>= not >), matching the
     * plan's own wording: Achieved >=100%, On Track >=75%, Needs Improvement
     * >=50%, Action Required <50%.
     */
    function salesTargetAchievementBand(float $pct): array
    {
        if ($pct >= 100) return ['key' => 'achieved',            'label' => t('Achieved')];
        if ($pct >= 75)  return ['key' => 'on_track',             'label' => t('On Track')];
        if ($pct >= 50)  return ['key' => 'needs_improvement',    'label' => t('Needs Improvement')];
        return                  ['key' => 'action_required',      'label' => t('Action Required')];
    }
}

if (!function_exists('salesTargetAchievement')) {
    /**
     * Resolves the applicable target row for the given scope + month and
     * compares it to actual net POS revenue for that same scope/window.
     *
     * Lookup order: an exact (warehouse_id, user_id=0) row for a caller
     * narrowed to one specific warehouse, else the company-wide
     * (warehouse_id=0, user_id=0) row. warehouse_id=0/user_id=0 are the
     * deliberate "all warehouses"/"all cashiers" sentinels (see the
     * migration's own note on why 0 and not NULL).
     *
     * @param int    $warehouseId  0 = company-wide; a specific warehouse_id narrows the lookup AND the actual-sales computation
     * @param string $periodMonth  'YYYY-MM-01'
     * @return array{has_target:bool,target_amount:float,actual:float,pct:float,band:string,band_label:string}|null null when no target row exists at all for this scope
     */
    function salesTargetAchievement(PDO $pdo, int $warehouseId, string $periodMonth): array
    {
        $target = null;
        if ($warehouseId > 0) {
            $st = $pdo->prepare("SELECT target_amount FROM pos_sales_targets WHERE warehouse_id = ? AND user_id = 0 AND period_month = ?");
            $st->execute([$warehouseId, $periodMonth]);
            $target = $st->fetchColumn();
        }
        if ($target === false || $target === null) {
            $st = $pdo->prepare("SELECT target_amount FROM pos_sales_targets WHERE warehouse_id = 0 AND user_id = 0 AND period_month = ?");
            $st->execute([$periodMonth]);
            $target = $st->fetchColumn();
        }

        $monthFrom = $periodMonth;
        $monthTo   = date('Y-m-t', strtotime($periodMonth));
        $recOrig = "ps.sale_status IN ('completed','partially_refunded','refunded') AND ps.is_return_sale = 0 AND ps.invoice_id IS NULL";
        $recRet  = "ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled') AND ps.invoice_id IS NULL";
        $params = [$monthFrom, $monthTo];
        $whSql = '';
        if ($warehouseId > 0) { $whSql = " AND ps.warehouse_id = ?"; $params[] = $warehouseId; }
        $sql = "SELECT COALESCE(SUM(CASE WHEN $recOrig THEN ps.grand_total - ps.tax_amount
                                          WHEN $recRet  THEN -(ps.grand_total - ps.tax_amount) ELSE 0 END), 0) AS net
                  FROM pos_sales ps WHERE DATE(ps.sale_date) BETWEEN ? AND ? $whSql";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $actual = round((float)$st->fetchColumn(), 2);

        if ($target === false || $target === null) {
            return ['has_target' => false, 'target_amount' => 0.0, 'actual' => $actual, 'pct' => 0.0, 'band' => '', 'band_label' => ''];
        }

        $targetAmount = round((float)$target, 2);
        $pct = $targetAmount > 0 ? round(($actual / $targetAmount) * 100, 1) : 0.0;
        $band = salesTargetAchievementBand($pct);

        return [
            'has_target'    => true,
            'target_amount' => $targetAmount,
            'actual'        => $actual,
            'pct'           => $pct,
            'band'          => $band['key'],
            'band_label'    => $band['label'],
        ];
    }
}
