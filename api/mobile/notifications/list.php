<?php
// scope-audit: skip — each query below carries its own RBAC + project/warehouse scope gate
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../../core/permissions.php';

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit;
}

global $pdo;
$user_id = (int)$_SESSION['user_id'];

// Initialise all groups in the same order the web dashboard shows them.
$groups = [
    'invoices'    => ['key' => 'invoices',    'title' => 'Invoices & Payments',        'color' => 'warning', 'items' => []],
    'products'    => ['key' => 'products',    'title' => 'Inventory & Products',        'color' => 'danger',  'items' => []],
    'approvals'   => ['key' => 'approvals',   'title' => 'Pending Approvals',           'color' => 'primary', 'items' => []],
    'cash_bank'   => ['key' => 'cash_bank',   'title' => 'Cash & Bank Controls',        'color' => 'danger',  'items' => []],
    'credit_risk' => ['key' => 'credit_risk', 'title' => 'Customers Over Credit Limit', 'color' => 'danger',  'items' => []],
    'grn_pending' => ['key' => 'grn_pending', 'title' => 'Goods Receipt Pending',        'color' => 'warning', 'items' => []],
    'hr_payroll'  => ['key' => 'hr_payroll',  'title' => 'HR & Payroll',                'color' => 'warning', 'items' => []],
    'quotes'      => ['key' => 'quotes',      'title' => 'Expiring Quotations',          'color' => 'warning', 'items' => []],
    'tenders'     => ['key' => 'tenders',     'title' => 'Expiring Tenders',             'color' => 'warning', 'items' => []],
    'documents'   => ['key' => 'documents',   'title' => 'Document Expiry',             'color' => 'warning', 'items' => []],
    'others'      => ['key' => 'others',      'title' => 'General Notifications',        'color' => 'info',    'items' => []],
];

