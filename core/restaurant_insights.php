<?php
/**
 * core/restaurant_insights.php — numbers for the restaurant dashboard panel
 * (restaurant dashboard pass, 2026-11-02).
 *
 * Source rules (.claude/reporting-source.md):
 *   • MONEY (sales, food cost, expenses, profit, trend) comes ONLY from the one ledger:
 *     journal_entries (posted) ⨝ journal_entry_items ⨝ accounts, dated by je.entry_date,
 *     with the standard project / warehouse scope. Totals reconcile with the Income Statement.
 *   • COUNTS (orders, hours, open bills, kitchen waits) come from the operational tables.
 *   • "Profit per plate" is the menu price minus the item's recipe / purchase cost — a MENU-ENGINEERING
 *     estimate, labelled as such on screen; it never feeds the ledger profit above it.
 *
 * Two scopes ($scope):
 *   'company'    — a restaurant-only company: the user's whole scope (company-wide expenses included).
 *   'restaurant' — the restaurant/hybrid shops only, inside a mixed company (company-wide costs such
 *                  as payroll cannot be attributed to a shop, so they appear in the Combined view).
 */
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/restaurant_dashboard.php';
require_once __DIR__ . '/financial_reports.php';
require_once __DIR__ . '/project_scope.php';

if (!function_exists('rdRestaurantIds')) {
    /** @return int[] restaurant/hybrid shops in the user's scope */
    function rdRestaurantIds(PDO $pdo): array
    {
        return array_values(array_map(fn($w) => (int)$w['warehouse_id'], restaurantWarehousesForSelect($pdo)));
    }
}

if (!function_exists('rdIn')) {
    function rdIn(array $ids): string
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        return $ids ? implode(',', $ids) : '0';
    }
}

if (!function_exists('rdJeScope')) {
    /** SQL fragment (alias je) limiting journal rows to what this view may see. */
    function rdJeScope(array $ids, string $scope): string
    {
        $base = scopeFilterSqlNullable('project', 'je');
        if ($scope === 'restaurant') return $base . ' AND je.warehouse_id IN (' . rdIn($ids) . ')';
        return $base . scopeFilterSqlNullable('warehouse', 'je');
    }
}

if (!function_exists('rdPeriod')) {
    /**
     * Window + bucket for the trend chart. daily = 30 days, weekly = 12 weeks (Mon-start), monthly = 12 months.
     * @return array{start:string,end:string,sql:string,keys:string[],labels:string[]}
     */
    function rdPeriod(string $period, ?string $today = null): array
    {
        $t = strtotime($today ?? date('Y-m-d'));
        $keys = []; $labels = [];
        if ($period === 'weekly') {
            $monday = strtotime('monday this week', $t);
            for ($i = 11; $i >= 0; $i--) { $d = strtotime("-$i weeks", $monday); $keys[] = date('Y-m-d', $d); $labels[] = date('d M', $d); }
            $sql = "DATE_FORMAT(DATE_SUB(je.entry_date, INTERVAL WEEKDAY(je.entry_date) DAY), '%Y-%m-%d')";
        } elseif ($period === 'monthly') {
            $first = strtotime(date('Y-m-01', $t));
            for ($i = 11; $i >= 0; $i--) { $d = strtotime("-$i months", $first); $keys[] = date('Y-m', $d); $labels[] = date('M y', $d); }
            $sql = "DATE_FORMAT(je.entry_date, '%Y-%m')";
        } elseif ($period === 'quarterly') {
            $q = (int)ceil((int)date('n', $t) / 3); $y = (int)date('Y', $t);
            for ($i = 7; $i >= 0; $i--) {
                $n = ($y * 4 + $q - 1) - $i; $yy = (int)floor($n / 4); $qq = $n % 4 + 1;
                $keys[] = $yy . '-Q' . $qq; $labels[] = 'Q' . $qq . ' ' . $yy;
                if ($i === 7) $qStart = sprintf('%04d-%02d-01', $yy, ($qq - 1) * 3 + 1);
            }
            $sql = "CONCAT(YEAR(je.entry_date), '-Q', QUARTER(je.entry_date))";
            return ['start' => $qStart, 'end' => date('Y-m-d', $t), 'sql' => $sql, 'keys' => $keys, 'labels' => $labels];
        } elseif ($period === 'yearly') {
            $y = (int)date('Y', $t);
            for ($i = 4; $i >= 0; $i--) { $keys[] = (string)($y - $i); $labels[] = (string)($y - $i); }
            return ['start' => ($y - 4) . '-01-01', 'end' => date('Y-m-d', $t), 'sql' => "DATE_FORMAT(je.entry_date, '%Y')", 'keys' => $keys, 'labels' => $labels];
        } else {
            $period = 'daily';
            for ($i = 29; $i >= 0; $i--) { $d = strtotime("-$i days", $t); $keys[] = date('Y-m-d', $d); $labels[] = date('d M', $d); }
            $sql = "DATE_FORMAT(je.entry_date, '%Y-%m-%d')";
        }
        $start = $period === 'monthly' ? $keys[0] . '-01' : $keys[0];
        return ['start' => $start, 'end' => date('Y-m-d', $t), 'sql' => $sql, 'keys' => $keys, 'labels' => $labels];
    }
}

