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

if (!function_exists('posSimpleBuySellSeries')) {
    /**
     * "Simple Mode" dashboard chart — Bought (cost) vs Sold (price) per
     * period, straight off pos_sales/pos_sale_items + products.cost_price.
     * Deliberately NOT the ledger — see core/pos_nav.php::posSimpleModeEnabled()
     * and .claude/reporting-source.md. This is the one dashboard view that
     * intentionally reads operational tables instead of journal_entries,
     * because it exists specifically for an owner who is never shown GL
     * language (a shopkeeper with no accountant). "Sold" mirrors the same
     * net-revenue recognition as api/pos/get_dashboard.php (grand_total -
     * tax_amount, originals minus returns, invoice-linked POS sales
     * excluded). "Bought" mirrors core/sales_posting.php::posSaleCogs()'s
     * per-line cost formula (actual batch cost where FEFO consumption
     * exists, else average products.cost_price; services and corrupt
     * cost>selling_price rows excluded), aggregated across all sales in one
     * query instead of N+1 calls, so the two numbers are the true buy/sell
     * pair for what was actually sold, not a rough estimate.
     *
     * 2026-09-15: also folds in real operating Expenses (the `expenses` table,
     * status 'approved' or 'paid' — the same accrual-recognition moment
     * core/expense_posting.php's postExpenseAccrual() uses, so this matches
     * the real ledger's P&L timing rather than only counting once cash moves)
     * so `net_profit` here is Sold − Bought − Expenses, a shop owner's actual
     * bottom line — not just gross margin. Requires `expenses.warehouse_id`
     * (2026-09-15 migration); a company-wide expense (NULL) is scoped exactly
     * like a company-wide sale already is via $expenseScopeSql.
     *
     * @param string $period           'daily'|'weekly'|'monthly'|'quarterly'|'yearly'
     * @param string $scopeSql         Pre-built scope clause on alias 'ps', e.g.
     *                                 scopeFilterSqlNullable('project','ps') . scopeFilterSqlNullable('warehouse','ps')
     * @param string $expenseScopeSql  Same idea, pre-built on alias 'e' for the
     *                                 `expenses` table, e.g. scopeFilterSqlNullable('project','e') . scopeFilterSqlNullable('warehouse','e').
     *                                 Empty string = no expenses series (caller opts in).
     * @return array<int, array{period:string, sold:float, bought:float, expenses:float, profit:float, net_profit:float}>
     */
    function posSimpleBuySellSeries(PDO $pdo, string $from, string $to, string $period, string $scopeSql, string $expenseScopeSql = ''): array
    {
        switch ($period) {
            case 'daily':     $periodSql = "DATE_FORMAT(ps.sale_date, '%Y-%m-%d')"; break;
            case 'weekly':    $periodSql = "DATE_FORMAT(ps.sale_date, '%x-%v')"; break;
            case 'quarterly': $periodSql = "CONCAT(YEAR(ps.sale_date), '-Q', QUARTER(ps.sale_date))"; break;
            case 'yearly':    $periodSql = "DATE_FORMAT(ps.sale_date, '%Y')"; break;
            default:          $periodSql = "DATE_FORMAT(ps.sale_date, '%Y-%m')"; break;
        }

        // Recognition predicates — identical to api/pos/get_dashboard.php's
        // $recOrig/$recRet so the "sold" totals here always reconcile to
        // that tile's net-revenue figure for the same window.
        $recOrig = "ps.sale_status IN ('completed','partially_refunded','refunded') AND ps.is_return_sale = 0 AND ps.invoice_id IS NULL";
        $recRet  = "ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled') AND ps.invoice_id IS NULL";

        $sql = "
            SELECT
                $periodSql AS period_key,
                SUM(CASE WHEN $recOrig THEN (ps.grand_total - ps.tax_amount)
                         WHEN $recRet  THEN -(ps.grand_total - ps.tax_amount)
                         ELSE 0 END) AS sold,
                SUM(CASE WHEN $recOrig THEN COALESCE(cogs.amount, 0)
                         WHEN $recRet  THEN -COALESCE(cogs.amount, 0)
                         ELSE 0 END) AS bought
              FROM pos_sales ps
              LEFT JOIN (
                    SELECT si.sale_id,
                           SUM(CASE WHEN bc.batch_qty > 0 THEN bc.batch_cost_total
                                    ELSE si.quantity * COALESCE(p.cost_price, 0) END) AS amount
                      FROM pos_sale_items si
                      JOIN products p ON si.product_id = p.product_id
                      LEFT JOIN (
                            SELECT psib.sale_item_id,
                                   SUM(psib.quantity) AS batch_qty,
                                   SUM(psib.quantity * pb.unit_cost) AS batch_cost_total
                              FROM pos_sale_item_batches psib
                              JOIN product_batches pb ON pb.batch_id = psib.batch_id
                          GROUP BY psib.sale_item_id
                      ) bc ON bc.sale_item_id = si.sale_item_id
                     WHERE p.is_service = 0
                       AND (bc.batch_qty > 0 OR NOT (p.cost_price > p.selling_price AND p.selling_price > 0))
                  GROUP BY si.sale_id
              ) cogs ON cogs.sale_id = ps.sale_id
             WHERE DATE(ps.sale_date) BETWEEN ? AND ? $scopeSql
          GROUP BY period_key
          ORDER BY period_key
        ";

        $st = $pdo->prepare($sql);
        $st->execute([$from, $to]);

        $byPeriod = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['period_key'] === null || $row['period_key'] === '') continue;
            $byPeriod[(string)$row['period_key']] = [
                'sold'   => round((float)$row['sold'], 2),
                'bought' => round((float)$row['bought'], 2),
            ];
        }

        // Expenses series — same period bucketing, own scope clause (different
        // table/alias, so it can't share $scopeSql's pre-built 'ps.' clause).
        if ($expenseScopeSql !== '') {
            switch ($period) {
                case 'daily':     $expPeriodSql = "DATE_FORMAT(e.expense_date, '%Y-%m-%d')"; break;
                case 'weekly':    $expPeriodSql = "DATE_FORMAT(e.expense_date, '%x-%v')"; break;
                case 'quarterly': $expPeriodSql = "CONCAT(YEAR(e.expense_date), '-Q', QUARTER(e.expense_date))"; break;
                case 'yearly':    $expPeriodSql = "DATE_FORMAT(e.expense_date, '%Y')"; break;
                default:          $expPeriodSql = "DATE_FORMAT(e.expense_date, '%Y-%m')"; break;
            }
            $expSql = "
                SELECT $expPeriodSql AS period_key, SUM(e.amount) AS spent
                  FROM expenses e
                 WHERE e.expense_date BETWEEN ? AND ?
                   AND e.status IN ('approved','paid')
                   $expenseScopeSql
              GROUP BY period_key
            ";
            $est = $pdo->prepare($expSql);
            $est->execute([$from, $to]);
            foreach ($est->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['period_key'] === null || $row['period_key'] === '') continue;
                $key = (string)$row['period_key'];
                if (!isset($byPeriod[$key])) $byPeriod[$key] = ['sold' => 0.0, 'bought' => 0.0];
                $byPeriod[$key]['spent'] = round((float)$row['spent'], 2);
            }
        }

        ksort($byPeriod);

        $out = [];
        foreach ($byPeriod as $key => $vals) {
            $sold     = $vals['sold'];
            $bought   = $vals['bought'];
            $expenses = $vals['spent'] ?? 0.0;
            $out[] = [
                'period'     => $key,
                'sold'       => $sold,
                'bought'     => $bought,
                'expenses'   => $expenses,
                'profit'     => round($sold - $bought, 2),
                'net_profit' => round($sold - $bought - $expenses, 2),
            ];
        }
        return $out;
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