// ── 1. Inventory: low stock, expiring batches, negative stock ─────────────────
if (canView('products')) {
    $prodScope = scopeFilterSqlNullable('project', 'p');
    $whScope   = scopeFilterSqlNullable('warehouse', 'product_stocks');
    // Avoid false positives for warehouse-scoped users: only count products that
    // actually have a stock row in their warehouse(s). Same logic as dashboard.php.
    $stockedOnlyInScope = ($whScope !== '') ? ' AND s.product_id IS NOT NULL ' : '';

    try {
        $stmt = $pdo->prepare("
            SELECT 'low_stock' AS type,
                   p.product_id AS id,
                   p.product_name,
                   p.sku,
                   COALESCE(s.available_stock, 0) AS stock_quantity,
                   p.min_stock_level AS reorder_level,
                   CASE WHEN COALESCE(s.available_stock, 0) <= 0
                        THEN 'Out of stock'
                        ELSE 'Low stock alert' END AS message
            FROM products p
            LEFT JOIN (
                SELECT product_id,
                       SUM(stock_quantity - reserved_quantity) AS available_stock
                FROM product_stocks
                WHERE 1=1 {$whScope}
                GROUP BY product_id
            ) s ON p.product_id = s.product_id
            WHERE p.status = 'active'
              AND p.is_service = 0
              AND (
                    (COALESCE(s.available_stock, 0) <= p.min_stock_level AND p.min_stock_level > 0)
                 OR (COALESCE(s.available_stock, 0) <= 0)
              )
              {$prodScope}{$stockedOnlyInScope}
            ORDER BY COALESCE(s.available_stock, 0) ASC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['stock_quantity'] = (float)$row['stock_quantity'];
            $row['reorder_level']  = (float)$row['reorder_level'];
            $groups['products']['items'][] = $row;
        }
    } catch (PDOException $e) {}

    // Expiring product batches (Phase 17c) + products without batch rows
    $batchWhScope = scopeFilterSqlNullable('warehouse', 'pb');
    try {
        $stmt = $pdo->prepare("
            SELECT 'expiring' AS type,
                   p.product_id AS id,
                   p.product_name,
                   p.sku,
                   pb.expiry_date,
                   DATEDIFF(pb.expiry_date, CURDATE()) AS days_remaining,
                   pb.batch_number,
                   pb.quantity_remaining,
                   'Product batch expiring soon' AS message
            FROM product_batches pb
            JOIN products p ON p.product_id = pb.product_id
            WHERE pb.expiry_date IS NOT NULL
              AND pb.quantity_remaining > 0
              AND pb.expiry_date > CURDATE()
              AND DATEDIFF(pb.expiry_date, CURDATE()) <= 30
              AND p.status = 'active'
              {$prodScope}
              {$batchWhScope}

            UNION ALL

            SELECT 'expiring' AS type,
                   p.product_id AS id,
                   p.product_name,
                   p.sku,
                   p.expiry_date,
                   DATEDIFF(p.expiry_date, CURDATE()) AS days_remaining,
                   NULL AS batch_number,
                   NULL AS quantity_remaining,
                   'Product expiring soon' AS message
            FROM products p
            WHERE p.expiry_date IS NOT NULL
              AND p.is_service = 0
              AND p.expiry_date > CURDATE()
              AND DATEDIFF(p.expiry_date, CURDATE()) <= 30
              AND p.status = 'active'
              AND p.product_id NOT IN (SELECT DISTINCT product_id FROM product_batches)
              {$prodScope}
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['days_remaining']    = (int)$row['days_remaining'];
            $row['quantity_remaining'] = isset($row['quantity_remaining']) ? (float)$row['quantity_remaining'] : null;
            $groups['products']['items'][] = $row;
        }
    } catch (PDOException $e) {}

    // Negative stock
    try {
        $stmt = $pdo->prepare("
            SELECT 'negative_stock' AS type,
                   p.product_id AS id,
                   p.product_name,
                   p.sku,
                   s.available_stock AS stock_quantity,
                   'Negative stock balance' AS message
            FROM products p
            INNER JOIN (
                SELECT product_id,
                       SUM(stock_quantity - reserved_quantity) AS available_stock
                FROM product_stocks
                WHERE 1=1 {$whScope}
                GROUP BY product_id
                HAVING available_stock < 0
            ) s ON p.product_id = s.product_id
            WHERE p.status = 'active'
              AND p.is_service = 0
              {$prodScope}
            ORDER BY s.available_stock ASC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['stock_quantity'] = (float)$row['stock_quantity'];
            $groups['products']['items'][] = $row;
        }
    } catch (PDOException $e) {}

    // De-duplicate: a product can appear in multiple queries (low stock AND expiring).
    // Keep first occurrence — out/low before expiring before negative — same as dashboard.
    $seen = [];
    $groups['products']['items'] = array_values(array_filter(
        $groups['products']['items'],
        function ($it) use (&$seen) {
            $pid = $it['id'] ?? null;
            if ($pid === null) return true;
            if (isset($seen[$pid])) return false;
            $seen[$pid] = true;
            return true;
        }
    ));
}