if (!function_exists('rdLedgerBuckets')) {
    /**
     * Sales / food cost / expenses per bucket (and per area when $splitIds is given), straight from the ledger.
     * Sales = revenue + other income; food cost = cogs; expenses = expense + finance cost.
     * @return array<string, array{sales:float,cost:float,expenses:float}>  key = "bucket" or "bucket|r" / "bucket|o"
     */
    function rdLedgerBuckets(PDO $pdo, string $from, string $to, string $bucketSql, string $jeScope, ?array $splitIds = null): array
    {
        $area = $splitIds === null ? "''" : "CASE WHEN je.warehouse_id IN (" . rdIn($splitIds) . ") THEN 'r' ELSE 'o' END";
        $st = $pdo->prepare("
            SELECT $bucketSql AS b, $area AS area,
                   SUM(CASE WHEN at.category IN ('revenue','other_income') THEN (CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS sales,
                   SUM(CASE WHEN at.category = 'cogs' THEN (CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS cost,
                   SUM(CASE WHEN at.category IN ('expense','finance_cost') THEN (CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS expenses
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
              JOIN accounts a         ON a.account_id = jei.account_id
         LEFT JOIN account_types at   ON at.type_id = a.account_type_id
             WHERE je.entry_date BETWEEN ? AND ? $jeScope
          GROUP BY b, area");
        $st->execute([$from, $to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['b'] . ($splitIds === null ? '' : '|' . $r['area']);
            $out[$k] = ['sales' => (float)$r['sales'], 'cost' => (float)$r['cost'], 'expenses' => (float)$r['expenses']];
        }
        return $out;
    }
}

if (!function_exists('rdTrend')) {
    /**
     * Continuous series for the Performance chart. With $splitIds the profit is also split into the
     * restaurant shops ("r") and everything else ("o": other shops + company-wide costs).
     */
    function rdTrend(PDO $pdo, string $period, string $jeScope, ?array $splitIds = null, ?string $today = null): array
    {
        $p = rdPeriod($period, $today);
        $rows = rdLedgerBuckets($pdo, $p['start'], $p['end'], $p['sql'], $jeScope, $splitIds);
        $z = ['sales' => 0.0, 'cost' => 0.0, 'expenses' => 0.0];
        $o = ['period' => $period, 'keys' => $p['keys'], 'labels' => $p['labels'],
              'sales' => [], 'cost' => [], 'expenses' => [], 'profit' => []];
        if ($splitIds !== null) foreach (['profit_r', 'profit_o', 'sales_r', 'sales_o'] as $k) $o[$k] = [];
        foreach ($p['keys'] as $k) {
            if ($splitIds === null) {
                $r = $rows[$k] ?? $z;
                $o['sales'][] = round($r['sales'], 2); $o['cost'][] = round($r['cost'], 2); $o['expenses'][] = round($r['expenses'], 2);
                $o['profit'][] = round($r['sales'] - $r['cost'] - $r['expenses'], 2);
            } else {
                $rr = $rows["$k|r"] ?? $z; $ro = $rows["$k|o"] ?? $z;
                $o['sales_r'][] = round($rr['sales'], 2); $o['sales_o'][] = round($ro['sales'], 2);
                $o['profit_r'][] = round($rr['sales'] - $rr['cost'] - $rr['expenses'], 2);
                $o['profit_o'][] = round($ro['sales'] - $ro['cost'] - $ro['expenses'], 2);
                $s = $rr['sales'] + $ro['sales']; $c = $rr['cost'] + $ro['cost']; $e = $rr['expenses'] + $ro['expenses'];
                $o['sales'][] = round($s, 2); $o['cost'][] = round($c, 2); $o['expenses'][] = round($e, 2); $o['profit'][] = round($s - $c - $e, 2);
            }
        }
        return $o;
    }
}

if (!function_exists('rdPct')) {
    /** % change from $prev to $now; null when there is nothing to compare against. */
    function rdPct(float $now, float $prev): ?float
    {
        if (abs($prev) < 0.005) return null;
        return round((($now - $prev) / abs($prev)) * 100, 1);
    }
}

if (!function_exists('rdHero')) {
    /**
     * Today, yesterday, and a 7-day trend. Today is only part-way through, so it is never compared with a
     * full day (that would show a scary "▼ 60%" at ten in the morning). The trend compares COMPLETE days:
     * the last 7 days (yesterday back) against the 7 days before. Sales come from the ledger; order counts
     * from pos_sales (operational).
     */
    function rdHero(PDO $pdo, string $jeScope, array $ids, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $day = fn(int $n) => date('Y-m-d', strtotime("$n days", strtotime($today)));
        $from = $day(-14);
        $rows = rdLedgerBuckets($pdo, $from, $today, "DATE_FORMAT(je.entry_date, '%Y-%m-%d')", $jeScope);
        $sales = fn(string $d) => (float)($rows[$d]['sales'] ?? 0);

        $orders = [];
        $in = rdIn($ids);
        try {
            $st = $pdo->prepare("SELECT DATE(ps.sale_date) AS d, COUNT(*) AS n FROM pos_sales ps
                WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0 AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN ($in)
                GROUP BY DATE(ps.sale_date)");
            $st->execute([$from, $today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $orders[$r['d']] = (int)$r['n'];
        } catch (Throwable $e) { error_log('rdHero: ' . $e->getMessage()); }
        $n = fn(string $d) => (int)($orders[$d] ?? 0);

        $sumS = function (int $fromOff, int $toOff) use ($day, $sales) { $t = 0.0; for ($i = $fromOff; $i <= $toOff; $i++) $t += $sales($day($i)); return $t; };
        $sumN = function (int $fromOff, int $toOff) use ($day, $n) { $t = 0; for ($i = $fromOff; $i <= $toOff; $i++) $t += $n($day($i)); return $t; };
        $s7 = $sumS(-7, -1); $s7p = $sumS(-14, -8); $n7 = $sumN(-7, -1); $n7p = $sumN(-14, -8);
        $avg = fn(float $s, int $c) => $c > 0 ? round($s / $c, 2) : 0.0;

        $open = ['tables' => 0, 'amount' => 0.0];
        try {
            $r = $pdo->query("SELECT COUNT(DISTINCT h.table_id) AS n, COALESCE(SUM(h.total_amount),0) AS amt FROM pos_held_sales h
                JOIN restaurant_tables t ON t.table_id = h.table_id WHERE h.status = 'held' AND t.warehouse_id IN ($in)")->fetch(PDO::FETCH_ASSOC) ?: [];
            $open = ['tables' => (int)($r['n'] ?? 0), 'amount' => (float)($r['amt'] ?? 0)];
        } catch (Throwable $e) { error_log('rdHero open: ' . $e->getMessage()); }

        return [
            'sales'    => ['today' => $sales($today), 'yesterday' => $sales($day(-1)), 'trend7' => rdPct($s7, $s7p)],
            'orders'   => ['today' => $n($today), 'yesterday' => $n($day(-1)), 'trend7' => rdPct((float)$n7, (float)$n7p)],
            'avg_bill' => ['today' => $avg($sales($today), $n($today)), 'yesterday' => $avg($sales($day(-1)), $n($day(-1))), 'trend7' => rdPct($avg($s7, $n7), $avg($s7p, $n7p))],
            'open'     => $open,
        ];
    }
}
if (!function_exists('rdProfitStory')) {
    /**
     * "Where your money went" — this month so far against the same days last month.
     * Uses glProfitLoss, so it matches the Income Statement for the same scope and dates.
     */
    function rdProfitStory(PDO $pdo, string $jeScope, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $from = date('Y-m-01', strtotime($today));
        $day = (int)date('j', strtotime($today));
        $pFrom = date('Y-m-01', strtotime('-1 month', strtotime($from)));
        $pTo = date('Y-m-d', strtotime('+' . ($day - 1) . ' days', strtotime($pFrom)));
        if ($pTo > date('Y-m-t', strtotime($pFrom))) $pTo = date('Y-m-t', strtotime($pFrom));
        $f = function (array $pl): array {
            $sales = round(($pl['total_revenue'] ?? 0) + ($pl['total_other_income'] ?? 0), 2);
            $cost = round((float)($pl['total_cogs'] ?? 0), 2);
            $exp = round((float)($pl['total_expense'] ?? 0) + (float)($pl['total_finance_cost'] ?? 0), 2);
            return ['sales' => $sales, 'cost' => $cost, 'expenses' => $exp, 'profit' => round($sales - $cost - $exp, 2)];
        };
        $cur = $f(glProfitLoss($pdo, $from, $today, null, $jeScope));
        $prev = $f(glProfitLoss($pdo, $pFrom, $pTo, null, $jeScope));
        $per100 = $cur['sales'] > 0
            ? ['cost' => round($cur['cost'] / $cur['sales'] * 100, 1), 'expenses' => round($cur['expenses'] / $cur['sales'] * 100, 1), 'profit' => round($cur['profit'] / $cur['sales'] * 100, 1)]
            : ['cost' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
        return ['from' => $from, 'to' => $today, 'prev_from' => $pFrom, 'prev_to' => $pTo, 'cur' => $cur, 'prev' => $prev,
                'margin' => $per100['profit'], 'per100' => $per100, 'profit_vs_prev' => rdPct($cur['profit'], $prev['profit']),
                'sales_vs_prev' => rdPct($cur['sales'], $prev['sales'])];
    }
}

if (!function_exists('rdProfitCheck')) {
    /**
     * Bought → used → left, from the one ledger: what went INTO stock this period, what came OUT of it (food sold),
     * what is in stock now, and the groceries that went straight to cost. Purchases do not change profit the day you buy;
     * they change it as the food is sold. This is what lets an owner confirm that.
     */
    function rdProfitCheck(PDO $pdo, string $jeScope, string $from, string $to): array
    {
        require_once __DIR__ . '/gl_accounts.php';
        $out = ['added' => 0.0, 'used' => 0.0, 'in_stock' => 0.0, 'groceries' => 0.0];
        try {
            $inv = (int)inventoryAccountId($pdo);
            $gro = (int)restaurantIngredientsAccountId($pdo);
            if ($inv > 0) {
                $st = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? THEN jei.amount ELSE 0 END),0) AS added,
                           COALESCE(SUM(CASE WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? THEN jei.amount ELSE 0 END),0) AS used,
                           COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0) AS in_stock
                      FROM journal_entry_items jei
                      JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
                     WHERE jei.account_id = ? AND je.entry_date <= ? $jeScope");
                $st->execute([$from, $to, $from, $to, $inv, $to]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['added'] = round((float)($r['added'] ?? 0), 2); $out['used'] = round((float)($r['used'] ?? 0), 2); $out['in_stock'] = round((float)($r['in_stock'] ?? 0), 2);
            }
            if ($gro > 0) {
                $st = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
                      FROM journal_entry_items jei
                      JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
                     WHERE jei.account_id = ? AND je.entry_date BETWEEN ? AND ? $jeScope");
                $st->execute([$gro, $from, $to]);
                $out['groceries'] = round((float)$st->fetchColumn(), 2);
            }
        } catch (Throwable $e) { error_log('rdProfitCheck: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('rdProfitWarnings')) {
    /**
     * Why this month's profit may look better than it really is. Pure: works on figures already loaded.
     * @param array $cur   rdProfitStory()['cur']
     * @param array $items rdDishEarnings()['items']
     * @return array<int,array{type:string,n:int}>
     */
    function rdProfitWarnings(array $cur, array $items, float $groceries): array
    {
        $w = [];
        if (($cur['sales'] ?? 0) <= 0) return $w;
        $uncosted = count(array_filter($items, fn($i) => ($i['profit_per_plate'] ?? null) === null));
        if ($uncosted > 0) $w[] = ['type' => 'no_cost', 'n' => $uncosted];
        // A dish with no recipe uses no stock: its food cost only reaches the books as groceries you recorded.
        $noRecipe = count(array_filter($items, fn($i) => ($i['kind'] ?? '') === 'dish'));
        if ($noRecipe > 0 && $groceries <= 0) $w[] = ['type' => 'no_food_bought', 'n' => $noRecipe];
        if (($cur['expenses'] ?? 0) <= 0) $w[] = ['type' => 'no_expenses', 'n' => 0];
        return $w;
    }
}

if (!function_exists('rdPeriodRange')) {
    /**
     * The "Showing" choice on the restaurant dashboard: from the start of the period to today, and the same stretch of the
     * period before it, so a part of this week is never compared with a whole last week.
     * @param string $p today|week|month|quarter|year (anything else means today)
     */
    function rdPeriodRange(string $p, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d'); $t = strtotime($today);
        $back = function (string $from, int $months) use ($today): array {   // the same number of days, a period earlier
            $pf = date('Y-m-d', strtotime("-$months months", strtotime($from)));
            $days = (int)((strtotime($today) - strtotime($from)) / 86400);
            $pt = date('Y-m-d', strtotime("+$days days", strtotime($pf)));
            $last = date('Y-m-d', strtotime('-1 day', strtotime($from)));
            return [$pf, $pt > $last ? $last : $pt];
        };
        if ($p === 'week') {
            $from = date('Y-m-d', strtotime('monday this week', $t));
            $days = (int)(($t - strtotime($from)) / 86400);
            $pf = date('Y-m-d', strtotime('-7 days', strtotime($from)));
            return ['key' => 'week', 'from' => $from, 'to' => $today, 'prev_from' => $pf, 'prev_to' => date('Y-m-d', strtotime("+$days days", strtotime($pf)))];
        }
        if ($p === 'month')   { $from = date('Y-m-01', $t); [$a, $b] = $back($from, 1);  return ['key' => 'month', 'from' => $from, 'to' => $today, 'prev_from' => $a, 'prev_to' => $b]; }
        if ($p === 'quarter') { $from = date('Y', $t) . '-' . sprintf('%02d', ((int)ceil((int)date('n', $t) / 3) - 1) * 3 + 1) . '-01'; [$a, $b] = $back($from, 3); return ['key' => 'quarter', 'from' => $from, 'to' => $today, 'prev_from' => $a, 'prev_to' => $b]; }
        if ($p === 'year')    { $from = date('Y-01-01', $t); [$a, $b] = $back($from, 12); return ['key' => 'year', 'from' => $from, 'to' => $today, 'prev_from' => $a, 'prev_to' => $b]; }
        $y = date('Y-m-d', strtotime('-1 day', $t));
        return ['key' => 'today', 'from' => $today, 'to' => $today, 'prev_from' => $y, 'prev_to' => $y];
    }
}

if (!function_exists('rdPeriodStory')) {
    /** Sales, costs and profit for a period from the one ledger, its number of orders, and the same stretch before it. Same shape as rdProfitStory(). */
    function rdPeriodStory(PDO $pdo, string $jeScope, array $ids, array $r): array
    {
        $f = function (array $pl): array {
            $sales = round(($pl['total_revenue'] ?? 0) + ($pl['total_other_income'] ?? 0), 2);
            $cost = round((float)($pl['total_cogs'] ?? 0), 2);
            $exp = round((float)($pl['total_expense'] ?? 0) + (float)($pl['total_finance_cost'] ?? 0), 2);
            return ['sales' => $sales, 'cost' => $cost, 'expenses' => $exp, 'profit' => round($sales - $cost - $exp, 2)];
        };
        $cur = $f(glProfitLoss($pdo, $r['from'], $r['to'], null, $jeScope));
        $prev = $f(glProfitLoss($pdo, $r['prev_from'], $r['prev_to'], null, $jeScope));
        $orders = 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM pos_sales ps WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0
                                  AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN (" . rdIn($ids) . ")");
            $st->execute([$r['from'], $r['to']]);
            $orders = (int)$st->fetchColumn();
        } catch (Throwable $e) { error_log('rdPeriodStory: ' . $e->getMessage()); }
        $per100 = $cur['sales'] > 0
            ? ['cost' => round($cur['cost'] / $cur['sales'] * 100, 1), 'expenses' => round($cur['expenses'] / $cur['sales'] * 100, 1), 'profit' => round($cur['profit'] / $cur['sales'] * 100, 1)]
            : ['cost' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
        return ['from' => $r['from'], 'to' => $r['to'], 'prev_from' => $r['prev_from'], 'prev_to' => $r['prev_to'], 'cur' => $cur, 'prev' => $prev,
                'margin' => $per100['profit'], 'per100' => $per100, 'profit_vs_prev' => rdPct($cur['profit'], $prev['profit']),
                'sales_vs_prev' => rdPct($cur['sales'], $prev['sales']), 'orders' => $orders,
                'avg_bill' => $orders > 0 ? round($cur['sales'] / $orders, 2) : 0.0];
    }
}

if (!function_exists('rdDashExtras')) {
    /**
     * The small counts the Layout-2 restaurant dashboard shows on its action buttons and summary cards (2026-11-02).
     * Counts only, from the operational tables, scoped to the restaurant shops $ids (and the user's expense scope).
     * @return array{tables_total:int, kitchen_waiting:int, kitchen_longest:int, reservations_today:int, expenses_pending:int, expenses_pending_amount:float, expenses_month:float}
     */
    function rdDashExtras(PDO $pdo, array $ids): array
    {
        $in = rdIn($ids);
        $o = ['tables_total' => 0, 'kitchen_waiting' => 0, 'kitchen_longest' => 0, 'reservations_today' => 0,
              'expenses_pending' => 0, 'expenses_pending_amount' => 0.0];
        try { $o['tables_total'] = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables WHERE warehouse_id IN ($in)")->fetchColumn(); }
        catch (Throwable $e) { error_log('rdDashExtras tables: ' . $e->getMessage()); }
        try {
            $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(TIMESTAMPDIFF(MINUTE, created_at, NOW())),0) AS m FROM kitchen_tickets
                               WHERE status = 'queued' AND warehouse_id IN ($in) AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) >= 20")->fetch(PDO::FETCH_ASSOC) ?: [];
            $o['kitchen_waiting'] = (int)($r['n'] ?? 0); $o['kitchen_longest'] = (int)($r['m'] ?? 0);
        } catch (Throwable $e) { /* kitchen tables are optional */ }
        try {
            $o['reservations_today'] = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_reservations
                WHERE warehouse_id IN ($in) AND DATE(reservation_time) = CURDATE() AND status IN ('booked','confirmed','pending','seated')")->fetchColumn();
        } catch (Throwable $e) { /* reservations are optional */ }
        try {
            $st = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(e.amount),0) AS a FROM expenses e WHERE e.status = 'pending'"
                . scopeFilterSqlNullable('warehouse', 'e') . scopeFilterSqlNullable('project', 'e'));
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $o['expenses_pending'] = (int)($r['c'] ?? 0); $o['expenses_pending_amount'] = (float)($r['a'] ?? 0);
        } catch (Throwable $e) { error_log('rdDashExtras expenses: ' . $e->getMessage()); }
        return $o;
    }
}

if (!function_exists('rdRecentActivities')) {
    /** The latest things that happened (sales, payments, edits — not page views), newest first. Caller checks the permission. */
    function rdRecentActivities(PDO $pdo, int $limit = 8): array
    {
        try {
            $st = $pdo->query("SELECT al.action, al.description, al.created_at, u.username
                                 FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id
                                WHERE NOT (al.action LIKE 'View%' OR al.action IN ('VIEW', 'view_list', 'FILTER') OR al.action LIKE 'Filter%' OR al.action LIKE 'Searched%'
                                           OR COALESCE(al.description, '') LIKE 'User viewed%' OR COALESCE(al.description, '') LIKE '[LOG] User viewed%' OR COALESCE(al.description, '') LIKE '[VIEW]%')
                             ORDER BY al.created_at DESC LIMIT " . (int)$limit);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('rdRecentActivities: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('rdClassifyDishes')) {
    /**
     * Menu engineering on the COSTED items: popularity (plates sold) against profit per plate,
     * each split at the median. Needs at least 3 costed items to say anything honest.
     * Adds 'class' = star | gem | popular | review to every costed item.
     * @param array<int,array> $items each with qty, profit_per_plate (null = not costed)
     */
    function rdClassifyDishes(array $items): array
    {
        $costed = array_keys(array_filter($items, fn($i) => $i['profit_per_plate'] !== null && (float)$i['qty'] > 0));
        if (count($costed) < 3) {
            foreach ($items as $k => $i) $items[$k]['class'] = null;
            return $items;
        }
        $med = function (array $v): float { sort($v); $n = count($v); return $n % 2 ? (float)$v[intdiv($n, 2)] : ((float)$v[$n / 2 - 1] + (float)$v[$n / 2]) / 2; };
        $mq = $med(array_map(fn($k) => (float)$items[$k]['qty'], $costed));
        $mp = $med(array_map(fn($k) => (float)$items[$k]['profit_per_plate'], $costed));
        foreach ($items as $k => $i) {
            if (!in_array($k, $costed, true)) { $items[$k]['class'] = null; continue; }
            $hiQ = (float)$i['qty'] >= $mq; $hiP = (float)$i['profit_per_plate'] >= $mp;
            $items[$k]['class'] = $hiQ && $hiP ? 'star' : (!$hiQ && $hiP ? 'gem' : ($hiQ ? 'popular' : 'review'));
        }
        return $items;
    }
}

if (!function_exists('rdDishEarnings')) {
    /**
     * Per menu item sold in the window: plates, price actually charged, unit cost (recipe for a dish,
     * purchase cost for a bought item), profit per plate, estimated profit, margin, menu-engineering class.
     * An item with no recipe / no purchase cost is listed under 'needs_cost' — never guessed.
     */
    function rdDishEarnings(PDO $pdo, array $ids, string $from, string $to): array
    {
        $out = ['items' => [], 'best_earners' => [], 'best_sellers' => [], 'needs_cost' => [], 'costed_count' => 0, 'est_profit' => 0.0, 'avg_margin' => null];
        $in = rdIn($ids);
        // Restaurant costing plan, Phase 2 — a plate's share of the recipe's "other costs" (0 before the migration).
        $extraPP  = restaurantExtraPerPlateSql($pdo, 'p');
        $extraGrp = restaurantExtraCostReady($pdo) ? ', p.recipe_extra_cost, p.recipe_yield' : '';
        try {
            $st = $pdo->prepare("
                SELECT p.product_id, p.product_name, p.is_service, p.selling_price, COALESCE(p.cost_price,0) AS cost_price,
                       SUM(psi.quantity) AS qty, SUM(psi.line_total) AS revenue,
                       (SELECT COUNT(*) FROM product_assembly_components ac WHERE ac.parent_product_id = p.product_id) AS comp_count,
                       (SELECT SUM(ac.qty_per_unit * COALESCE(cp.cost_price,0)) FROM product_assembly_components ac
                          LEFT JOIN products cp ON cp.product_id = ac.component_product_id WHERE ac.parent_product_id = p.product_id) AS recipe_cost,
                       $extraPP AS extra_pp
                  FROM pos_sale_items psi
                  JOIN pos_sales ps ON ps.sale_id = psi.sale_id
                  JOIN products p   ON p.product_id = psi.product_id
                 WHERE ps.sale_status IN ('completed','partially_refunded') AND ps.is_return_sale = 0
                   AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN ($in) AND p.is_menu_item = 1
              GROUP BY p.product_id, p.product_name, p.is_service, p.selling_price, p.cost_price$extraGrp
              ORDER BY qty DESC");
            $st->execute([$from, $to]);
            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $qty = (float)$r['qty'];
                $price = $qty > 0 && (float)$r['revenue'] > 0 ? (float)$r['revenue'] / $qty : (float)$r['selling_price'];
                $cost = null;
                $hasRecipe = (int)$r['comp_count'] > 0 || (float)$r['extra_pp'] > 0;   // ingredients and/or other costs
                if ((int)$r['is_service'] === 1 && $hasRecipe) $cost = (float)$r['recipe_cost'] + (float)$r['extra_pp'];
                elseif ((int)$r['is_service'] === 0 && (float)$r['cost_price'] > 0) $cost = (float)$r['cost_price'];
                // A dish without a recipe has no known cost (restaurant costing plan, 2026-11-09): the old
                // "cost of one plate" guess (purchase_price) is no longer read, so these figures match the Menu.
                $ppp = $cost === null ? null : round($price - $cost, 2);
                $items[(int)$r['product_id']] = [
                    'product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'qty' => $qty,
                    'price' => round($price, 2), 'cost' => $cost === null ? null : round($cost, 2),
                    'profit_per_plate' => $ppp, 'margin' => ($ppp !== null && $price > 0) ? round($ppp / $price * 100, 1) : null,
                    'est_profit' => $ppp === null ? null : round($ppp * $qty, 2), 'kind' => ((int)$r['is_service'] === 1 && $hasRecipe) ? 'recipe' : ((int)$r['is_service'] === 0 ? 'bought' : 'dish'),
                ];
            }
            $items = rdClassifyDishes($items);
            $costed = array_values(array_filter($items, fn($i) => $i['profit_per_plate'] !== null));
            usort($costed, fn($a, $b) => $b['est_profit'] <=> $a['est_profit']);
            $sellers = array_values($items); usort($sellers, fn($a, $b) => $b['qty'] <=> $a['qty']);
            $needs = array_values(array_filter($items, fn($i) => $i['profit_per_plate'] === null));
            usort($needs, fn($a, $b) => $b['qty'] <=> $a['qty']);
            $rev = 0.0; $est = 0.0;
            foreach ($costed as $i) { $rev += $i['price'] * $i['qty']; $est += $i['est_profit']; }
            $out = ['items' => array_values($items), 'best_earners' => array_slice($costed, 0, 5), 'best_sellers' => array_slice($sellers, 0, 5),
                    'needs_cost' => $needs, 'costed_count' => count($costed), 'est_profit' => round($est, 2),
                    'avg_margin' => $rev > 0 ? round($est / $rev * 100, 1) : null];
        } catch (Throwable $e) { error_log('rdDishEarnings: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('rdOrdersByHour')) {
    /** Orders per hour of the day over the last $days days (counts, not money). @return array{hours:int[],peak:?int,total:int} */
    function rdOrdersByHour(PDO $pdo, array $ids, int $days = 30, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($today)));
        $hours = array_fill(0, 24, 0);
        try {
            $st = $pdo->prepare("SELECT HOUR(ps.sale_date) AS h, COUNT(*) AS n FROM pos_sales ps
                WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0 AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN (" . rdIn($ids) . ")
                GROUP BY HOUR(ps.sale_date)");
            $st->execute([$from, $today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $hours[(int)$r['h']] = (int)$r['n'];
        } catch (Throwable $e) { error_log('rdOrdersByHour: ' . $e->getMessage()); }
        $total = array_sum($hours);
        $peak = $total > 0 ? array_search(max($hours), $hours, true) : null;
        return ['hours' => $hours, 'peak' => $peak === false ? null : $peak, 'total' => $total];
    }
}

if (!function_exists('rdAttention')) {
    /**
     * The short "needs attention" list (max 3, most important first).
     * @return array<int,array{type:string,n:int,extra:int}>
     */
    function rdAttention(PDO $pdo, array $ids, int $uncostedCount, int $lowStockCount): array
    {
        $in = rdIn($ids); $list = [];
        try {
            $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(TIMESTAMPDIFF(MINUTE, h.held_at, NOW())),0) AS m FROM pos_held_sales h
                JOIN restaurant_tables t ON t.table_id = h.table_id
                WHERE h.status = 'held' AND t.warehouse_id IN ($in) AND TIMESTAMPDIFF(MINUTE, h.held_at, NOW()) >= 90")->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int)($r['n'] ?? 0) > 0) $list[] = ['type' => 'old_bills', 'n' => (int)$r['n'], 'extra' => (int)$r['m']];
        } catch (Throwable $e) { error_log('rdAttention bills: ' . $e->getMessage()); }
        try {
            $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(TIMESTAMPDIFF(MINUTE, kt.created_at, NOW())),0) AS m FROM kitchen_tickets kt
                WHERE kt.status = 'queued' AND kt.warehouse_id IN ($in) AND TIMESTAMPDIFF(MINUTE, kt.created_at, NOW()) >= 20")->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int)($r['n'] ?? 0) > 0) $list[] = ['type' => 'kitchen_wait', 'n' => (int)$r['n'], 'extra' => (int)$r['m']];
        } catch (Throwable $e) { /* kitchen tables optional */ }
        if ($lowStockCount > 0) $list[] = ['type' => 'low_stock', 'n' => $lowStockCount, 'extra' => 0];
        if ($uncostedCount > 0) $list[] = ['type' => 'no_cost', 'n' => $uncostedCount, 'extra' => 0];
        return array_slice($list, 0, 3);
    }
}

if (!function_exists('rdAreaTotals')) {
    /** Month-to-date sales / profit for the restaurant shops and for everything else (mixed companies). */
    function rdAreaTotals(PDO $pdo, array $ids, string $from, string $to): array
    {
        $rows = rdLedgerBuckets($pdo, $from, $to, "'m'", rdJeScope($ids, 'company'), $ids);
        $z = ['sales' => 0.0, 'cost' => 0.0, 'expenses' => 0.0];
        $r = $rows['m|r'] ?? $z; $o = $rows['m|o'] ?? $z;
        $pf = fn(array $x) => round($x['sales'] - $x['cost'] - $x['expenses'], 2);
        return ['restaurant' => ['sales' => round($r['sales'], 2), 'profit' => $pf($r)],
                'other' => ['sales' => round($o['sales'], 2), 'profit' => $pf($o)],
                'total' => ['sales' => round($r['sales'] + $o['sales'], 2), 'profit' => round($pf($r) + $pf($o), 2)]];
    }
}

/* ───────────── Dashboard redesign (2026-11-03): chart, kitchen, open orders, staff ───────────── */

if (!function_exists('rdCompareSeries')) {
    /**
     * The "Sales | Orders" chart for the chosen period, against the same stretch of the period before (rdPeriodRange()):
     * Today → by hour vs yesterday · This week → by day vs last week · This month → by day vs last month ·
     * This quarter → by week vs last quarter · This year → by month vs last year. Slots stop at today.
     * Sales: the one ledger (revenue + other income, the same as the Sales card); a POS sale's hour comes from its sale time.
     * Orders: counts of completed POS sales (operational).
     * @return array{key:string,labels:string[],sales:float[],sales_prev:float[],orders:int[],orders_prev:int[],peak:?string}
     */
    function rdCompareSeries(PDO $pdo, string $jeScope, array $ids, array $r): array
    {
        $key = in_array($r['key'] ?? '', ['today', 'week', 'month', 'quarter', 'year'], true) ? $r['key'] : 'today';
        $from = strtotime($r['from']); $to = strtotime($r['to']);
        // slot labels, up to today
        if ($key === 'week')        { $n = (int)date('N', $to); $labels = []; for ($i = 0; $i < $n; $i++) $labels[] = t(date('D', strtotime("+$i days", $from))); }
        elseif ($key === 'month')   { $n = (int)date('j', $to); $labels = array_map('strval', range(1, $n)); }
        elseif ($key === 'quarter') { $n = intdiv((int)(($to - $from) / 86400), 7) + 1; $labels = []; for ($i = 0; $i < $n; $i++) $labels[] = date('d M', strtotime('+' . ($i * 7) . ' days', $from)); }
        elseif ($key === 'year')    { $n = (int)date('n', $to); $labels = []; for ($i = 1; $i <= $n; $i++) $labels[] = t(date('M', mktime(0, 0, 0, $i, 1, 2000))); }
        else                        { $n = 24; $labels = array_map(fn($h) => sprintf('%02d', $h), range(0, 23)); }
        // where a row falls: $d = its date column, $tm = its date-and-time column, $start = the window's first day
        $pos = function (string $d, string $tm, string $start) use ($key, $pdo): string {
            if ($key === 'week')    return "WEEKDAY($d)";
            if ($key === 'month')   return "(DAY($d) - 1)";
            if ($key === 'quarter') return "FLOOR(DATEDIFF($d, " . $pdo->quote($start) . ") / 7)";
            if ($key === 'year')    return "(MONTH($d) - 1)";
            return "HOUR($tm)";
        };
        $in = rdIn($ids);
        $sales = function (string $a, string $b) use ($pdo, $jeScope, $pos, $n): array {
            $out = array_fill(0, $n, 0.0);
            try {
                $st = $pdo->prepare("
                    SELECT " . $pos('je.entry_date', 'COALESCE(ps.sale_date, je.created_at)', $a) . " AS p,
                           SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE -jei.amount END) AS s
                      FROM journal_entry_items jei
                      JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
                      JOIN accounts a         ON a.account_id = jei.account_id
                      JOIN account_types at   ON at.type_id = a.account_type_id AND at.category IN ('revenue','other_income')
                 LEFT JOIN pos_sales ps       ON je.entity_type = 'pos_sale' AND ps.sale_id = je.entity_id
                     WHERE je.entry_date BETWEEN ? AND ? $jeScope
                  GROUP BY p");
                $st->execute([$a, $b]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $p = (int)$row['p']; if ($p >= 0 && $p < $n) $out[$p] = round((float)$row['s'], 2); }
            } catch (Throwable $e) { error_log('rdCompareSeries sales: ' . $e->getMessage()); }
            return $out;
        };
        $orders = function (string $a, string $b) use ($pdo, $in, $pos, $n): array {
            $out = array_fill(0, $n, 0);
            try {
                $st = $pdo->prepare("SELECT " . $pos('DATE(ps.sale_date)', 'ps.sale_date', $a) . " AS p, COUNT(*) AS c FROM pos_sales ps
                    WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0 AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN ($in)
                    GROUP BY p");
                $st->execute([$a, $b]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $p = (int)$row['p']; if ($p >= 0 && $p < $n) $out[$p] = (int)$row['c']; }
            } catch (Throwable $e) { error_log('rdCompareSeries orders: ' . $e->getMessage()); }
            return $out;
        };
        // Profit view (2026-11-03): each slot's costs from the one ledger, split the way a restaurant owner
        // thinks about them — drinks & stock items SOLD (cost posted with the sale: *_cogs entries), food
        // BOUGHT without stock (every other Cost-of-goods posting: "Something else", Buy ingredients, grocery
        // bills — counted the day bought), and expenses. Sales − the three = profit, the same buckets as above.
        // Restaurant costing plan, Phase 3: stock taken for cooking ('restaurant_cook_cogs') joins the stock part
        // ("Drinks & stock used"); spoiled stock and kitchen waste get their own part ("Spoiled & waste").
        // The three cost parts still add up to the same Cost-of-goods total.
        $parts = function (string $a, string $b) use ($pdo, $jeScope, $pos, $n): array {
            $out = ['stock' => array_fill(0, $n, 0.0), 'food' => array_fill(0, $n, 0.0), 'waste' => array_fill(0, $n, 0.0), 'exp' => array_fill(0, $n, 0.0)];
            // Phase 5: plates cooked by the pot but not sold (and a re-close's reversal) are waste too.
            $isWaste = "je.entity_type IN ('kitchen_waste','restaurant_spoilage','restaurant_cook_unsold','restaurant_cook_unsold_void')";
            try {
                $st = $pdo->prepare("
                    SELECT " . $pos('je.entry_date', 'je.created_at', $a) . " AS p,
                           SUM(CASE WHEN at.category = 'cogs' AND je.entity_type LIKE '%\\_cogs%' THEN (CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS stock,
                           SUM(CASE WHEN at.category = 'cogs' AND je.entity_type IS NOT NULL AND $isWaste THEN (CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS waste,
                           SUM(CASE WHEN at.category = 'cogs' AND (je.entity_type IS NULL OR (je.entity_type NOT LIKE '%\\_cogs%' AND NOT $isWaste)) THEN (CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS food,
                           SUM(CASE WHEN at.category IN ('expense','finance_cost') THEN (CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END) ELSE 0 END) AS exp
                      FROM journal_entry_items jei
                      JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
                      JOIN accounts a         ON a.account_id = jei.account_id
                      JOIN account_types at   ON at.type_id = a.account_type_id AND at.category IN ('cogs','expense','finance_cost')
                     WHERE je.entry_date BETWEEN ? AND ? $jeScope
                  GROUP BY p");
                $st->execute([$a, $b]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $p = (int)$row['p'];
                    if ($p < 0 || $p >= $n) continue;
                    foreach (['stock', 'food', 'waste', 'exp'] as $c) $out[$c][$p] = round((float)$row[$c], 2);
                }
            } catch (Throwable $e) { error_log('rdCompareSeries profit: ' . $e->getMessage()); }
            return $out;
        };
        $o = ['key' => $key, 'labels' => $labels,
              'sales' => $sales($r['from'], $r['to']), 'sales_prev' => $sales($r['prev_from'], $r['prev_to']),
              'orders' => $orders($r['from'], $r['to']), 'orders_prev' => $orders($r['prev_from'], $r['prev_to'])];
        $pp = $parts($r['from'], $r['to']);
        $o['p_stock'] = $pp['stock']; $o['p_food'] = $pp['food']; $o['p_waste'] = $pp['waste']; $o['p_exp'] = $pp['exp'];
        // Period totals for the Money panel — taken before "today" trims the night hours off the slots.
        $o['p_tot'] = ['stock' => round(array_sum($pp['stock']), 2), 'food' => round(array_sum($pp['food']), 2),
                       'waste' => round(array_sum($pp['waste']), 2), 'exp' => round(array_sum($pp['exp']), 2)];
        $o['p_cum'] = []; $run = 0.0;
        foreach ($o['sales'] as $i => $s) { $run += $s - $pp['stock'][$i] - $pp['food'][$i] - $pp['waste'][$i] - $pp['exp'][$i]; $o['p_cum'][] = round($run, 2); }
        if ($key === 'today') {                    // trim the night: from 08:00 (or the first sale) to 21:00 (or the last)
            $busy = []; for ($h = 0; $h < 24; $h++) if ($o['sales'][$h] != 0 || $o['sales_prev'][$h] != 0 || $o['orders'][$h] || $o['orders_prev'][$h]) $busy[] = $h;
            $lo = $busy ? min($busy[0], 8) : 8; $hi = $busy ? max(end($busy), 21) : 21;
            foreach (['labels', 'sales', 'sales_prev', 'orders', 'orders_prev', 'p_stock', 'p_food', 'p_waste', 'p_exp', 'p_cum'] as $k) $o[$k] = array_slice($o[$k], $lo, $hi - $lo + 1);
        }
        $mx = $o['sales'] ? max($o['sales']) : 0;
        $o['peak'] = $mx > 0 ? $o['labels'][array_search($mx, $o['sales'], true)] : null;
        return $o;
    }
}

if (!function_exists('rdKitchenLoad')) {
    /** Kitchen tickets waiting or cooking right now, per kitchen area (operational counts). @return array{total:int,areas:array<int,array{name:string,n:int}>} */
    function rdKitchenLoad(PDO $pdo, array $ids): array
    {
        $out = ['total' => 0, 'areas' => []];
        try {
            $st = $pdo->query("SELECT COALESCE(NULLIF(ks.name, ''), '') AS name, COUNT(*) AS n FROM kitchen_tickets kt
                LEFT JOIN kitchen_stations ks ON ks.station_id = kt.station_id
                WHERE kt.status IN ('queued','preparing') AND kt.warehouse_id IN (" . rdIn($ids) . ")
                GROUP BY kt.station_id, ks.name ORDER BY n DESC");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['areas'][] = ['name' => $r['name'] !== '' ? $r['name'] : t('Kitchen'), 'n' => (int)$r['n']];
                $out['total'] += (int)$r['n'];
            }
        } catch (Throwable $e) { /* kitchen tables are optional */ }
        return $out;
    }
}

if (!function_exists('rdOpenOrders')) {
    /** Table orders not paid yet, newest first (operational: the held order of each table). */
    function rdOpenOrders(PDO $pdo, array $ids, int $limit = 5): array
    {
        try {
            return $pdo->query("SELECT h.hold_id, t.table_number, COALESCE(h.item_count, 0) AS item_count, h.total_amount, h.held_at,
                       TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS waiter
                  FROM pos_held_sales h JOIN restaurant_tables t ON t.table_id = h.table_id LEFT JOIN users u ON u.user_id = h.user_id
                 WHERE h.status = 'held' AND t.warehouse_id IN (" . rdIn($ids) . ")
              ORDER BY h.held_at DESC LIMIT " . max(1, (int)$limit))->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('rdOpenOrders: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('rdStaffSales')) {
    /**
     * Sales by staff for the period: the "served by" person picked on the till, otherwise who rang the sale up.
     * Amounts from the one ledger (the POS sale's revenue lines); order counts from the same sales.
     * @return array<int,array{name:string,orders:int,sales:float}>
     */
    function rdStaffSales(PDO $pdo, string $jeScope, array $ids, string $from, string $to, int $limit = 5): array
    {
        try {
            $st = $pdo->prepare("
                SELECT COALESCE(ps.assigned_to, ps.user_id) AS uid, COUNT(DISTINCT ps.sale_id) AS orders,
                       SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE -jei.amount END) AS sales,
                       MAX(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS name, MAX(u.username) AS uname
                  FROM journal_entry_items jei
                  JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted' AND je.entity_type = 'pos_sale'
                  JOIN accounts a         ON a.account_id = jei.account_id
                  JOIN account_types at   ON at.type_id = a.account_type_id AND at.category = 'revenue'
                  JOIN pos_sales ps       ON ps.sale_id = je.entity_id
             LEFT JOIN users u            ON u.user_id = COALESCE(ps.assigned_to, ps.user_id)
                 WHERE je.entry_date BETWEEN ? AND ? AND ps.warehouse_id IN (" . rdIn($ids) . ") $jeScope
              GROUP BY uid ORDER BY sales DESC LIMIT " . max(1, (int)$limit));
            $st->execute([$from, $to]);
            return array_map(fn($r) => ['name' => trim((string)$r['name']) !== '' ? trim((string)$r['name']) : (string)($r['uname'] ?? '—'),
                                        'orders' => (int)$r['orders'], 'sales' => round((float)$r['sales'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) { error_log('rdStaffSales: ' . $e->getMessage()); return []; }
    }
}
