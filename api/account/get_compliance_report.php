<?php
/**
 * api/account/get_compliance_report.php
 *
 * AJAX data source for the Compliance dashboard. Runs four compliance checks
 * and returns per-type counts, chart data and a unified exceptions list:
 *   1. Products expiring within 30 days
 *   2. High-value customers (>1,000,000 sales) missing a TIN
 *   3. Invoices cancelled in the last 30 days
 *   4. Active-stock products with missing/zero cost price
 *
 * Admin-oversight report. Project-scope helpers are applied where the table
 * carries project_id (no-op for admins, who see everything) per security.md §23.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('compliance_report') && !isAdmin()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    global $pdo;
    $rows = [];

    // 1. Expiring products (products.project_id scoped)
    $pScope = scopeFilterSqlNullable('project', 'p');
    $stmt = $pdo->prepare("
        SELECT p.product_name, p.sku, p.stock_quantity,
               p.expiry_date, DATEDIFF(p.expiry_date, CURDATE()) AS days_remaining
          FROM products p
         WHERE p.expiry_date IS NOT NULL
           AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND p.stock_quantity > 0 $pScope
      ORDER BY p.expiry_date ASC
    ");
    $stmt->execute();
    $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expiring as $r) {
        $dr = (int)$r['days_remaining'];
        $rows[] = [
            'category' => 'Expiring Stock',
            'reference'=> $r['sku'] ?: $r['product_name'],
            'detail'   => $r['product_name'] . ' — expires ' . date('d M Y', strtotime($r['expiry_date'])),
            'value'    => $dr < 0 ? abs($dr).' days overdue' : $dr.' days left',
            'severity' => $dr < 0 ? 'high' : ($dr <= 7 ? 'high' : 'medium'),
        ];
    }

    // 2. High-value customers missing TIN (scope via invoices.project_id)
    $iScope = scopeFilterSqlNullable('project', 'i');
    $stmt = $pdo->prepare("
        SELECT c.customer_name, c.phone, COALESCE(SUM(i.grand_total),0) AS total_sales
          FROM customers c
          JOIN invoices i ON c.customer_id = i.customer_id
         WHERE (c.tin_number IS NULL OR c.tin_number = '')
           AND i.status != 'cancelled' $iScope
      GROUP BY c.customer_id, c.customer_name, c.phone
        HAVING total_sales > 1000000
      ORDER BY total_sales DESC
    ");
    $stmt->execute();
    $missing_tin = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($missing_tin as $r) {
        $rows[] = [
            'category' => 'Missing TIN',
            'reference'=> $r['phone'] ?: '—',
            'detail'   => $r['customer_name'] . ' — high-value customer with no TIN',
            'value'    => number_format((float)$r['total_sales'], 2),
            'severity' => 'high',
        ];
    }

    // 3. Cancelled invoices, last 30 days (invoices.project_id scoped)
    $iScope2 = scopeFilterSqlNullable('project', 'i');
    $stmt = $pdo->prepare("
        SELECT i.invoice_number, i.invoice_date, i.grand_total
          FROM invoices i
         WHERE i.status = 'cancelled'
           AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) $iScope2
      ORDER BY i.invoice_date DESC
    ");
    $stmt->execute();
    $cancelled = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cancelled as $r) {
        $rows[] = [
            'category' => 'Cancelled Invoice',
            'reference'=> $r['invoice_number'],
            'detail'   => 'Cancelled on ' . date('d M Y', strtotime($r['invoice_date'])),
            'value'    => number_format((float)$r['grand_total'], 2),
            'severity' => 'medium',
        ];
    }

    // 4. Active-stock products missing cost price (products.project_id scoped)
    $pScope2 = scopeFilterSqlNullable('project', 'p');
    $stmt = $pdo->prepare("
        SELECT p.product_name, p.sku, p.selling_price
          FROM products p
         WHERE (p.cost_price IS NULL OR p.cost_price = 0)
           AND p.stock_quantity > 0 $pScope2
    ");
    $stmt->execute();
    $missing_cost = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($missing_cost as $r) {
        $rows[] = [
            'category' => 'Missing Cost Price',
            'reference'=> $r['sku'] ?: $r['product_name'],
            'detail'   => $r['product_name'] . ' — no cost price (distorts profit)',
            'value'    => 'Sell: ' . number_format((float)$r['selling_price'], 2),
            'severity' => 'medium',
        ];
    }

    $counts = [
        'expiring'     => count($expiring),
        'missing_tin'  => count($missing_tin),
        'cancelled'    => count($cancelled),
        'missing_cost' => count($missing_cost),
    ];
    $total = array_sum($counts);
    $high  = count(array_filter($rows, fn($r) => $r['severity'] === 'high'));

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_issues' => $total,
            'high'         => $high,
            'expiring'     => $counts['expiring'],
            'missing_tin'  => $counts['missing_tin'],
        ],
        'charts' => [
            'by_type' => [
                ['label'=>'Expiring Stock',     'value'=>$counts['expiring']],
                ['label'=>'Missing TIN',        'value'=>$counts['missing_tin']],
                ['label'=>'Cancelled Invoices', 'value'=>$counts['cancelled']],
                ['label'=>'Missing Cost Price', 'value'=>$counts['missing_cost']],
            ],
            'by_severity' => [
                ['label'=>'High',   'value'=>$high],
                ['label'=>'Medium', 'value'=>$total - $high],
            ],
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_compliance_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
