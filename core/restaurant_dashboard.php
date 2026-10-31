<?php
/**
 * core/restaurant_dashboard.php — figures for the restaurant dashboard
 * (restaurant_plan.md Phase 6: dashboard follows what the company has).
 *
 * Money figures come from the ONE ledger (glProfitLoss — posted journal, project +
 * warehouse scope), per .claude/reporting-source.md: sales today, and this month's
 * sales / ingredients (Cost of Sales) / food profit / operating expenses.
 * Operational counts and lists (orders today, open tables, recent orders, top dishes,
 * drinks running low) read the operational tables, limited to the user's restaurant shops.
 */
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/financial_reports.php';
require_once __DIR__ . '/project_scope.php';

if (!function_exists('restaurantDashboardData')) {
    function restaurantDashboardData(PDO $pdo): array
    {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $jeScope = scopeFilterSqlNullable('project', 'je') . scopeFilterSqlNullable('warehouse', 'je');
        $plToday = glProfitLoss($pdo, $today, $today, null, $jeScope);
        $plMonth = glProfitLoss($pdo, $monthStart, $today, null, $jeScope);
        $sales = fn(array $pl) => round(($pl['total_revenue'] ?? 0) + ($pl['total_other_income'] ?? 0), 2);

        $out = [
            'sales_today'       => $sales($plToday),
            'month_sales'       => $sales($plMonth),
            'month_ingredients' => round((float)($plMonth['total_cogs'] ?? 0), 2),
            'month_expenses'    => round((float)($plMonth['total_expense'] ?? 0), 2),
            'orders_today'      => 0,
            'open_tables'       => 0,
            'open_amount'       => 0.0,
            'recent'            => [],
            'top_dishes'        => [],
            'low_drinks'        => [],
        ];
        $out['month_food_profit'] = round($out['month_sales'] - $out['month_ingredients'], 2);

        $ids = array_map(fn($w) => (int)$w['warehouse_id'], restaurantWarehousesForSelect($pdo));
        if (!$ids) return $out;
        $in = implode(',', $ids);

        try {
            $out['orders_today'] = (int)$pdo->query("SELECT COUNT(*) FROM pos_sales ps
                WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0 AND DATE(ps.sale_date) = CURDATE() AND ps.warehouse_id IN ($in)")->fetchColumn();

            $row = $pdo->query("SELECT COUNT(DISTINCT h.table_id) AS n, COALESCE(SUM(h.total_amount),0) AS amt
                FROM pos_held_sales h JOIN restaurant_tables t ON t.table_id = h.table_id
                WHERE h.status = 'held' AND t.warehouse_id IN ($in)")->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['open_tables'] = (int)($row['n'] ?? 0);
            $out['open_amount'] = (float)($row['amt'] ?? 0);

            $out['recent'] = $pdo->query("SELECT ps.sale_id, ps.receipt_number, ps.sale_type, ps.grand_total, ps.sale_date, rt.table_number
                FROM pos_sales ps LEFT JOIN restaurant_tables rt ON rt.table_id = ps.table_id
                WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0 AND ps.warehouse_id IN ($in)
                ORDER BY ps.sale_date DESC, ps.sale_id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

            $out['top_dishes'] = $pdo->query("SELECT p.product_name, SUM(psi.quantity) AS qty, SUM(psi.line_total) AS amount
                FROM pos_sale_items psi JOIN pos_sales ps ON ps.sale_id = psi.sale_id JOIN products p ON p.product_id = psi.product_id
                WHERE ps.sale_status = 'completed' AND ps.is_return_sale = 0 AND DATE(ps.sale_date) = CURDATE()
                  AND ps.warehouse_id IN ($in) AND p.is_menu_item = 1
                GROUP BY p.product_id, p.product_name ORDER BY qty DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

            $out['low_drinks'] = $pdo->query("SELECT p.product_name, COALESCE(SUM(ps.stock_quantity),0) AS stock
                FROM products p LEFT JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id IN ($in)
                WHERE p.status = 'active' AND p.is_service = 0
                  AND (p.is_menu_item = 1
                       -- restaurant_plan.md Phase 7 — ingredients used in a dish's recipe
                       OR p.product_id IN (SELECT ac.component_product_id FROM product_assembly_components ac
                                             JOIN products d ON d.product_id = ac.parent_product_id
                                            WHERE d.is_menu_item = 1 AND d.is_service = 1 AND d.is_combo = 1))
                GROUP BY p.product_id, p.product_name, p.min_stock_level
                HAVING stock <= GREATEST(COALESCE(p.min_stock_level,0), 5)
                ORDER BY stock ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('restaurantDashboardData: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('restaurantSalesByOrderType')) {
    /**
     * restaurant_plan.md Phase 6 — Restaurant Sales report: sales per order type
     * (Dine in / Take away / Delivery / other) from the ONE ledger: posted revenue
     * lines (account_types.category = 'revenue', same classification as glProfitLoss)
     * of 'pos_sale' journal entries, joined to the sale for its order type.
     * Returns are posted separately (pos_return) and are not split by order type.
     *
     * @param int[] $warehouseIds restaurant shops to include (already scope-checked)
     * @return array<int, array{sale_type:string, orders:int, sales:float}>
     */
    function restaurantSalesByOrderType(PDO $pdo, string $from, string $to, array $warehouseIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $warehouseIds)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $scope = scopeFilterSqlNullable('project', 'je');
        $st = $pdo->prepare("
            SELECT COALESCE(ps.sale_type, 'walk_in') AS sale_type,
                   COUNT(DISTINCT ps.sale_id) AS orders,
                   SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE -jei.amount END) AS sales
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted' AND je.entity_type = 'pos_sale'
              JOIN accounts a         ON a.account_id = jei.account_id
              JOIN account_types at   ON at.type_id = a.account_type_id AND at.category = 'revenue'
              JOIN pos_sales ps       ON ps.sale_id = je.entity_id
             WHERE je.entry_date BETWEEN ? AND ? AND ps.warehouse_id IN ($in) $scope
          GROUP BY COALESCE(ps.sale_type, 'walk_in')
          ORDER BY sales DESC");
        $st->execute([$from, $to]);
        return array_map(fn($r) => ['sale_type' => $r['sale_type'], 'orders' => (int)$r['orders'], 'sales' => round((float)$r['sales'], 2)],
            $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('restaurantItemsSold')) {
    /**
     * Quantities sold per menu item and group (operational counts, no money figures).
     * @param int[] $warehouseIds
     */
    function restaurantItemsSold(PDO $pdo, string $from, string $to, array $warehouseIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $warehouseIds)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $st = $pdo->prepare("
            SELECT p.product_name, COALESCE(c.category_name, '') AS group_name, p.is_service, SUM(psi.quantity) AS qty
              FROM pos_sale_items psi
              JOIN pos_sales ps ON ps.sale_id = psi.sale_id
              JOIN products p   ON p.product_id = psi.product_id
         LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE ps.sale_status IN ('completed','partially_refunded') AND ps.is_return_sale = 0
               AND DATE(ps.sale_date) BETWEEN ? AND ? AND ps.warehouse_id IN ($in) AND p.is_menu_item = 1
          GROUP BY p.product_id, p.product_name, c.category_name, p.is_service
          ORDER BY qty DESC");
        $st->execute([$from, $to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
