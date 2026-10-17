<?php
/**
 * api/account/get_product_analysis_report.php
 *
 * AJAX data source for the Product Performance report — summary, three chart
 * datasets, and per-product rows.
 *
 * Revenue/quantity sold = SUM(quantity * unit_price) / SUM(quantity) across
 * invoice_items + pos_sale_items (actual realised sale lines — same
 * definition as get_sales_report.php, the reference implementation).
 * 2026-09-10: used to read sales_order_items only, so a POS-only tenant
 * (Sales module off) always showed every product as unsold despite real POS
 * activity. sales_order_items is deliberately NOT unioned in alongside them,
 * since a sales order that gets invoiced would otherwise be counted twice.
 *
 * Project- and warehouse-scoped per security.md §23 via the parent
 * invoices/pos_sales row (invoice_items/pos_sale_items carry no scope
 * columns of their own).
 *
 * Profit (2026-10-16, "Most Profitable Products"): each line's cost uses the SAME
 * basis the ledger posts as cost of goods sold — invoice lines quantity ×
 * products.cost_price (core/revenue_posting.php invoiceCogsValue), POS lines their
 * batch cost or quantity × cost_price, skipping a cost above the selling price
 * (core/sales_posting.php posSaleCogs). Services post no cost of goods; a service
 * with a recorded cost (materials) shows it as an ESTIMATE, flagged per row. The
 * goods cost total is reconciled against the ledger's COGS for the period.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('product_analysis')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$date_from    = $_GET['date_from']    ?? date('Y-01-01');
$date_to      = $_GET['date_to']      ?? date('Y-12-31');
$project_id   = (isset($_GET['project_id'])   && $_GET['project_id']   !== '') ? (int)$_GET['project_id']   : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;
$item_type    = in_array($_GET['item_type'] ?? '', ['', 'product', 'service'], true) ? ($_GET['item_type'] ?? '') : '';
$sort         = ($_GET['sort'] ?? '') === 'profit' ? 'profit' : 'revenue';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success'=>false,'message'=>'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>isShopLabel() ? 'Access denied: this shop is not in your assigned scope.' : 'Access denied: this warehouse is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    // Invoice-side condition (lives in the JOIN, same shape as before). Each
    // side keeps its OWN params array so the merged order exactly matches
    // the placeholder order in the final SQL text (inv's, then pos's).
    $inv_params = [$date_from, $date_to];
    $invCond = "i.invoice_date BETWEEN ? AND ? AND i.status != 'cancelled'";
    if ($project_id !== null)   { $invCond .= " AND i.project_id = ?";   $inv_params[] = $project_id; }
    else                        { $invCond .= scopeFilterSqlNullable('project', 'i'); }
    if ($warehouse_id !== null) { $invCond .= " AND i.warehouse_id = ?"; $inv_params[] = $warehouse_id; }
    else                        { $invCond .= scopeFilterSqlNullable('warehouse', 'i'); }

    // POS-side condition.
    $pos_params = [$date_from, $date_to];
    $posCond = "DATE(ps.sale_date) BETWEEN ? AND ? AND ps.sale_status = 'completed'";
    if ($project_id !== null)   { $posCond .= " AND ps.project_id = ?";   $pos_params[] = $project_id; }
    else                        { $posCond .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $posCond .= " AND ps.warehouse_id = ?"; $pos_params[] = $warehouse_id; }
    else                        { $posCond .= scopeFilterSqlNullable('warehouse', 'ps'); }
    $params = array_merge($inv_params, $pos_params);

    // Aggregated PER-PRODUCT first (each source individually), so joining
    // two one-to-many item tables to `products` at once can't fan out and
    // inflate SUMs — a classic multi-join bug avoided here on purpose.
    // Revenue is after line discounts (invoice discount_amount; POS line_total
    // is already net). The analysis starts from the sold lines, so an item that
    // was deleted later, or a line typed by hand, still counts.
    $hasLineDiscount = (bool)$pdo->query("SHOW COLUMNS FROM invoice_items LIKE 'discount_amount'")->fetch();
    $invNet = $hasLineDiscount ? "ii.quantity * ii.unit_price - COALESCE(ii.discount_amount, 0)" : "ii.quantity * ii.unit_price";
    $U = fn(string $col) => "CONVERT($col USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    // goods_cost: what the ledger posts as COGS for the line; est_cost: a service's recorded cost (not posted).
    $hasBatches = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sale_item_batches'")->fetch();
    $posBatch = $hasBatches ? "
          LEFT JOIN (SELECT psib.sale_item_id, SUM(psib.quantity) AS batch_qty, SUM(psib.quantity * pb.unit_cost) AS batch_cost
                       FROM pos_sale_item_batches psib JOIN product_batches pb ON pb.batch_id = psib.batch_id
                      GROUP BY psib.sale_item_id) bc ON bc.sale_item_id = psi.sale_item_id" : "";
    $posGoods = $hasBatches
        ? "CASE WHEN COALESCE(pp.is_service, 1) = 1 THEN 0
                WHEN bc.batch_qty > 0 THEN bc.batch_cost
                WHEN pp.cost_price > pp.selling_price AND pp.selling_price > 0 THEN 0
                ELSE psi.quantity * COALESCE(pp.cost_price, 0) END"
        : "CASE WHEN COALESCE(pp.is_service, 1) = 1 THEN 0
                WHEN pp.cost_price > pp.selling_price AND pp.selling_price > 0 THEN 0
                ELSE psi.quantity * COALESCE(pp.cost_price, 0) END";
    $combined = "
        SELECT COALESCE(ii.product_id, 0) AS product_id, {$U('ii.product_name')} AS line_name,
               ii.quantity AS qty, ii.unit_price AS unit_price, ($invNet) AS revenue,
               CASE WHEN ip.product_id IS NOT NULL AND ip.is_service = 0 THEN ii.quantity * COALESCE(ip.cost_price, 0) ELSE 0 END AS goods_cost,
               CASE WHEN ip.is_service = 1 THEN ii.quantity * COALESCE(ip.cost_price, 0) ELSE 0 END AS est_cost
          FROM invoice_items ii
          JOIN invoices i ON ii.invoice_id = i.invoice_id AND $invCond
          LEFT JOIN products ip ON ip.product_id = ii.product_id
        UNION ALL
        SELECT psi.product_id, {$U('psi.product_name')}, psi.quantity, psi.unit_price, COALESCE(psi.line_total, psi.quantity * psi.unit_price),
               $posGoods,
               CASE WHEN pp.is_service = 1 THEN psi.quantity * COALESCE(pp.cost_price, 0) ELSE 0 END
          FROM pos_sale_items psi
          JOIN pos_sales ps ON psi.sale_id = ps.sale_id AND $posCond
          LEFT JOIN products pp ON pp.product_id = psi.product_id
          $posBatch
    ";
    $typeCond = '';
    if ($item_type === 'service') $typeCond = " WHERE (ci.product_id = 0 OR p.is_service = 1)";
    if ($item_type === 'product') $typeCond = " WHERE p.product_id IS NOT NULL AND COALESCE(p.is_service, 0) = 0";

    $stmt = $pdo->prepare("
        SELECT ci.product_id,
               MAX(CASE WHEN p.is_service = 1
                        THEN COALESCE(NULLIF({$U('p.contract_item_no')}, ''), NULLIF({$U('p.product_code')}, ''), '')
                        ELSE COALESCE(NULLIF({$U('p.product_code')}, ''), NULLIF({$U('p.contract_item_no')}, ''), '') END) AS product_code,
               CASE WHEN ci.product_id = 0 THEN 'Not linked to an item'
                    ELSE MAX(COALESCE({$U('p.product_name')}, ci.line_name)) END   AS product_name,
               MAX(COALESCE({$U('c.category_name')}, 'Uncategorised'))             AS category,
               MAX(COALESCE(p.is_service, 0))                                      AS is_service,
               MAX(CASE WHEN ci.product_id > 0 AND p.product_id IS NULL THEN 1 ELSE 0 END) AS deleted,
               COUNT(*)                                                            AS times_sold,
               COALESCE(SUM(ci.qty), 0)                                            AS qty_sold,
               COALESCE(SUM(ci.revenue), 0)                                        AS revenue,
               COALESCE(AVG(ci.unit_price), 0)                                     AS avg_price,
               COALESCE(SUM(ci.goods_cost), 0)                                     AS goods_cost,
               COALESCE(SUM(ci.est_cost), 0)                                       AS est_cost
          FROM ($combined) ci
          LEFT JOIN products p   ON p.product_id = ci.product_id AND ci.product_id > 0
          LEFT JOIN categories c ON p.category_id = c.category_id
          $typeCond
      GROUP BY ci.product_id
        HAVING qty_sold > 0
      ORDER BY revenue DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $goods = round((float)$r['goods_cost'], 2);
        $est   = round((float)$r['est_cost'], 2);
        $r['cost']       = round($goods + $est, 2);
        $r['cost_basis'] = $goods > 0 ? 'ledger' : ($est > 0 ? 'estimate' : 'none');
        $r['profit']     = round((float)$r['revenue'] - $r['cost'], 2);
        $r['margin']     = (float)$r['revenue'] > 0 ? round($r['profit'] / (float)$r['revenue'] * 100, 1) : null;
    }
    unset($r);
    if ($sort === 'profit') usort($rows, fn($a, $b) => $b['profit'] <=> $a['profit']);

    $total_cost   = array_sum(array_column($rows, 'cost'));
    $total_profit = array_sum(array_column($rows, 'profit'));
    $goods_cost   = round(array_sum(array_map(fn($r) => (float)$r['goods_cost'], $rows)), 2);

    // Reconcile the goods cost with the ledger's cost of goods sold for the same period/scope.
    $ledger = null;
    try {
        require_once __DIR__ . '/../../core/financial_classification.php';
        require_once __DIR__ . '/../../core/financial_reports.php';
        $scopeSql = ($project_id === null ? scopeFilterSqlNullable('project', 'je') : '')
                  . ($warehouse_id === null ? scopeFilterSqlNullable('warehouse', 'je') : '');
        $pl = glProfitLoss($pdo, $date_from, $date_to, $project_id, $scopeSql, $warehouse_id);
        $ledger = ['cogs' => (float)$pl['total_cogs'], 'report_goods_cost' => $goods_cost,
                   'difference' => round($goods_cost - (float)$pl['total_cogs'], 2)];
    } catch (Throwable $e) {
        error_log('product_analysis ledger reconcile: ' . $e->getMessage());
    }

    $total_revenue = array_sum(array_map(fn($r) => (float)$r['revenue'], $rows));
    $total_units   = array_sum(array_map(fn($r) => (float)$r['qty_sold'], $rows));

    $top_products = array_slice(array_map(fn($r) => ['name'=>$r['product_name'],'total'=>(float)$r['revenue']], $rows), 0, 8);
    $top_units    = array_slice(array_map(fn($r) => ['name'=>$r['product_name'],'value'=>(float)$r['qty_sold']],
                        (function($rows){ usort($rows, fn($a,$b)=>(float)$b['qty_sold']<=>(float)$a['qty_sold']); return $rows; })($rows)), 0, 8);

    $byCat = [];
    foreach ($rows as $r) { $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float)$r['revenue']; }
    arsort($byCat);
    $by_category = array_map(fn($k,$v) => ['label'=>$k,'value'=>round($v,2)], array_keys($byCat), array_values($byCat));

    echo json_encode([
        'success' => true,
        'summary' => [
            'products_sold' => count($rows),
            'total_revenue' => round($total_revenue, 2),
            'total_units'   => $total_units,
            'avg_price'     => count($rows) > 0 ? round(array_sum(array_map(fn($r)=>(float)$r['avg_price'],$rows))/count($rows),2) : 0,
            'total_cost'    => round($total_cost, 2),
            'total_profit'  => round($total_profit, 2),
            'margin'        => $total_revenue > 0 ? round($total_profit / $total_revenue * 100, 1) : null,
        ],
        'ledger' => $ledger,
        'sort'   => $sort,
        'charts' => [
            'top_products' => $top_products,
            'by_category'  => $by_category,
            'top_units'    => $top_units,
            'top_profit'   => array_slice(array_map(fn($r) => ['name' => $r['product_name'], 'value' => (float)$r['profit']],
                                (function ($rs) { usort($rs, fn($a, $b) => $b['profit'] <=> $a['profit']); return $rs; })($rows)), 0, 8),
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_product_analysis_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
