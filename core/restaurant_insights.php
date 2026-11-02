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
        try {
            $st = $pdo->prepare("
                SELECT p.product_id, p.product_name, p.is_service, p.selling_price, COALESCE(p.cost_price,0) AS cost_price,
                       SUM(psi.quantity) AS qty, SUM(psi.line_total) AS revenue,
                       (SELECT COUNT(*) FROM product_assembly_components ac WHERE ac.parent_product_id = p.product_id) AS comp_count,
                       (SELECT SUM(ac.qty_per_unit * COALESCE(cp.cost_price,0)) FROM product_assembly_components ac
                          LEFT JOIN products cp ON cp.product_id = ac.component_product_id WHERE ac.parent_product_id = p.product_id) AS recipe_cost
                  FROM pos_sale_items psi
                  JOIN pos_sales ps ON ps.sale_id = psi.sale_id
                  JOIN products p   ON p.product_id = psi.product_id
                 WHERE ps.sale_status IN ('completed','partially_refunded') AND ps.is_return_sale = 0
                   AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN ($in) AND p.is_menu_item = 1
              GROUP BY p.product_id, p.product_name, p.is_service, p.selling_price, p.cost_price
              ORDER BY qty DESC");
            $st->execute([$from, $to]);
            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $qty = (float)$r['qty'];
                $price = $qty > 0 && (float)$r['revenue'] > 0 ? (float)$r['revenue'] / $qty : (float)$r['selling_price'];
                $cost = null;
                if ((int)$r['comp_count'] > 0) $cost = (float)$r['recipe_cost'];
                elseif ((int)$r['is_service'] === 0 && (float)$r['cost_price'] > 0) $cost = (float)$r['cost_price'];
                $ppp = $cost === null ? null : round($price - $cost, 2);
                $items[(int)$r['product_id']] = [
                    'product_id' => (int)$r['product_id'], 'name' => $r['product_name'], 'qty' => $qty,
                    'price' => round($price, 2), 'cost' => $cost === null ? null : round($cost, 2),
                    'profit_per_plate' => $ppp, 'margin' => ($ppp !== null && $price > 0) ? round($ppp / $price * 100, 1) : null,
                    'est_profit' => $ppp === null ? null : round($ppp * $qty, 2), 'kind' => (int)$r['comp_count'] > 0 ? 'recipe' : ((int)$r['is_service'] === 0 ? 'bought' : 'dish'),
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
