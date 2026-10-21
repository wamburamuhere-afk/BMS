<?php
/**
 * api/account/get_service_sales_report.php
 * Sales of each service for app/constant/reports/service_sales_report.php.
 *
 * Invoice lines count only when their invoice is posted to the ledger and not
 * cancelled, so the figures agree with the revenue the books recognised. POS
 * service sales (completed, not converted to an invoice, net of returns) can be
 * included. Lines typed by hand on an invoice (no catalogue link) are kept as
 * one "Not linked" row instead of disappearing.
 *
 * Net revenue = quantity x price - line discount, before VAT.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('service_sales_report')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$date_from   = $_GET['date_from'] ?? date('Y-01-01');
$date_to     = $_GET['date_to']   ?? date('Y-12-31');
$source      = in_array($_GET['source'] ?? '', ['', 'invoice', 'pos'], true) ? ($_GET['source'] ?? '') : '';
$project_id  = (isset($_GET['project_id'])  && $_GET['project_id']  !== '') ? (int)$_GET['project_id']  : null;
$customer_id = (isset($_GET['customer_id']) && $_GET['customer_id'] !== '') ? (int)$_GET['customer_id'] : null;
$category_id = (isset($_GET['category_id']) && $_GET['category_id'] !== '') ? (int)$_GET['category_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) || $date_from > $date_to) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    // ── one row per sold line, both sources, same shape ──────────────────
    $invWhere  = ["i.invoice_date BETWEEN ? AND ?", "i.status <> 'cancelled'",
                  "EXISTS (SELECT 1 FROM journal_entries je WHERE je.entity_type = 'invoice' AND je.entity_id = i.invoice_id AND je.status = 'posted')",
                  "(p.product_id IS NULL OR p.is_service = 1)"];
    $invParams = [$date_from, $date_to];
    if ($project_id !== null)  { $invWhere[] = "i.project_id = ?";  $invParams[] = $project_id; }
    if ($customer_id !== null) { $invWhere[] = "i.customer_id = ?"; $invParams[] = $customer_id; }
    $invSql = implode(' AND ', $invWhere) . ($project_id === null ? scopeFilterSqlNullable('project', 'i') : '') . scopeFilterSqlNullable('warehouse', 'i');
    $hasLineDiscount = (bool)$pdo->query("SHOW COLUMNS FROM invoice_items LIKE 'discount_amount'")->fetch();
    $invDisc = $hasLineDiscount ? 'COALESCE(ii.discount_amount, 0)' : '0';

    $posWhere  = ["DATE(ps.sale_date) BETWEEN ? AND ?", "ps.invoice_id IS NULL", "p.is_service = 1",
                  "((ps.is_return_sale = 0 AND ps.sale_status IN ('completed','partially_refunded','refunded'))
                    OR (ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled')))"];
    $posParams = [$date_from, $date_to];
    if ($project_id !== null)  { $posWhere[] = "ps.project_id = ?";  $posParams[] = $project_id; }
    if ($customer_id !== null) { $posWhere[] = "ps.customer_id = ?"; $posParams[] = $customer_id; }
    $posSql = implode(' AND ', $posWhere) . ($project_id === null ? scopeFilterSqlNullable('project', 'ps') : '') . scopeFilterSqlNullable('warehouse', 'ps');

    $parts = []; $params = [];
    if ($source !== 'pos') {
        $parts[] = "SELECT 'invoice' AS src, i.invoice_id AS doc_id, i.invoice_date AS doc_date, i.customer_id,
                           COALESCE(ii.product_id, 0) AS product_id, CONVERT(ii.product_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS line_name,
                           ii.quantity AS qty, ii.quantity * ii.unit_price AS gross, $invDisc AS discount,
                           COALESCE(ii.tax_amount, 0) AS vat
                      FROM invoice_items ii
                      JOIN invoices i ON i.invoice_id = ii.invoice_id
                      LEFT JOIN products p ON p.product_id = ii.product_id
                     WHERE $invSql";
        $params = array_merge($params, $invParams);
    }
    if ($source !== 'invoice') {
        $parts[] = "SELECT 'pos' AS src, ps.sale_id AS doc_id, DATE(ps.sale_date) AS doc_date, ps.customer_id,
                           psi.product_id, CONVERT(psi.product_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS line_name,
                           CASE WHEN ps.is_return_sale = 1 THEN -psi.quantity ELSE psi.quantity END AS qty,
                           CASE WHEN ps.is_return_sale = 1 THEN -1 ELSE 1 END * psi.quantity * psi.unit_price AS gross,
                           CASE WHEN ps.is_return_sale = 1 THEN -1 ELSE 1 END * COALESCE(psi.discount_amount, 0) AS discount,
                           CASE WHEN ps.is_return_sale = 1 THEN -1 ELSE 1 END * COALESCE(psi.tax_amount, 0) AS vat
                      FROM pos_sale_items psi
                      JOIN pos_sales ps ON ps.sale_id = psi.sale_id
                      JOIN products p ON p.product_id = psi.product_id
                     WHERE $posSql";
        $params = array_merge($params, $posParams);
    }
    $lines = "(" . implode(" UNION ALL ", $parts) . ")";

    $catJoin = "LEFT JOIN products sp ON sp.product_id = l.product_id AND l.product_id > 0
                LEFT JOIN categories c ON c.category_id = sp.category_id";
    $catWhere = '';
    if ($category_id !== null) { $catWhere = " WHERE sp.category_id = ?"; }
    $catParams = $category_id !== null ? [$category_id] : [];

    // ── per service ─────────────────────────────────────────────────────
    $st = $pdo->prepare("
        SELECT l.product_id,
               MAX(COALESCE(NULLIF(CONVERT(sp.contract_item_no USING utf8mb4) COLLATE utf8mb4_unicode_ci, ''), NULLIF(CONVERT(sp.sku USING utf8mb4) COLLATE utf8mb4_unicode_ci, ''), '')) AS code,
               CASE WHEN l.product_id = 0 THEN 'Not linked to a service' ELSE MAX(COALESCE(CONVERT(sp.product_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, l.line_name)) END AS name,
               MAX(COALESCE(CONVERT(c.category_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CASE WHEN l.product_id = 0 THEN '—' ELSE 'Uncategorised' END)) AS category,
               MAX(CASE WHEN l.product_id > 0 AND sp.product_id IS NULL THEN 1 ELSE 0 END) AS deleted,
               COUNT(DISTINCT CONCAT(l.src, ':', l.doc_id)) AS documents,
               COUNT(DISTINCT l.customer_id) AS customers,
               SUM(l.qty) AS qty, SUM(l.gross) AS gross, SUM(l.discount) AS discount,
               SUM(l.gross - l.discount) AS net, SUM(l.vat) AS vat, MAX(l.doc_date) AS last_sold
          FROM $lines l
          $catJoin
          $catWhere
      GROUP BY l.product_id
        HAVING ABS(SUM(l.qty)) > 0.0005 OR ABS(SUM(l.gross - l.discount)) > 0.005
      ORDER BY net DESC
    ");
    $st->execute(array_merge($params, $catParams));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $totalNet = array_sum(array_map(fn($r) => (float)$r['net'], $rows));
    foreach ($rows as &$r) {
        foreach (['qty', 'gross', 'discount', 'net', 'vat'] as $k) $r[$k] = round((float)$r[$k], 2);
        $r['product_id'] = (int)$r['product_id'];
        $r['documents']  = (int)$r['documents'];
        $r['customers']  = (int)$r['customers'];
        $r['deleted']    = (int)$r['deleted'] === 1;
        $r['avg_price']  = abs($r['qty']) > 0.0005 ? round($r['net'] / $r['qty'], 2) : 0;
        $r['share']      = $totalNet != 0.0 ? round($r['net'] / $totalNet * 100, 1) : 0;
    }
    unset($r);

    // ── summary (one pass over the same lines) ──────────────────────────
    $sm = $pdo->prepare("
        SELECT COUNT(DISTINCT CONCAT(l.src, ':', l.doc_id)) AS documents, COUNT(DISTINCT l.customer_id) AS customers,
               COALESCE(SUM(l.gross - l.discount), 0) AS net, COALESCE(SUM(l.vat), 0) AS vat, COALESCE(SUM(l.discount), 0) AS discount
          FROM $lines l $catJoin $catWhere");
    $sm->execute(array_merge($params, $catParams));
    $sum = $sm->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── monthly trend for the top 5 services ────────────────────────────
    $top5 = array_slice(array_values(array_filter($rows, fn($r) => $r['product_id'] > 0)), 0, 5);
    $months = [];
    for ($m = new DateTime(substr($date_from, 0, 7) . '-01'), $end = new DateTime(substr($date_to, 0, 7) . '-01'); $m <= $end && count($months) < 36; $m->modify('+1 month')) {
        $months[] = $m->format('Y-m');
    }
    $trend = ['months' => $months, 'series' => []];
    if ($top5) {
        $ids = array_map(fn($r) => $r['product_id'], $top5);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $tr  = $pdo->prepare("SELECT l.product_id, DATE_FORMAT(l.doc_date, '%Y-%m') AS ym, SUM(l.gross - l.discount) AS net
                                FROM $lines l $catJoin
                               WHERE l.product_id IN ($ph)" . ($category_id !== null ? " AND sp.category_id = ?" : '') . "
                            GROUP BY l.product_id, ym");
        $tr->execute(array_merge($params, $ids, $catParams));
        $byId = [];
        foreach ($tr->fetchAll(PDO::FETCH_ASSOC) as $t) $byId[(int)$t['product_id']][$t['ym']] = round((float)$t['net'], 2);
        foreach ($top5 as $r) {
            $trend['series'][] = ['name' => $r['name'], 'data' => array_map(fn($ym) => $byId[$r['product_id']][$ym] ?? 0, $months)];
        }
    }

    // ── by category ─────────────────────────────────────────────────────
    $byCat = [];
    foreach ($rows as $r) $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + $r['net'];
    arsort($byCat);

    // ── quoted vs invoiced per service (same period, same filters) ──────
    $quoted = [];
    if ($source !== 'pos') {
        $qWhere = ["q.order_date BETWEEN ? AND ?", "q.status <> 'cancelled'", "p.is_service = 1"];
        $qParams = [$date_from, $date_to];
        if ($project_id !== null)  { $qWhere[] = "q.project_id = ?";  $qParams[] = $project_id; }
        if ($customer_id !== null) { $qWhere[] = "q.customer_id = ?"; $qParams[] = $customer_id; }
        if ($category_id !== null) { $qWhere[] = "p.category_id = ?"; $qParams[] = $category_id; }
        $qs = $pdo->prepare("SELECT qi.product_id, COUNT(DISTINCT q.sales_order_id) AS quotes,
                                    SUM(qi.quantity * qi.unit_price - COALESCE(qi.discount_amount, 0)) AS quoted,
                                    COUNT(DISTINCT CASE WHEN q.converted_to_so_id IS NOT NULL THEN q.sales_order_id END) AS converted
                               FROM quotation_items qi
                               JOIN quotations q ON q.sales_order_id = qi.order_id
                               JOIN products p ON p.product_id = qi.product_id
                              WHERE " . implode(' AND ', $qWhere)
                              . ($project_id === null ? scopeFilterSqlNullable('project', 'q') : '') . "
                           GROUP BY qi.product_id");
        $qs->execute($qParams);
        foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $q) $quoted[(int)$q['product_id']] = $q;
    }
    $conversion = [];
    $netById = [];
    foreach ($rows as $r) if ($r['product_id'] > 0) $netById[$r['product_id']] = $r;
    foreach ($quoted as $pid => $q) {
        $name = $netById[$pid]['name'] ?? ($pdo->query("SELECT product_name FROM products WHERE product_id = " . (int)$pid)->fetchColumn() ?: 'Service #' . $pid);
        $conversion[] = [
            'name'       => $name,
            'quotes'     => (int)$q['quotes'],
            'converted'  => (int)$q['converted'],
            'quoted'     => round((float)$q['quoted'], 2),
            'invoiced'   => round((float)($netById[$pid]['net'] ?? 0), 2),
            'rate'       => (int)$q['quotes'] > 0 ? round((int)$q['converted'] / (int)$q['quotes'] * 100, 1) : 0,
        ];
    }
    usort($conversion, fn($a, $b) => $b['quoted'] <=> $a['quoted']);

    $top = array_values(array_filter($rows, fn($r) => $r['product_id'] > 0))[0] ?? null;
    echo json_encode([
        'success' => true,
        'summary' => [
            'net_revenue'  => round((float)($sum['net'] ?? 0), 2),
            'vat'          => round((float)($sum['vat'] ?? 0), 2),
            'discount'     => round((float)($sum['discount'] ?? 0), 2),
            'documents'    => (int)($sum['documents'] ?? 0),
            'customers'    => (int)($sum['customers'] ?? 0),
            'services'     => count(array_filter($rows, fn($r) => $r['product_id'] > 0)),
            'top_service'  => $top ? ['name' => $top['name'], 'net' => $top['net']] : null,
        ],
        'charts' => [
            'top_services' => array_map(fn($r) => ['name' => $r['name'], 'net' => $r['net']], array_slice($rows, 0, 10)),
            'trend'        => $trend,
            'by_category'  => array_map(fn($k, $v) => ['label' => $k, 'value' => round($v, 2)], array_keys($byCat), array_values($byCat)),
            'conversion'   => array_slice($conversion, 0, 10),
        ],
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('get_service_sales_report: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