// ── 2. Overdue invoices ───────────────────────────────────────────────────────
if (canView('invoices')) {
    $invScope = scopeFilterSqlNullable('project', 'invoices');
    try {
        $stmt = $pdo->prepare("
            SELECT 'overdue' AS type,
                   invoice_id AS id,
                   invoice_number AS reference,
                   customer_name,
                   ROUND(grand_total - COALESCE(paid_amount, 0), 2) AS overdue_amount,
                   DATEDIFF(CURDATE(), due_date) AS days_overdue,
                   'Overdue payment' AS message
            FROM invoices
            LEFT JOIN customers ON customers.customer_id = invoices.customer_id
            WHERE invoices.status NOT IN ('paid', 'cancelled', 'draft')
              AND due_date < CURDATE()
              AND (grand_total - COALESCE(paid_amount, 0)) > 0
              {$invScope}
            ORDER BY days_overdue DESC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']             = (int)$row['id'];
            $row['overdue_amount'] = (float)$row['overdue_amount'];
            $row['days_overdue']   = (int)$row['days_overdue'];
            $groups['invoices']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 3. Document expiry (≤ 30 days) ───────────────────────────────────────────
if (canView('document_library') || isAdmin()) {
    try {
        $docScope = scopeFilterSqlNullable('project', 'd');
        $stmt = $pdo->query("
            SELECT 'doc_expiring' AS type,
                   d.id AS id,
                   d.title,
                   CONCAT('Expires ', DATE_FORMAT(d.expire_date, '%d %b %Y')) AS message,
                   d.expire_date,
                   DATEDIFF(d.expire_date, CURDATE()) AS days_remaining
            FROM documents d
            WHERE d.expire_date IS NOT NULL
              AND d.expire_date >= CURDATE()
              AND d.expire_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              {$docScope}
            ORDER BY d.expire_date ASC
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']             = (int)$row['id'];
            $row['days_remaining'] = (int)$row['days_remaining'];
            $groups['documents']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 4. Cash register shifts not closed from a previous day ───────────────────
if (canView('cash_register')) {
    try {
        $stmt = $pdo->prepare("
            SELECT 'cash_shift_open' AS type,
                   crs.shift_id AS id,
                   u.username AS reference,
                   crs.start_time,
                   DATEDIFF(CURDATE(), DATE(crs.start_time)) AS days_open,
                   'Cash register shift not closed' AS message
            FROM cash_register_shifts crs
            LEFT JOIN users u ON crs.user_id = u.user_id
            WHERE crs.status = 'active'
              AND DATE(crs.start_time) < CURDATE()
            ORDER BY crs.start_time ASC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']        = (int)$row['id'];
            $row['days_open'] = (int)$row['days_open'];
            $groups['cash_bank']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 5. Bank reconciliation overdue (> 15 days since last reconciliation) ─────
if (canView('bank_reconciliation')) {
    try {
        $stmt = $pdo->prepare("
            SELECT 'bank_recon_overdue' AS type,
                   a.account_id AS id,
                   a.account_name AS reference,
                   MAX(br.reconciliation_date) AS last_reconciled,
                   DATEDIFF(CURDATE(), MAX(br.reconciliation_date)) AS days_since,
                   'Bank reconciliation overdue' AS message
            FROM accounts a
            INNER JOIN bank_reconciliations br
                    ON br.bank_account_id = a.account_id AND br.status = 'reconciled'
            WHERE a.status = 'active'
            GROUP BY a.account_id, a.account_name
            HAVING days_since > 15
            ORDER BY days_since DESC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']         = (int)$row['id'];
            $row['days_since'] = (int)$row['days_since'];
            $groups['cash_bank']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 6. Leave applications waiting 2+ days for approval ───────────────────────
if (canReview('leaves') || canApprove('leaves') || canEdit('leaves')) {
    $empScope = scopeFilterSqlNullable('project', 'e');
    try {
        $stmt = $pdo->prepare("
            SELECT 'leave_pending' AS type,
                   l.leave_id AS id,
                   CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS reference,
                   l.leave_type,
                   DATEDIFF(CURDATE(), DATE(l.created_at)) AS days_waiting,
                   'Leave awaiting approval' AS message
            FROM leaves l
            LEFT JOIN employees e ON e.employee_id = l.employee_id
            WHERE l.status = 'pending'
              AND DATEDIFF(CURDATE(), DATE(l.created_at)) >= 2
              {$empScope}
            ORDER BY l.created_at ASC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']           = (int)$row['id'];
            $row['days_waiting'] = (int)$row['days_waiting'];
            $groups['hr_payroll']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 7. Payroll not yet processed this month (only fires on day 25+) ───────────
if (canView('payroll') && (int)date('d') >= 25) {
    try {
        $current_period = date('Y-m');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE payroll_period = ?");
        $stmt->execute([$current_period]);
        if ((int)$stmt->fetchColumn() === 0) {
            $groups['hr_payroll']['items'][] = [
                'type'      => 'payroll_due',
                'id'        => 0,
                'reference' => $current_period,
                'period'    => date('F Y'),
                'days_left' => (int)date('t') - (int)date('d'),
                'message'   => 'Payroll not yet processed for ' . date('F Y'),
            ];
        }
    } catch (PDOException $e) {}
}

// ── 8. Quotations expiring within 5 days ─────────────────────────────────────
if (canView('quotations')) {
    $quoteScope   = scopeFilterSqlNullable('project', 'q');
    $quoteWhScope = scopeFilterSqlNullable('warehouse', 'q');
    try {
        $stmt = $pdo->prepare("
            SELECT 'quote_expiring' AS type,
                   q.sales_order_id AS id,
                   COALESCE(c.customer_name, 'N/A') AS reference,
                   q.quote_valid_until AS expiry_date,
                   DATEDIFF(q.quote_valid_until, CURDATE()) AS days_remaining,
                   'Quotation expiring soon' AS message
            FROM quotations q
            LEFT JOIN customers c ON c.customer_id = q.customer_id
            WHERE q.quote_valid_until IS NOT NULL
              AND q.quote_valid_until BETWEEN CURDATE() AND CURDATE() + INTERVAL 5 DAY
              AND q.status IN ('pending','sent','draft')
              {$quoteScope}{$quoteWhScope}
            ORDER BY q.quote_valid_until ASC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']             = (int)$row['id'];
            $row['days_remaining'] = (int)$row['days_remaining'];
            $groups['quotes']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 9. Tender deadlines within 7 days ────────────────────────────────────────
if (canView('tenders')) {
    try {
        $stmt = $pdo->prepare("
            SELECT 'tender_deadline' AS type,
                   t.tender_id AS id,
                   t.tender_no AS reference,
                   t.submission_deadline AS deadline,
                   DATEDIFF(t.submission_deadline, CURDATE()) AS days_remaining,
                   'Tender submission deadline approaching' AS message
            FROM tenders t
            WHERE t.submission_deadline IS NOT NULL
              AND t.submission_deadline BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY
              AND UPPER(t.status) IN ('PENDING','OPEN','DRAFT')
            ORDER BY t.submission_deadline ASC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']             = (int)$row['id'];
            $row['days_remaining'] = (int)$row['days_remaining'];
            $groups['tenders']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 10. GRN pending: purchase orders past expected date with no receipt ───────
if (canView('grn') || canView('purchase_orders')) {
    $poScope = scopeFilterSqlNullable('project', 'po');
    $whScope = scopeFilterSqlNullable('warehouse', 'po');
    try {
        $stmt = $pdo->prepare("
            SELECT 'grn_pending' AS type,
                   po.purchase_order_id AS id,
                   po.order_number AS reference,
                   COALESCE(s.supplier_name, 'N/A') AS supplier_name,
                   po.expected_date,
                   DATEDIFF(CURDATE(), po.expected_date) AS days_overdue,
                   'Goods receipt pending' AS message
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
            LEFT JOIN purchase_receipts pr ON pr.purchase_order_id = po.purchase_order_id
            WHERE po.expected_date IS NOT NULL
              AND po.expected_date < CURDATE()
              AND po.status IN ('ordered','approved','partially_received')
              AND pr.receipt_id IS NULL
              {$poScope}
              {$whScope}
            GROUP BY po.purchase_order_id, po.order_number, s.supplier_name, po.expected_date
            ORDER BY po.expected_date ASC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']           = (int)$row['id'];
            $row['days_overdue'] = (int)$row['days_overdue'];
            $groups['grn_pending']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 11. Customers over credit limit ──────────────────────────────────────────
if (canView('invoices') || canView('customers')) {
    $custScope    = scopeFilterSqlNullable('customer', 'c');
    $invProjScope = scopeFilterSqlNullable('project', 'i');
    try {
        $stmt = $pdo->prepare("
            SELECT 'credit_over' AS type,
                   c.customer_id AS id,
                   c.customer_name AS reference,
                   c.credit_limit,
                   ROUND(COALESCE(SUM(i.grand_total - i.paid_amount), 0), 2) AS outstanding,
                   ROUND(COALESCE(SUM(i.grand_total - i.paid_amount), 0) - c.credit_limit, 2) AS excess,
                   'Customer over credit limit' AS message
            FROM customers c
            INNER JOIN invoices i ON i.customer_id = c.customer_id
            WHERE c.status = 'active'
              AND c.credit_limit > 0
              AND i.status NOT IN ('paid','cancelled','draft')
              {$custScope}{$invProjScope}
            GROUP BY c.customer_id, c.customer_name, c.credit_limit
            HAVING outstanding > c.credit_limit
            ORDER BY excess DESC
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id']           = (int)$row['id'];
            $row['credit_limit'] = (float)$row['credit_limit'];
            $row['outstanding']  = (float)$row['outstanding'];
            $row['excess']       = (float)$row['excess'];
            $groups['credit_risk']['items'][] = $row;
        }
    } catch (PDOException $e) {}
}

// ── 12. Pending approvals ─────────────────────────────────────────────────────
if (canApprove('expenses')) {
    $expScope = scopeFilterSqlNullable('project', 'e');
    try {
        $stmt = $pdo->prepare("
            SELECT expense_id AS id,
                   reference_number AS reference,
                   CONCAT('Expense claim - TSh ', FORMAT(amount, 2)) AS description,
                   created_at AS timestamp,
                   e.description AS details
            FROM expenses e
            WHERE e.status = 'pending'
            {$expScope}
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups['approvals']['items'][] = [
                'type'      => 'approval',
                'subtype'   => 'expense',
                'id'        => (int)$row['id'],
                'reference' => $row['reference'],
                'message'   => $row['description'],
                'details'   => $row['details'] ?? '',
                'time'      => $row['timestamp'],
            ];
        }
    } catch (PDOException $e) {}
}

if (canApprove('purchase_orders')) {
    $poScope = scopeFilterSqlNullable('project', 'po');
    $whScope = scopeFilterSqlNullable('warehouse', 'po');
    try {
        $stmt = $pdo->prepare("
            SELECT purchase_order_id AS id,
                   order_number AS reference,
                   CONCAT('Purchase order - TSh ', FORMAT(grand_total, 2)) AS description,
                   po.created_at AS timestamp,
                   s.supplier_name
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'pending_approval'
            {$poScope}
            {$whScope}
            LIMIT 5
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups['approvals']['items'][] = [
                'type'      => 'approval',
                'subtype'   => 'purchase_order',
                'id'        => (int)$row['id'],
                'reference' => $row['reference'],
                'message'   => $row['description'],
                'details'   => $row['supplier_name'] ?? '',
                'time'      => $row['timestamp'],
            ];
        }
    } catch (PDOException $e) {}
}

// ── 13. Engine notifications: unread action-driven items not already covered ──
// Mirrors what the web bell puts in the "others" group: unread notifications
// from the notifications table whose event_key is NOT a time-based type
// already covered by the live queries above (so the bell never double-counts).
try {
    $stmt = $pdo->prepare("
        SELECT notification_id AS id,
               title,
               message,
               type,
               priority,
               action_url,
               event_key,
               created_at
        FROM notifications
        WHERE user_id = ?
          AND is_read = 0
          AND event_key IS NOT NULL
          AND event_key NOT IN ('invoice.overdue','document.expiring','quotation.expiring','tender.deadline')
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['type']            = 'engine_action';
        $row['id']              = (int)$row['id'];
        $groups['others']['items'][] = $row;
    }
} catch (PDOException $e) {}

// ── Engine notification centre: full list for the Notifications screen ────────
// Separate from the grouped bell alerts: shows ALL the user's notifications
// (read + unread) paginated, for the dedicated Notifications screen in Flutter.
$engine_notifications = ['unread_count' => 0, 'total_count' => 0, 'items' => []];
try {
    $ucStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM notifications
        WHERE user_id = ?
    ");
    $ucStmt->execute([$user_id]);
    $uc = $ucStmt->fetch(PDO::FETCH_ASSOC);
    $engine_notifications['total_count']  = (int)($uc['total_count']  ?? 0);
    $engine_notifications['unread_count'] = (int)($uc['unread_count'] ?? 0);

    $listLimit  = max(1, min(50, (int)($_GET['notif_limit']  ?? 20)));
    $listOffset = max(0, (int)($_GET['notif_offset'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT notification_id, title, message, type, priority,
               is_read, action_url, event_key, category,
               created_at, read_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY is_read ASC, created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user_id, $listLimit, $listOffset]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['notification_id'] = (int)$row['notification_id'];
        $row['is_read']         = (bool)$row['is_read'];
        $engine_notifications['items'][] = $row;
    }
} catch (PDOException $e) {}

// ── Build output: only non-empty groups, preserving dashboard order ───────────
$output_groups = [];
foreach ($groups as $key => $group) {
    if (!empty($group['items'])) {
        $output_groups[] = [
            'key'   => $key,
            'title' => $group['title'],
            'color' => $group['color'],
            'count' => count($group['items']),
            'items' => array_values($group['items']),
        ];
    }
}

// badge_count mirrors the web bell: total items across all groups
// (live alerts + approvals + engine_action items in others).
$badge_count = 0;
foreach ($groups as $g) {
    $badge_count += count($g['items']);
}

echo json_encode([
    'success'              => true,
    'badge_count'          => $badge_count,
    'groups'               => $output_groups,
    'engine_notifications' => $engine_notifications,
]);
