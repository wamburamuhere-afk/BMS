<?php
// scope-audit: skip — dashboard aggregates cross-system KPIs for summary display; per-module scope filtering deferred to Phase G-2
// File: dashboard.php
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/header.php';

// Enforce login
if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}

$user_id = $_SESSION['user_id'];

// Define permissions dynamically based on RBAC
$user_permissions = [
    'can_view_all' => isAdmin(),
    'can_approve_expenses' => isAdmin() || hasPermission('approve_expenses') || canEdit('expenses') || canApprove('expenses') || canReview('expenses'),
    'can_approve_purchases' => isAdmin() || hasPermission('approve_purchase_orders') || canEdit('purchase_orders') || canApprove('purchase_orders') || canReview('purchase_orders'),
    'can_edit_all' => isAdmin()
];

// Determine if user can see ANY approvals
$user_permissions['can_approve'] = $user_permissions['can_approve_expenses'] || $user_permissions['can_approve_purchases'];

// Set time range for dashboard data (default: current month)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$time_range = isset($_GET['time_range']) ? $_GET['time_range'] : 'monthly';

// Get dashboard statistics — always use business stats for the main cards
$dashboard_stats = get_business_stats($pdo, $start_date, $end_date, $user_id, $user_permissions);


// Get recent activities — only query if user can see the widget
$recent_activities = [];
if (canView('audit_logs')) {
    $recent_activities = get_recent_activities($pdo, $user_id, $user_permissions);
}

// Get pending approvals
$pending_approvals = [];
if ($user_permissions['can_approve']) {
    $pending_approvals = get_pending_approvals($pdo, $user_permissions);
}

// Get alerts and notifications
$alerts = get_system_alerts($pdo, $user_id);

// Group notifications by category
$notif_groups = [
    'invoices' => ['title' => 'Invoices & Payments', 'icon' => 'bi-receipt', 'color' => 'warning', 'items' => []],
    'products' => ['title' => 'Inventory & Products', 'icon' => 'bi-box-seam', 'color' => 'danger', 'items' => []],
    'approvals' => ['title' => 'Pending Approvals', 'icon' => 'bi-shield-check', 'color' => 'primary', 'items' => []],
    'cash_bank' => ['title' => 'Cash & Bank Controls', 'icon' => 'bi-bank', 'color' => 'danger', 'items' => []],
    'credit_risk' => ['title' => 'Customers Over Credit Limit', 'icon' => 'bi-exclamation-octagon', 'color' => 'danger', 'items' => []],
    'grn_pending' => ['title' => 'Goods Receipt Pending', 'icon' => 'bi-truck', 'color' => 'warning', 'items' => []],
    'hr_payroll' => ['title' => 'HR & Payroll', 'icon' => 'bi-people-fill', 'color' => 'warning', 'items' => []],
    'quotes_tenders' => ['title' => 'Expiring Quotations & Tenders', 'icon' => 'bi-clock-history', 'color' => 'warning', 'items' => []],
    'documents' => ['title' => 'Document Expiry', 'icon' => 'bi-file-earmark-text', 'color' => 'warning', 'items' => []],
    'others' => ['title' => 'General Notifications', 'icon' => 'bi-bell', 'color' => 'info', 'items' => []]
];

foreach ($alerts as $a) {
    switch ($a['type']) {
        case 'overdue':
            $notif_groups['invoices']['items'][] = $a; break;
        case 'low_stock':
        case 'expiring':
        case 'negative_stock':
            $notif_groups['products']['items'][] = $a; break;
        case 'doc_expiring':
            $notif_groups['documents']['items'][] = $a; break;
        case 'cash_shift_open':
        case 'bank_recon_overdue':
            $notif_groups['cash_bank']['items'][] = $a; break;
        case 'leave_pending':
        case 'payroll_due':
            $notif_groups['hr_payroll']['items'][] = $a; break;
        case 'quote_expiring':
        case 'tender_deadline':
            $notif_groups['quotes_tenders']['items'][] = $a; break;
        case 'grn_pending':
            $notif_groups['grn_pending']['items'][] = $a; break;
        case 'credit_over':
            $notif_groups['credit_risk']['items'][] = $a; break;
        default:
            $notif_groups['others']['items'][] = $a;
    }
}

// Add pending approvals to the groups
if (!empty($pending_approvals)) {
    foreach ($pending_approvals as $p) {
        $notif_groups['approvals']['items'][] = [
            'type' => 'approval',
            'subtype' => $p['type'],
            'id' => $p['id'],
            'reference' => $p['reference'],
            'message' => $p['description'],
            'details' => $p['details'] ?? ($p['supplier_name'] ?? ''),
            'time' => $p['time_ago']
        ];
    }
}

// Filter out empty groups
$active_notif_groups = array_filter($notif_groups, function($group) {
    return !empty($group['items']);
});

// Get warehouses for quick stock adjustment
$warehouses = [];
try {
    $stmt = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name");
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fail silently
}

// Get user-specific metrics
$user_metrics = get_user_metrics($pdo, $user_id, $user_role);

// Helper functions
function get_microfinance_stats($pdo, $start_date, $end_date, $user_id, $permissions) {
    $stats = [];
    
    // Total Loan Portfolio
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_loans,
            SUM(amount) as total_portfolio,
            SUM(balance) as total_outstanding,
            SUM(total_paid) as total_repaid
        FROM loans 
        WHERE status IN ('active', 'approved', 'disbursed')
    ");
    $stmt->execute();
    $stats['loan_portfolio'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Active Loans
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as active_loans,
               SUM(balance) as active_outstanding
        FROM loans 
        WHERE status = 'active'
    ");
    $stmt->execute();
    $stats['active_loans'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Overdue Loans
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as overdue_loans,
               SUM(balance) as overdue_amount
        FROM loans 
        WHERE status = 'active' 
        AND next_payment_date < CURDATE()
    ");
    $stmt->execute();
    $stats['overdue_loans'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Today's Collections
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as payments_today,
               SUM(amount) as amount_collected
        FROM loan_repayments 
        WHERE DATE(payment_date) = CURDATE()
        AND status = 'completed'
    ");
    $stmt->execute();
    $stats['today_collections'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Monthly Disbursements
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as loans_disbursed,
               SUM(amount) as amount_disbursed
        FROM loans 
        WHERE status = 'disbursed'
        AND DATE(disbursement_date) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
    $stats['monthly_disbursements'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Portfolio at Risk
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT SUM(balance) FROM loans WHERE status = 'active' AND next_payment_date < CURDATE()) / 
            NULLIF((SELECT SUM(balance) FROM loans WHERE status = 'active'), 0) * 100 as par_rate
    ");
    $stmt->execute();
    $stats['par_rate'] = $stmt->fetchColumn();
    
    // Loan Applications
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_applications
        FROM loan_applications 
        WHERE status = 'pending'
    ");
    $stmt->execute();
    $stats['pending_applications'] = $stmt->fetchColumn();
    
    // Customer Stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_customers,
            COUNT(CASE WHEN customer_type = 'borrower' THEN 1 END) as borrowers,
            COUNT(CASE WHEN customer_type = 'saver' THEN 1 END) as savers
        FROM customers 
        WHERE status = 'active'
    ");
    $stmt->execute();
    $stats['customers'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $stats;
}

function get_business_stats($pdo, $start_date, $end_date, $user_id, $permissions) {
    // Seed safe defaults so every key always exists even when a query is skipped
    $stats = [
        'sales'            => ['total_sales' => 0, 'total_revenue' => 0, 'avg_sale_value' => 0],
        'today_sales'      => ['today_sales' => 0, 'today_revenue' => 0],
        'pending_invoices' => ['pending_invoices' => 0, 'pending_amount' => 0],
        'overdue_invoices' => ['overdue_invoices' => 0, 'overdue_amount' => 0],
        'purchases'        => ['total_purchases' => 0, 'total_spent' => 0],
        'inventory'        => ['total_products' => 0, 'inventory_value' => 0, 'low_stock_items' => 0],
        'customers'        => ['total_customers' => 0, 'active_customers' => 0, 'new_customers' => 0],
        'expenses'         => ['total_expenses' => 0, 'total_expense_amount' => 0],
        'pos_today'        => ['pos_sales_today' => 0, 'pos_revenue_today' => 0],
    ];

    // ── 1. Invoice / Sales stats ──────────────────────────────────────────────
    // Gate: user must have invoices or reports access
    // Scope: project_id on invoices table (nullable — records with NULL are global)
    if (canView('invoices') || canView('sales_report') || hasReportsAccess()) {
        $invScope = scopeFilterSqlNullable('project', 'invoices');

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_sales,
                   SUM(grand_total) as total_revenue,
                   AVG(grand_total) as avg_sale_value
            FROM invoices
            WHERE status NOT IN ('cancelled', 'draft')
              AND invoice_date BETWEEN :start_date AND :end_date
              {$invScope}
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $stats['sales'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['sales'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as today_sales, SUM(grand_total) as today_revenue
            FROM invoices
            WHERE status = 'paid'
              AND DATE(invoice_date) = CURDATE()
              {$invScope}
        ");
        $stmt->execute();
        $stats['today_sales'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['today_sales'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_invoices,
                   SUM(grand_total - COALESCE(paid_amount, 0)) as pending_amount
            FROM invoices
            WHERE status IN ('pending', 'approved', 'sent', 'partial')
              AND due_date >= CURDATE()
              {$invScope}
        ");
        $stmt->execute();
        $stats['pending_invoices'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['pending_invoices'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as overdue_invoices,
                   SUM(grand_total - COALESCE(paid_amount, 0)) as overdue_amount
            FROM invoices
            WHERE status IN ('pending', 'approved', 'sent', 'partial')
              AND due_date < CURDATE()
              {$invScope}
        ");
        $stmt->execute();
        $stats['overdue_invoices'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['overdue_invoices'];
    }

    // ── 2. Purchase stats ─────────────────────────────────────────────────────
    // Gate: purchase_orders module; scope: po.project_id (nullable)
    if (canView('purchase_orders')) {
        $poScope = scopeFilterSqlNullable('project', 'purchase_orders');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_purchases, SUM(grand_total) as total_spent
            FROM purchase_orders
            WHERE status = 'received'
              AND order_date BETWEEN :start_date AND :end_date
              {$poScope}
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $stats['purchases'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['purchases'];
    }

    // ── 3. Inventory value ────────────────────────────────────────────────────
    // Gate: products module; scope: p.project_id (nullable)
    if (canView('products') || canView('inventory_report')) {
        $prodScope = scopeFilterSqlNullable('project', 'p');
        $stmt = $pdo->prepare("
            SELECT COUNT(p.product_id) as total_products,
                   SUM(COALESCE(s.total_stock, 0) * p.cost_price) as inventory_value,
                   SUM(CASE WHEN COALESCE(s.available_stock, 0) <= p.min_stock_level
                                 AND p.min_stock_level > 0 THEN 1 ELSE 0 END) as low_stock_items
            FROM products p
            LEFT JOIN (
                SELECT product_id,
                       SUM(stock_quantity) as total_stock,
                       SUM(stock_quantity - reserved_quantity) as available_stock
                FROM product_stocks
                GROUP BY product_id
            ) s ON p.product_id = s.product_id
            WHERE p.status = 'active'
              AND p.is_service = 0
              {$prodScope}
        ");
        $stmt->execute();
        $stats['inventory'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['inventory'];
    }

    // ── 4. Customer stats ─────────────────────────────────────────────────────
    // Gate: customers module; scope: customer scope list (customer_id IN ...)
    if (canView('customers')) {
        $custScope = scopeFilterSqlNullable('customer', 'c');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_customers,
                   COUNT(CASE WHEN c.status = 'active' THEN 1 END) as active_customers,
                   COUNT(CASE WHEN c.created_at BETWEEN :start_date AND :end_date THEN 1 END) as new_customers
            FROM customers c
            WHERE 1=1
              {$custScope}
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $stats['customers'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['customers'];
    }

    // ── 5. Expense stats ──────────────────────────────────────────────────────
    // Gate: expenses module; scope: e.project_id (nullable)
    if (canView('expenses')) {
        $expScope = scopeFilterSqlNullable('project', 'e');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_expenses, SUM(e.amount) as total_expense_amount
            FROM expenses e
            WHERE e.expense_date BETWEEN :start_date AND :end_date
              AND e.status IN ('approved', 'paid')
              {$expScope}
        ");
        $stmt->execute(['start_date' => $start_date, 'end_date' => $end_date]);
        $stats['expenses'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['expenses'];
    }

    // ── 6. POS sales today ────────────────────────────────────────────────────
    // Gate: pos module; no project scope — POS is a shared point-of-sale terminal
    if (canView('pos')) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pos_sales_today, SUM(grand_total) as pos_revenue_today
            FROM pos_sales
            WHERE DATE(sale_date) = CURDATE()
              AND sale_status = 'completed'
        ");
        $stmt->execute();
        $stats['pos_today'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats['pos_today'];
    }

    return $stats;
}

function get_recent_activities($pdo, $user_id, $permissions) {
    $activities = [];
    
    // Query activity_logs table
    $sql = "
        SELECT 
            id as id,
            action as type,
            description,
            activity_logs.created_at as timestamp,
            ip_address as reference,
            u.username as user_name
        FROM activity_logs 
        LEFT JOIN users u ON activity_logs.user_id = u.user_id
    ";

    // Admins and any role with audit_logs access see all; others see only their own
    $see_all = $permissions['can_view_all'] || canView('audit_logs');
    if (!$see_all) {
        $sql .= " WHERE activity_logs.user_id = :user_id ";
    }

    $sql .= " ORDER BY activity_logs.created_at DESC LIMIT 10";

    $stmt = $pdo->prepare($sql);

    if (!$see_all) {
        $stmt->execute(['user_id' => $user_id]);
    } else {
        $stmt->execute();
    }
    
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format timestamps
    foreach ($activities as &$activity) {
        $activity['time_ago'] = time_ago($activity['timestamp']);
    }
    
    return $activities;
}

function get_pending_approvals($pdo, $permissions = []) {
    $approvals = [];
    

    
    // Get pending expenses if they have permission
    if ($permissions['can_approve_expenses'] ?? false) {
        $expScope = scopeFilterSqlNullable('project', 'e');
        $stmt = $pdo->prepare("
            SELECT
                'expense' as type,
                expense_id as id,
                reference_number as reference,
                CONCAT('Expense claim - TSh ', FORMAT(amount, 2)) as description,
                created_at as timestamp,
                e.description as details
            FROM expenses e
            WHERE e.status = 'pending'
            {$expScope}
            LIMIT 5
        ");
        $stmt->execute();
        $expense_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $approvals = array_merge($approvals, $expense_approvals);
    }
    
    // Get pending purchase orders if they have permission
    if ($permissions['can_approve_purchases'] ?? false) {
        $poScope = scopeFilterSqlNullable('project', 'po');
        $stmt = $pdo->prepare("
            SELECT
                'purchase' as type,
                purchase_order_id as id,
                order_number as reference,
                CONCAT('Purchase order - TSh ', FORMAT(grand_total, 2)) as description,
                po.created_at as timestamp,
                s.supplier_name
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status = 'pending_approval'
            {$poScope}
            LIMIT 5
        ");
        $stmt->execute();
        $purchase_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $approvals = array_merge($approvals, $purchase_approvals);
    }
	$loan_approvals=[];
    $approvals = array_merge($loan_approvals, $approvals);
    
    // Sort by timestamp
    usort($approvals, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // Limit to 10 items
    $approvals = array_slice($approvals, 0, 10);
    
    // Format timestamps
    foreach ($approvals as &$approval) {
        $approval['time_ago'] = time_ago($approval['timestamp']);
    }
    
    return $approvals;
}

function get_system_alerts($pdo, $user_id) {
    $alerts = [];

    // ── 1. Inventory: low stock, negative stock, expiring products ────────────
    // Gate: only fetch if user can see products module
    $stock_alerts = $expiry_alerts = $negative_stock_alerts = [];
    if (canView('products')) {
        $prodScope = scopeFilterSqlNullable('project', 'p');

        $stmt = $pdo->prepare("
            SELECT 'low_stock' as type,
                   p.product_id as id,
                   p.product_name,
                   p.sku,
                   COALESCE(s.available_stock, 0) as stock_quantity,
                   p.min_stock_level AS reorder_level,
                   CASE WHEN COALESCE(s.available_stock, 0) <= 0 THEN 'Out of stock'
                        ELSE 'Low stock alert' END as message
            FROM products p
            LEFT JOIN (
                SELECT product_id, SUM(stock_quantity - reserved_quantity) as available_stock
                FROM product_stocks GROUP BY product_id
            ) s ON p.product_id = s.product_id
            WHERE p.status = 'active'
              AND ((COALESCE(s.available_stock, 0) <= p.min_stock_level AND p.min_stock_level > 0)
                OR (COALESCE(s.available_stock, 0) <= 0))
              {$prodScope}
            ORDER BY COALESCE(s.available_stock, 0) ASC
            LIMIT 10
        ");
        $stmt->execute();
        $stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT 'expiring' as type,
                   p.product_id as id,
                   p.product_name,
                   p.sku,
                   p.expiry_date,
                   DATEDIFF(p.expiry_date, CURDATE()) as days_remaining,
                   'Product expiring soon' as message
            FROM products p
            WHERE p.expiry_date IS NOT NULL
              AND p.expiry_date > CURDATE()
              AND DATEDIFF(p.expiry_date, CURDATE()) <= 30
              AND p.status = 'active'
              {$prodScope}
            LIMIT 5
        ");
        $stmt->execute();
        $expiry_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $stmt = $pdo->prepare("
                SELECT 'negative_stock' as type,
                       p.product_id as id,
                       p.product_name,
                       p.sku,
                       s.available_stock as stock_quantity,
                       'Negative stock balance' as message
                FROM products p
                INNER JOIN (
                    SELECT product_id, SUM(stock_quantity - reserved_quantity) as available_stock
                    FROM product_stocks GROUP BY product_id
                    HAVING available_stock < 0
                ) s ON p.product_id = s.product_id
                WHERE p.status = 'active'
                  {$prodScope}
                ORDER BY s.available_stock ASC
                LIMIT 5
            ");
            $stmt->execute();
            $negative_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 2. Overdue invoices ───────────────────────────────────────────────────
    // Gate: only fetch if user can see invoices
    $overdue_alerts = [];
    if (canView('invoices')) {
        $invScope = scopeFilterSqlNullable('project', 'invoices');
        $stmt = $pdo->prepare("
            SELECT 'overdue' as type,
                   invoice_id as id,
                   invoice_number as reference,
                   customer_name,
                   grand_total - paid_amount as overdue_amount,
                   DATEDIFF(CURDATE(), due_date) as days_overdue,
                   'Overdue payment' as message
            FROM invoices
            LEFT JOIN customers ON customers.customer_id = invoices.customer_id
            WHERE invoices.status IN ('sent', 'partial', 'pending', 'approved')
              AND due_date < CURDATE()
              AND (grand_total - paid_amount) > 0
              {$invScope}
            LIMIT 5
        ");
        $stmt->execute();
        $overdue_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── 3. Document expiry — already personal via user_id, no project scope ──
    $doc_alerts = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 'doc_expiring' as type,
                   document_id as id,
                   title,
                   message,
                   action_url,
                   created_at
            FROM notifications
            WHERE user_id = ?
              AND type = 'alert'
              AND document_id IS NOT NULL
              AND is_read = 0
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $doc_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── 4. Cash register shifts left open ────────────────────────────────────
    // Gate: finance/admin only — no project scope (company-wide control)
    $cash_shift_alerts = [];
    if (canView('cash_register')) {
        try {
            $stmt = $pdo->prepare("
                SELECT 'cash_shift_open' as type,
                       crs.shift_id as id,
                       u.username as reference,
                       crs.start_time,
                       DATEDIFF(CURDATE(), DATE(crs.start_time)) as days_open,
                       'Cash register shift not closed' as message
                FROM cash_register_shifts crs
                LEFT JOIN users u ON crs.user_id = u.user_id
                WHERE crs.status = 'active'
                  AND DATE(crs.start_time) < CURDATE()
                ORDER BY crs.start_time ASC
                LIMIT 5
            ");
            $stmt->execute();
            $cash_shift_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 5. Bank reconciliation overdue ───────────────────────────────────────
    // Gate: finance/admin only — no project scope (company-wide control)
    $bank_recon_alerts = [];
    if (canView('bank_reconciliation')) {
        try {
            $stmt = $pdo->prepare("
                SELECT 'bank_recon_overdue' as type,
                       a.account_id as id,
                       a.account_name as reference,
                       MAX(br.reconciliation_date) as last_reconciled,
                       DATEDIFF(CURDATE(), MAX(br.reconciliation_date)) as days_since,
                       'Bank reconciliation overdue' as message
                FROM accounts a
                INNER JOIN bank_reconciliations br ON br.bank_account_id = a.account_id AND br.status = 'reconciled'
                WHERE a.status = 'active'
                GROUP BY a.account_id, a.account_name
                HAVING days_since > 15
                ORDER BY days_since DESC
                LIMIT 5
            ");
            $stmt->execute();
            $bank_recon_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 6. Pending leave applications ────────────────────────────────────────
    // Gate: only approvers/reviewers see this; scoped to their project's employees
    $leave_alerts = [];
    if (canReview('leaves') || canApprove('leaves') || canEdit('leaves')) {
        // employees alias 'e' has project_id — scope via project type on that alias
        $empScope = scopeFilterSqlNullable('project', 'e');
        try {
            $stmt = $pdo->prepare("
                SELECT 'leave_pending' as type,
                       l.leave_id as id,
                       CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) as reference,
                       l.leave_type,
                       DATEDIFF(CURDATE(), DATE(l.created_at)) as days_waiting,
                       'Leave awaiting approval' as message
                FROM leaves l
                LEFT JOIN employees e ON e.employee_id = l.employee_id
                WHERE l.status = 'pending'
                  AND DATEDIFF(CURDATE(), DATE(l.created_at)) >= 2
                  {$empScope}
                ORDER BY l.created_at ASC
                LIMIT 5
            ");
            $stmt->execute();
            $leave_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 7. Payroll not processed ──────────────────────────────────────────────
    // Gate: payroll module access only — company-wide flag, no project scope
    $payroll_alerts = [];
    if (canView('payroll') && (int)date('d') >= 25) {
        try {
            $current_period = date('Y-m');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE payroll_period = ?");
            $stmt->execute([$current_period]);
            if ((int)$stmt->fetchColumn() === 0) {
                $payroll_alerts[] = [
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

    // ── 8. Quotations expiring within 5 days ─────────────────────────────────
    // Gate: quotations module access; scoped to user's projects
    $quote_alerts = [];
    if (canView('quotations')) {
        $quoteScope = scopeFilterSqlNullable('project', 'q');
        try {
            $stmt = $pdo->prepare("
                SELECT 'quote_expiring' as type,
                       q.sales_order_id as id,
                       COALESCE(c.customer_name, 'N/A') as reference,
                       q.quote_valid_until as expiry_date,
                       DATEDIFF(q.quote_valid_until, CURDATE()) as days_remaining,
                       'Quotation expiring soon' as message
                FROM quotations q
                LEFT JOIN customers c ON c.customer_id = q.customer_id
                WHERE q.quote_valid_until IS NOT NULL
                  AND q.quote_valid_until BETWEEN CURDATE() AND CURDATE() + INTERVAL 5 DAY
                  AND q.status IN ('pending','sent','draft')
                  {$quoteScope}
                ORDER BY q.quote_valid_until ASC
                LIMIT 5
            ");
            $stmt->execute();
            $quote_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 9. Tender deadlines within 7 days ────────────────────────────────────
    // Gate: tenders module access; tenders have no project_id column — gate only
    $tender_alerts = [];
    if (canView('tenders')) {
        try {
            $stmt = $pdo->prepare("
                SELECT 'tender_deadline' as type,
                       t.tender_id as id,
                       t.tender_no as reference,
                       t.submission_deadline as deadline,
                       DATEDIFF(t.submission_deadline, CURDATE()) as days_remaining,
                       'Tender submission deadline approaching' as message
                FROM tenders t
                WHERE t.submission_deadline IS NOT NULL
                  AND t.submission_deadline BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY
                  AND UPPER(t.status) IN ('PENDING','OPEN','DRAFT')
                ORDER BY t.submission_deadline ASC
                LIMIT 5
            ");
            $stmt->execute();
            $tender_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 10. GRN pending for overdue purchase orders ───────────────────────────
    // Gate: GRN module access; scoped via po.project_id
    $grn_pending_alerts = [];
    if (canView('grn') || canView('purchase_orders')) {
        $poScope = scopeFilterSqlNullable('project', 'po');
        try {
            $stmt = $pdo->prepare("
                SELECT 'grn_pending' as type,
                       po.purchase_order_id as id,
                       po.order_number as reference,
                       COALESCE(s.supplier_name, 'N/A') as supplier_name,
                       po.expected_date,
                       DATEDIFF(CURDATE(), po.expected_date) as days_overdue,
                       'Goods receipt pending' as message
                FROM purchase_orders po
                LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
                LEFT JOIN purchase_receipts pr ON pr.purchase_order_id = po.purchase_order_id
                WHERE po.expected_date IS NOT NULL
                  AND po.expected_date < CURDATE()
                  AND po.status IN ('ordered','approved','partially_received')
                  AND pr.receipt_id IS NULL
                  {$poScope}
                GROUP BY po.purchase_order_id, po.order_number, s.supplier_name, po.expected_date
                ORDER BY po.expected_date ASC
                LIMIT 5
            ");
            $stmt->execute();
            $grn_pending_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── 11. Customers over credit limit ──────────────────────────────────────
    // Gate: invoices or customers module access; scoped by customer scope list
    $credit_over_alerts = [];
    if (canView('invoices') || canView('customers')) {
        $custScope = scopeFilterSqlNullable('customer', 'c');
        try {
            $stmt = $pdo->prepare("
                SELECT 'credit_over' as type,
                       c.customer_id as id,
                       c.customer_name as reference,
                       c.credit_limit,
                       COALESCE(SUM(i.grand_total - i.paid_amount), 0) as outstanding,
                       (COALESCE(SUM(i.grand_total - i.paid_amount), 0) - c.credit_limit) as excess,
                       'Customer over credit limit' as message
                FROM customers c
                INNER JOIN invoices i ON i.customer_id = c.customer_id
                WHERE c.status = 'active'
                  AND c.credit_limit > 0
                  AND i.status IN ('sent','partial','pending','approved')
                  {$custScope}
                GROUP BY c.customer_id, c.customer_name, c.credit_limit
                HAVING outstanding > c.credit_limit
                ORDER BY excess DESC
                LIMIT 5
            ");
            $stmt->execute();
            $credit_over_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    $alerts = array_merge(
        $stock_alerts,
        $overdue_alerts,
        $expiry_alerts,
        $doc_alerts,
        $negative_stock_alerts,
        $cash_shift_alerts,
        $bank_recon_alerts,
        $leave_alerts,
        $payroll_alerts,
        $quote_alerts,
        $tender_alerts,
        $grn_pending_alerts,
        $credit_over_alerts
    );

    // Sort by urgency
    usort($alerts, function($a, $b) {
        $priority = [
            'negative_stock'      => 5,
            'cash_shift_open'     => 4,
            'credit_over'         => 4,
            'bank_recon_overdue'  => 3,
            'low_stock'           => 3,
            'grn_pending'         => 3,
            'overdue'             => 2,
            'doc_expiring'        => 2,
            'payroll_due'         => 2,
            'tender_deadline'     => 2,
            'leave_pending'       => 2,
            'quote_expiring'      => 1,
            'expiring'            => 1,
        ];
        return ($priority[$b['type']] ?? 0) - ($priority[$a['type']] ?? 0);
    });

    return array_slice($alerts, 0, 50);
}

function get_user_metrics($pdo, $user_id, $user_role) {
    $metrics = [];
    
    if ($user_role == 'Sales') {
        // Salesperson metrics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_sales,
                SUM(grand_total) as total_revenue,
                AVG(grand_total) as avg_sale_value,
                MAX(grand_total) as largest_sale
            FROM invoices 
            WHERE created_by = :user_id
            AND status = 'paid'
            AND MONTH(invoice_date) = MONTH(CURDATE())
            AND YEAR(invoice_date) = YEAR(CURDATE())
        ");
        $stmt->execute(['user_id' => $user_id]);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Monthly target achievement (assuming target is 1,000,000)
        $metrics['monthly_target'] = 1000000;
        $metrics['target_achievement'] = $metrics['total_revenue'] ? 
            round(($metrics['total_revenue'] / $metrics['monthly_target']) * 100, 2) : 0;
            
    } elseif ($user_role == 'Cashier') {
        // Cashier metrics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(grand_total) as total_cash_handled,
                AVG(grand_total) as avg_transaction
            FROM pos_sales 
            WHERE user_id = :user_id
            AND sale_status = 'completed'
            AND DATE(sale_date) = CURDATE()
        ");
        $stmt->execute(['user_id' => $user_id]);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $metrics;
}

function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}



function get_progress_color($percentage) {
    if ($percentage >= 80) return 'success';
    if ($percentage >= 50) return 'warning';
    return 'danger';
}
?>
  <!-- Dashboard Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                <div>
                    <h2 class="mb-1"><i class="bi bi-speedometer2"></i> Dashboard</h2>
                    <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($username) ?>! 
                        <span class="badge bg-primary"><?= $user_role ?></span>
                    </p>
                </div>
                <div class="d-flex flex-row gap-2 w-100 w-md-auto justify-content-between justify-content-md-end ms-md-auto">
                    <!-- Date Range Selector -->
                    <div class="dropdown flex-fill flex-md-grow-0">
                        <button class="btn btn-outline-primary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-calendar"></i> 
                            <?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="?time_range=today">Today</a></li>
                            <li><a class="dropdown-item" href="?time_range=yesterday">Yesterday</a></li>
                            <li><a class="dropdown-item" href="?time_range=week">This Week</a></li>
                            <li><a class="dropdown-item" href="?time_range=month">This Month</a></li>
                            <li><a class="dropdown-item" href="?time_range=quarter">This Quarter</a></li>
                            <li><a class="dropdown-item" href="?time_range=year">This Year</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="GET" class="px-3 py-2">
                                    <div class="mb-2">
                                        <label class="form-label small">Custom Range</label>
                                        <input type="date" class="form-control form-control-sm mb-2" name="start_date" value="<?= $start_date ?>">
                                        <input type="date" class="form-control form-control-sm" name="end_date" value="<?= $end_date ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="dropdown flex-fill flex-md-grow-0">
                        <button class="btn btn-primary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-lightning"></i> Quick Actions
                        </button>
                        <ul class="dropdown-menu">
                            <?php if ($company_type != 'microfinance'): ?>
                                <?php if(canView('pos')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('pos') ?>"><i class="bi bi-cart-check"></i> POS</a></li>
                                <?php endif; ?>
                                <?php if(canCreate('invoices')): ?>
                                <li><a class="dropdown-item" href="invoice_create"><i class="bi bi-receipt"></i> Create Invoice</a></li>
                                <?php endif; ?>
                                <?php if(canView('sales_orders')): ?>
                                <li><a class="dropdown-item" href="sales_orders"><i class="bi bi-cart"></i> Sales Order</a></li>
                                <?php endif; ?>
                                <?php if(canView('pos') || canCreate('invoices') || canView('sales_orders')): ?>
                                <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if(canCreate('customers')): ?>
                            <li><a class="dropdown-item" href="<?= getUrl('customers') ?>?action=add"><i class="bi bi-person-plus"></i> Add Customer</a></li>
                            <?php endif; ?>
                            <?php if(canCreate('products')): ?>
                            <li><a class="dropdown-item" href="<?= getUrl('product_create') ?>"><i class="bi bi-plus-circle"></i> Add Product</a></li>
                            <?php endif; ?>
                            <?php if(canCreate('suppliers')): ?>
                            <li><a class="dropdown-item" href="<?= getUrl('suppliers') ?>?action=add"><i class="bi bi-truck"></i> Add Supplier</a></li>
                            <?php endif; ?>
                            
                            <?php if(hasReportsAccess()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="reports"><i class="bi bi-graph-up"></i> View Reports</a></li>
                            <?php endif; ?>

                            <?php if (get_setting('enable_projects') == '1' && canView('projects')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="projects"><i class="bi bi-kanban"></i> Projects Management</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>


<div class="container-fluid mt-4">

    <!-- CRM Overdue Activities Alert -->
    <?php if (canView('crm_dashboard')): ?>
    <div id="crmOverdueAlert" style="display:none" class="row mb-3">
        <div class="col-12">
            <div class="alert alert-warning alert-dismissible fade show py-2 mb-0 d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-clock-history fs-5"></i>
                <span id="crmOverdueText">You have overdue CRM activities.</span>
                <a href="<?= getUrl('crm/dashboard') ?>" class="btn btn-sm btn-warning ms-2">View CRM</a>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
    <script>
    $.getJSON('<?= buildUrl('api/crm/get_dashboard_data.php') ?>?period=this_month', function(r) {
        if (r.success && r.kpi && r.kpi.overdue_activities > 0) {
            $('#crmOverdueText').text('You have ' + r.kpi.overdue_activities + ' overdue CRM ' + (r.kpi.overdue_activities === 1 ? 'activity' : 'activities') + '.');
            $('#crmOverdueAlert').show();
        }
    });
    </script>
    <?php endif; ?>

    <!-- Professional Summary-First Notifications -->
    <?php 
    $total_notifs = count($alerts) + count($pending_approvals);
    if ($total_notifs > 0):
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px; background: linear-gradient(135deg, #fff9e6 0%, #fff 100%); border-left: 5px solid #ffc107 !important;">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="bg-warning-subtle text-warning p-2 rounded-circle me-3 shadow-sm">
                                <i class="bi bi-bell-fill fs-4 pulse-icon"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-dark">System requires your attention</h6>
                                <p class="mb-0 text-muted small">You have <strong><?= $total_notifs ?></strong> pending notifications across <?= count($active_notif_groups) ?> categories.</p>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-warning btn-sm fw-bold px-3 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#detailedNotifications" aria-expanded="false" id="toggleNotifBtn">
                                <i class="bi bi-eye me-1"></i> View Details
                            </button>
                            <button type="button" class="btn-close small" onclick="$(this).closest('.row').fadeOut()"></button>
                        </div>
                    </div>

                    <!-- Collapsible Detailed View -->
                    <div class="collapse mt-3" id="detailedNotifications">
                        <hr class="my-3 opacity-10">
                        <div class="row g-3 px-1">
                            <?php foreach ($active_notif_groups as $key => $group): ?>
                            <div class="col-md-4">
                                <div class="h-100 bg-white rounded-3 border border-light-subtle p-3 shadow-sm">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="bg-<?= $group['color'] ?>-subtle text-<?= $group['color'] ?> p-2 rounded-3 me-2" style="width: 38px; height: 38px; display: flex; align-items:center; justify-content:center;">
                                            <i class="bi <?= $group['icon'] ?> fs-5"></i>
                                        </div>
                                        <h6 class="mb-0 fw-bold small text-uppercase" style="letter-spacing: 0.5px;"><?= $group['title'] ?></h6>
                                        <span class="badge bg-<?= $group['color'] ?> rounded-pill ms-auto"><?= count($group['items']) ?></span>
                                    </div>
                                    
                                    <div class="notification-list custom-scrollbar" style="max-height: 220px; overflow-y: auto; overflow-x: hidden;">
                                        <?php foreach ($group['items'] as $item): ?>
                                        <div class="p-2 mb-2 rounded-2 border-bottom border-light-subtle position-relative action-row">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div style="flex: 1; padding-right: 10px;">
                                                    <?php if ($key === 'approvals'): ?>
                                                        <h6 class="small mb-1 fw-bold text-dark"><?= htmlspecialchars((string)$item['message']) ?></h6>
                                                        <div class="d-flex justify-content-between">
                                                            <small class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-hash me-1"></i><?= htmlspecialchars((string)$item['reference']) ?></small>
                                                            <small class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= $item['time'] ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <h6 class="small mb-1 fw-bold <?= ($item['type'] == 'low_stock' && $item['stock_quantity'] <= 0) ? 'text-danger' : ($item['type'] == 'negative_stock' ? 'text-danger' : 'text-dark') ?>">
                                                            <?php if ($item['type'] == 'low_stock'): ?>
                                                                <?= $item['stock_quantity'] <= 0 ? '<span class="badge bg-danger p-1 me-1">OUT</span>' : '<span class="badge bg-warning text-dark p-1 me-1">LOW</span>' ?>
                                                                <?= htmlspecialchars((string)$item['product_name']) ?>
                                                            <?php elseif ($item['type'] == 'negative_stock'): ?>
                                                                <span class="badge bg-danger p-1 me-1">NEG</span><?= htmlspecialchars((string)$item['product_name']) ?>
                                                            <?php elseif ($item['type'] == 'overdue'): ?>
                                                                Overdue: <?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php elseif ($item['type'] == 'expiring'): ?>
                                                                Expiry: <?= htmlspecialchars((string)$item['product_name']) ?>
                                                            <?php elseif ($item['type'] == 'doc_expiring'): ?>
                                                                <i class="bi bi-file-earmark-text me-1"></i><?= htmlspecialchars((string)$item['title']) ?>
                                                            <?php elseif ($item['type'] == 'cash_shift_open'): ?>
                                                                <i class="bi bi-cash-register me-1"></i>Shift open: <?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php elseif ($item['type'] == 'bank_recon_overdue'): ?>
                                                                <i class="bi bi-bank me-1"></i><?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php elseif ($item['type'] == 'leave_pending'): ?>
                                                                <i class="bi bi-person-badge me-1"></i>Leave: <?= htmlspecialchars(trim((string)$item['reference']) ?: 'Employee') ?>
                                                            <?php elseif ($item['type'] == 'payroll_due'): ?>
                                                                <i class="bi bi-calendar-month me-1"></i>Payroll: <?= htmlspecialchars((string)$item['period']) ?>
                                                            <?php elseif ($item['type'] == 'quote_expiring'): ?>
                                                                <i class="bi bi-file-earmark-text me-1"></i>Quote #<?= (int)$item['id'] ?> — <?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php elseif ($item['type'] == 'tender_deadline'): ?>
                                                                <i class="bi bi-clipboard-check me-1"></i>Tender: <?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php elseif ($item['type'] == 'grn_pending'): ?>
                                                                <i class="bi bi-truck me-1"></i>PO: <?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php elseif ($item['type'] == 'credit_over'): ?>
                                                                <i class="bi bi-exclamation-octagon me-1"></i><?= htmlspecialchars((string)$item['reference']) ?>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <small class="text-muted d-block" style="font-size: 0.7rem;">
                                                            <?php if ($item['type'] == 'low_stock'): ?>
                                                                Available: <span class="fw-bold"><?= $item['stock_quantity'] ?></span> | Reorder: <?= $item['reorder_level'] ?>
                                                            <?php elseif ($item['type'] == 'negative_stock'): ?>
                                                                Balance: <span class="text-danger fw-bold"><?= $item['stock_quantity'] ?></span> | SKU: <?= htmlspecialchars((string)$item['sku']) ?>
                                                            <?php elseif ($item['type'] == 'overdue'): ?>
                                                                Due: <span class="text-danger fw-bold"><?= format_currency($item['overdue_amount']) ?></span>
                                                            <?php elseif ($item['type'] == 'expiring'): ?>
                                                                Exp: <?= $item['expiry_date'] ?> (<?= $item['days_remaining'] ?>d left)
                                                            <?php elseif ($item['type'] == 'doc_expiring'): ?>
                                                                <?= htmlspecialchars((string)$item['message']) ?>
                                                            <?php elseif ($item['type'] == 'cash_shift_open'): ?>
                                                                Open <span class="text-danger fw-bold"><?= (int)$item['days_open'] ?>d</span> · started <?= date('M d, H:i', strtotime($item['start_time'])) ?>
                                                            <?php elseif ($item['type'] == 'bank_recon_overdue'): ?>
                                                                <span class="text-danger fw-bold"><?= (int)$item['days_since'] ?>d</span> since last reconciliation
                                                            <?php elseif ($item['type'] == 'leave_pending'): ?>
                                                                <?= htmlspecialchars((string)($item['leave_type'] ?? 'Leave')) ?> · waiting <?= (int)$item['days_waiting'] ?>d
                                                            <?php elseif ($item['type'] == 'payroll_due'): ?>
                                                                Not processed · <?= (int)$item['days_left'] ?>d to month-end
                                                            <?php elseif ($item['type'] == 'quote_expiring'): ?>
                                                                Valid until <?= $item['expiry_date'] ?> (<?= (int)$item['days_remaining'] ?>d left)
                                                            <?php elseif ($item['type'] == 'tender_deadline'): ?>
                                                                Deadline <?= $item['deadline'] ?> (<?= (int)$item['days_remaining'] ?>d left)
                                                            <?php elseif ($item['type'] == 'grn_pending'): ?>
                                                                <?= htmlspecialchars((string)$item['supplier_name']) ?> · <?= (int)$item['days_overdue'] ?>d overdue
                                                            <?php elseif ($item['type'] == 'credit_over'): ?>
                                                                Owes <span class="text-danger fw-bold"><?= format_currency($item['outstanding']) ?></span> · Limit <?= format_currency($item['credit_limit']) ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <?php if ($key === 'approvals'): ?>
                                                        <?php 
                                                        $link = '#';
                                                        if ($item['subtype'] === 'expense') $link = getUrl('accounts/expense_details') . '?id=' . $item['id'];
                                                        elseif ($item['subtype'] === 'purchase') $link = getUrl('purchase_order_details') . '?id=' . $item['id'];
                                                        ?>
                                                        <a href="<?= $link ?>" class="btn btn-xs btn-light border p-1 py-0 shadow-sm" title="Go to details">
                                                            <i class="bi bi-arrow-right-short fs-5 text-primary"></i>
                                                        </a>
                                                    <?php elseif ($key === 'documents'): ?>
                                                        <a href="<?= htmlspecialchars($item['action_url'] ?? getUrl('document_library')) ?>" class="btn btn-xs btn-light border p-1 py-0 shadow-sm" title="View document library">
                                                            <i class="bi bi-arrow-right-short fs-5 text-warning"></i>
                                                        </a>
                                                    <?php else:
                                                        $nav_link = '';
                                                        switch ($item['type'] ?? '') {
                                                            case 'cash_shift_open':    $nav_link = getUrl('cash_register'); break;
                                                            case 'bank_recon_overdue': $nav_link = getUrl('bank_reconciliation'); break;
                                                            case 'leave_pending':      $nav_link = getUrl('leaves'); break;
                                                            case 'payroll_due':        $nav_link = getUrl('payroll'); break;
                                                            case 'quote_expiring':     $nav_link = getUrl('quotations'); break;
                                                            case 'tender_deadline':    $nav_link = getUrl('tenders'); break;
                                                            case 'grn_pending':        $nav_link = getUrl('purchase_order_details') . '?id=' . (int)$item['id']; break;
                                                            case 'credit_over':        $nav_link = getUrl('customers/details') . '?id=' . (int)$item['id']; break;
                                                            case 'negative_stock':     $nav_link = getUrl('stock_adjustments'); break;
                                                        }
                                                        ?>
                                                        <?php if ($nav_link !== ''): ?>
                                                            <a href="<?= htmlspecialchars($nav_link) ?>" class="btn btn-xs btn-light border p-1 py-0 shadow-sm" title="View details">
                                                                <i class="bi bi-arrow-right-short fs-5 text-<?= $group['color'] ?>"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-xs btn-light border p-1 py-0 shadow-sm" onclick="handleAlertAction(<?= htmlspecialchars(json_encode($item)) ?>)" title="Handle Alert">
                                                                <i class="bi bi-arrow-right-short fs-5 text-<?= $group['color'] ?>"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        $('#detailedNotifications').on('show.bs.collapse', function () {
            $('#toggleNotifBtn').html('<i class="bi bi-eye-slash me-1"></i> Hide Details').removeClass('btn-warning').addClass('btn-outline-warning');
        }).on('hide.bs.collapse', function () {
            $('#toggleNotifBtn').html('<i class="bi bi-eye me-1"></i> View Details').removeClass('btn-outline-warning').addClass('btn-warning');
        });
    </script>
    <style>
        .pulse-icon { animation: pulse-animation 2s infinite; }
        @keyframes pulse-animation { 
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #eee; border-radius: 10px; }
        .action-row:hover { background-color: #fcfcfc; transition: 0.2s; }
        .btn-xs { padding: 0.1rem 0.3rem; font-size: 0.75rem; }
    </style>
    <?php endif; ?>

    <!-- Quick Links Section -->
    <?php
    $ql_has_links = canView('pos') || canCreate('invoices') || canCreate('customers')
                 || canCreate('suppliers') || canCreate('products')
                 || (get_setting('enable_projects') == '1' && canView('projects'));
    ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-link-45deg"></i> Quick Links</h6>
                </div>
                <div class="card-body">
                    <?php if ($ql_has_links): ?>
                    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-6 g-3">
                        <?php if (canView('pos')): ?>
                        <div class="col">
                            <a href="pos" class="btn btn-outline-primary w-100 h-100 py-3">
                                <i class="bi bi-cart-check display-6"></i>
                                <div class="mt-2">POS</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (canCreate('invoices')): ?>
                        <div class="col">
                            <a href="invoice_create" class="btn btn-outline-success w-100 h-100 py-3">
                                <i class="bi bi-receipt display-6"></i>
                                <div class="mt-2">Create Invoice</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (canCreate('customers')): ?>
                        <div class="col">
                            <a href="<?= getUrl('customers') ?>?action=add" class="btn btn-outline-info w-100 h-100 py-3">
                                <i class="bi bi-person-plus display-6"></i>
                                <div class="mt-2">Add Customer</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (canCreate('suppliers')): ?>
                        <div class="col">
                            <a href="<?= getUrl('suppliers') ?>?action=add" class="btn btn-outline-secondary w-100 h-100 py-3">
                                <i class="bi bi-truck display-6"></i>
                                <div class="mt-2">Add Supplier</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (canCreate('products')): ?>
                        <div class="col">
                            <a href="<?= getUrl('product_create') ?>" class="btn btn-outline-warning w-100 h-100 py-3">
                                <i class="bi bi-plus-circle display-6"></i>
                                <div class="mt-2">Add Product</div>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (get_setting('enable_projects') == '1' && canView('projects')): ?>
                        <div class="col">
                            <a href="projects" class="btn btn-outline-dark w-100 h-100 py-3">
                                <i class="bi bi-briefcase display-6"></i>
                                <div class="mt-2">Projects Management</div>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-lock fs-2 d-block mb-2"></i>
                        No quick actions available for your role.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
  
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <link rel="stylesheet" href="style.css">

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <!-- 1. Monthly Revenue -->
        <?php if(canView('invoices') || canView('sales_report') || hasReportsAccess()): ?>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($dashboard_stats['sales']['total_revenue'] ?? 0) ?></h4>
                            <p class="mb-0">Monthly Revenue</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small>
                            <i class="bi bi-receipt"></i>
                            <?= $dashboard_stats['sales']['total_sales'] ?? 0 ?> Sales this month
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 2. Today's POS Sales -->
        <?php if(canView('pos')): ?>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($dashboard_stats['pos_today']['pos_revenue_today'] ?? 0) ?></h4>
                            <p class="mb-0">Today's POS Sales</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cart-check" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small>
                            <i class="bi bi-cart-check"></i>
                            <?= $dashboard_stats['pos_today']['pos_sales_today'] ?? 0 ?> Transactions today
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 3. Overdue Invoices -->
        <?php if(canView('invoices')): ?>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($dashboard_stats['overdue_invoices']['overdue_amount'] ?? 0) ?></h4>
                            <p class="mb-0">Overdue Invoices</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small>
                            <i class="bi bi-clock-history"></i>
                            <?= $dashboard_stats['overdue_invoices']['overdue_invoices'] ?? 0 ?> Invoices overdue
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 4. Inventory Value -->
        <?php if(canView('products') || canView('inventory_report')): ?>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($dashboard_stats['inventory']['inventory_value'] ?? 0) ?></h4>
                            <p class="mb-0">Inventory Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-boxes" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small>
                            <i class="bi bi-box"></i>
                            <?= $dashboard_stats['inventory']['total_products'] ?? 0 ?> Products in stock
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Content Area -->
    <?php 
    $show_sidebar = canView('audit_logs') || !empty($user_metrics);
    $main_col_class = $show_sidebar ? 'col-lg-8' : 'col-lg-12';
    ?>
    <div class="row">
        <!-- Left Column: Charts and Metrics -->
        <div class="<?= $main_col_class ?> mb-4">
            <!-- Performance Chart -->
            <?php if (hasReportsAccess()): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart-line text-primary me-2"></i> Performance Overview</h6>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm w-auto border-0 bg-light" id="chartPeriod">
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                        <button class="btn btn-sm btn-light border-0" onclick="loadPerformanceChart($('#chartPeriod').val())">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 320px; width: 100%;">
                        <canvas id="performanceChart"></canvas>
                        <div id="chartLoader" class="text-center py-5 position-absolute top-50 start-50 translate-middle w-100" style="display:none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading chart...</span>
                            </div>
                            <p class="mt-2 text-muted small">Loading performance data...</p>
                        </div>
                    </div>
                    <div id="performanceSummary"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Stats Row -->
            <div class="row">
                <?php if(canView('customers')): ?>
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-people text-success me-2"></i> Customer Overview</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6">
                                    <h3 class="fw-bold"><?= $dashboard_stats['customers']['total_customers'] ?? 0 ?></h3>
                                    <small class="text-muted text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.05em;">Total Customers</small>
                                </div>
                                <div class="col-6 border-start">
                                    <h3 class="fw-bold text-success"><?= $dashboard_stats['customers']['active_customers'] ?? 0 ?></h3>
                                    <small class="text-muted text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.05em;">Active</small>
                                </div>
                            </div>
                            <?php
                            $total_customers = max(1, $dashboard_stats['customers']['total_customers'] ?? 0);
                            $active_percentage = min(100, ($dashboard_stats['customers']['active_customers'] ?? 0) / $total_customers * 100);
                            ?>
                            <div class="mt-4">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Retention Rate</small>
                                    <small class="fw-bold"><?= round($active_percentage) ?>%</small>
                                </div>
                                <div class="progress" style="height: 6px; border-radius: 10px;">
                                    <div class="progress-bar bg-success" style="width: <?= $active_percentage ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if(canView('products') || canView('inventory_report')): ?>
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-box text-warning me-2"></i> Inventory Status</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center mb-4">
                                <div class="col-6">
                                    <h3 class="fw-bold"><?= $dashboard_stats['inventory']['total_products'] ?? 0 ?></h3>
                                    <small class="text-muted text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.05em;">Total Products</small>
                                </div>
                                <div class="col-6 border-start">
                                    <h3 class="fw-bold <?= ($dashboard_stats['inventory']['low_stock_items'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= $dashboard_stats['inventory']['low_stock_items'] ?? 0 ?>
                                    </h3>
                                    <small class="text-muted text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.05em;">Low Stock</small>
                                </div>
                            </div>
                            <?php if(canView('products')): ?>
                            <div class="mt-auto">
                                <a href="<?= getUrl('products') ?>?filter=low_stock" class="btn btn-sm btn-outline-danger w-100 border-2 fw-bold">
                                    <i class="bi bi-exclamation-triangle me-1"></i> View Inventory Alerts
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Recent Activities and Quick Actions -->
        <?php if ($show_sidebar): ?>
        <div class="col-lg-4 mb-4">
            <!-- Recent Activities -->
            <?php if (canView('audit_logs')): ?>
            <div class="card mb-4">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.05em; font-weight: 700;"><i class="bi bi-clock-history"></i> Recent Activities</h6>
                    <a href="<?= getUrl('activity_log') ?>" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between gap-2">
                                    <h6 class="mb-1" style="min-width:0; flex:1;">
                                        <?php 
                                        $icon = 'bi-activity'; $color = 'text-secondary';
                                        $t = isset($activity['type']) ? strtolower((string)$activity['type']) : '';
                                        $d = isset($activity['description']) ? strtolower((string)$activity['description']) : '';
                                        
                                        if (strpos($t, 'invoice') !== false) { $icon = 'bi-receipt'; $color = 'text-primary'; }
                                        elseif (strpos($t, 'payment') !== false || strpos($d, 'payment') !== false) { $icon = 'bi-cash'; $color = 'text-success'; }
                                        elseif (strpos($t, 'sale') !== false) { $icon = 'bi-cart'; $color = 'text-info'; }
                                        elseif (strpos($t, 'customer') !== false || strpos($d, 'customer') !== false) { $icon = 'bi-person'; $color = 'text-warning'; }
                                        elseif (strpos($t, 'employee') !== false || strpos($d, 'employee') !== false) { $icon = 'bi-person-badge'; $color = 'text-dark'; }
                                        elseif (strpos($t, 'product') !== false || strpos($d, 'product') !== false) { $icon = 'bi-box'; $color = 'text-danger'; }
                                        elseif (strpos($t, 'stock') !== false || strpos($t, 'inventory') !== false) { $icon = 'bi-boxes'; $color = 'text-primary'; }
                                        elseif (strpos($t, 'delete') !== false) { $icon = 'bi-trash'; $color = 'text-danger'; }
                                        elseif (strpos($t, 'add') !== false || strpos($t, 'create') !== false) { $icon = 'bi-plus-circle'; $color = 'text-success'; }
                                        ?>
                                    <h6 class="mb-1 d-flex align-items-center">
                                        <i class="bi <?= $icon ?> <?= $color ?> me-2"></i>
                                        <?php
                                        $displayText = !empty($activity['description']) ? $activity['description'] : $activity['type'];
                                        echo htmlspecialchars((string)$displayText);
                                        ?>
                                    </h6>
                                    <small class="text-muted text-nowrap"><?= $activity['time_ago'] ?></small>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <?php 
                                        $ref = (string)($activity['reference'] ?? '');
                                        // Only show reference if it's not an IP address
                                        if (!empty($ref) && strpos($ref, '.') === false && strpos($ref, ':') === false) {
                                            echo htmlspecialchars($ref);
                                            if (!empty($activity['user_name'])) echo ' • ';
                                        }
                                        ?>
                                        <?php if (!empty($activity['user_name'])): ?>
                                            <i class="bi bi-person-circle small me-1"></i> <?= htmlspecialchars((string)$activity['user_name']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-activity text-muted" style="font-size: 2rem;"></i>
                                <p class="text-muted mt-2">No recent activities</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Sidebar Content Ends Here -->

            <!-- User Performance (for sales/cashier roles) -->
            <?php if (!empty($user_metrics)): ?>
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-person-badge"></i> Your Performance</h6>
                </div>
                <div class="card-body">
                    <?php if ($user_role == 'Sales'): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Monthly Target</span>
                            <span><?= format_currency($user_metrics['total_revenue'] ?? 0) ?> / <?= format_currency($user_metrics['monthly_target'] ?? 0) ?></span>
                        </div>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar bg-<?= get_progress_color($user_metrics['target_achievement'] ?? 0) ?>" 
                                 style="width: <?= min($user_metrics['target_achievement'] ?? 0, 100) ?>%">
                                <?= round($user_metrics['target_achievement'] ?? 0) ?>%
                            </div>
                        </div>
                    </div>
                    <div class="row text-center">
                        <div class="col-6">
                            <h4><?= $user_metrics['total_sales'] ?? 0 ?></h4>
                            <small class="text-muted">Total Sales</small>
                        </div>
                        <div class="col-6">
                            <h4><?= format_currency($user_metrics['avg_sale_value'] ?? 0) ?></h4>
                            <small class="text-muted">Avg Sale</small>
                        </div>
                    </div>
                    
                    <?php elseif ($user_role == 'Cashier'): ?>
                    <div class="text-center mb-3">
                        <h1 class="display-6"><?= $user_metrics['total_transactions'] ?? 0 ?></h1>
                        <p class="text-muted">Transactions Today</p>
                    </div>
                    <div class="row text-center">
                        <div class="col-6">
                            <h4><?= format_currency($user_metrics['total_cash_handled'] ?? 0) ?></h4>
                            <small class="text-muted">Total Cash</small>
                        </div>
                        <div class="col-6">
                            <h4><?= format_currency($user_metrics['avg_transaction'] ?? 0) ?></h4>
                            <small class="text-muted">Avg Transaction</small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    
</div>

<!-- JavaScript for Dashboard -->
<script>
$(document).ready(function() {
    // Load performance chart
    loadPerformanceChart();
    
    // Auto-refresh dashboard every 5 minutes
    setTimeout(function() {
        if (document.hasFocus()) {
            location.reload();
        }
    }, 300000);
    
    // Chart period change
    $('#chartPeriod').change(function() {
        loadPerformanceChart($(this).val());
    });
    
    // Real-time updates for critical metrics
    if (typeof(EventSource) !== "undefined") {
        var source = new EventSource("api/dashboard_updates.php");
        source.onmessage = function(event) {
            var data = JSON.parse(event.data);
            updateDashboardMetrics(data);
        };
    }
});

function loadPerformanceChart(period = 'monthly') {
    var apiUrl = '<?= getUrl("api/get_performance_data.php") ?>';
    console.log('[Chart] Loading from URL:', apiUrl, '| period:', period);
    $('#chartLoader').show();
    $.ajax({
        url: apiUrl,
        type: 'GET',
        data: { period: period },
        dataType: 'json',
        success: function(response) {
            $('#chartLoader').hide();
            console.log('[Chart] API response:', JSON.stringify(response).substring(0, 300));
            if (response.success && response.data && response.data.length > 0) {
                console.log('[Chart] Rendering', response.data.length, 'data points');
                renderChart(response.data);
            } else {
                console.warn('[Chart] No data or API error. success=', response.success, 'data length=', response.data ? response.data.length : 'null');
                $('#performanceSummary').html(`
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-bar-chart" style="font-size:2rem;"></i>
                        <p class="mt-2 small">No performance data found for this period.</p>
                    </div>
                `);
                renderChart([]); // render empty chart with grid shown
            }
        },
        error: function(xhr) {
            $('#chartLoader').hide();
            console.error('[Chart] AJAX error:', xhr.status, xhr.responseText.substring(0, 200));
            $('#performanceSummary').html(`
                <div class="alert alert-warning small mt-3">
                    <strong>Chart Error (HTTP ${xhr.status}):</strong><br>
                    URL tried: <code>${apiUrl}</code><br>
                    Response: <code>${xhr.responseText.substring(0, 150)}</code>
                </div>
            `);
        }
    });
}

let dashboardChart = null;
function renderChart(data) {
    const ctx = document.getElementById('performanceChart').getContext('2d');
    
    if (dashboardChart) {
        dashboardChart.destroy();
        dashboardChart = null;
    }
    
    $('#performanceSummary').empty();

    // If no data, show empty chart frame
    const labels = data.length > 0 ? data.map(row => row.period) : [];
    const revenueData = data.length > 0 ? data.map(row => parseFloat(row.revenue) || 0) : [];
    const expenseData = data.length > 0 ? data.map(row => parseFloat(row.expense) || 0) : [];
    
    dashboardChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue (Invoiced)',
                data: revenueData,
                borderColor: '#0d6efd',
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return 'rgba(13,110,253,0.08)';
                    const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(13,110,253,0.18)');
                    gradient.addColorStop(1, 'rgba(13,110,253,0.01)');
                    return gradient;
                },
                fill: true,
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 4,
                pointHoverRadius: 7,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#0d6efd',
                pointBorderWidth: 2,
            }, {
                label: 'Expenses',
                data: expenseData,
                borderColor: '#dc3545',
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return 'rgba(220,53,69,0.05)';
                    const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(220,53,69,0.13)');
                    gradient.addColorStop(1, 'rgba(220,53,69,0.01)');
                    return gradient;
                },
                fill: true,
                tension: 0.4,
                borderWidth: 2,
                borderDash: [6, 3],
                pointRadius: 4,
                pointHoverRadius: 7,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#dc3545',
                pointBorderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        pointStyleWidth: 12,
                        boxHeight: 8,
                        font: { size: 12 },
                        padding: 20
                    }
                },
                tooltip: {
                    padding: 14,
                    backgroundColor: 'rgba(17,24,39,0.9)',
                    titleFont: { size: 13, weight: 'bold' },
                    bodyFont: { size: 12 },
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            const val = context.parsed.y;
                            if (val >= 1000000) label += 'TSh ' + (val/1000000).toFixed(2) + 'M';
                            else if (val >= 1000) label += 'TSh ' + (val/1000).toFixed(1) + 'K';
                            else label += 'TSh ' + val.toLocaleString();
                            return label;
                        },
                        afterBody: function(items) {
                            const rev = items.find(i => i.datasetIndex === 0);
                            const exp = items.find(i => i.datasetIndex === 1);
                            if (rev && exp) {
                                const profit = rev.parsed.y - exp.parsed.y;
                                const sign = profit >= 0 ? '+' : '';
                                const formatted = Math.abs(profit) >= 1000000
                                    ? (profit/1000000).toFixed(2) + 'M'
                                    : Math.abs(profit) >= 1000
                                        ? (profit/1000).toFixed(1) + 'K'
                                        : profit.toLocaleString();
                                return ['─────────────────', `Net Profit: ${sign}TSh ` + formatted];
                            }
                            return [];
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    ticks: {
                        color: '#6b7280',
                        font: { size: 11 },
                        callback: function(value) {
                            if (value >= 1000000) return 'TSh ' + (value/1000000).toFixed(1) + 'M';
                            if (value >= 1000) return 'TSh ' + (value/1000).toFixed(0) + 'K';
                            return 'TSh ' + value;
                        },
                        maxTicksLimit: 7
                    },
                    grid: {
                        color: 'rgba(107,114,128,0.15)',
                        lineWidth: 1,
                        drawBorder: false
                    },
                    border: {
                        dash: [4, 4],
                        display: false
                    }
                },
                x: {
                    ticks: {
                        color: '#6b7280',
                        font: { size: 11 },
                        maxRotation: 0
                    },
                    grid: {
                        color: 'rgba(107,114,128,0.08)',
                        lineWidth: 1,
                        drawBorder: false
                    },
                    border: {
                        display: false
                    }
                }
            }
        }
    });

    // Update the summary below the chart
    if (data.length > 0) {
        const last = data[data.length - 1];
        const paperProfit = last.revenue - (last.expense || 0);
        const actualCash = (last.collected || 0) - (last.expense || 0);
        
        $('#performanceSummary').append(`
            <div class="mt-4 p-3 bg-light rounded shadow-sm border">
                <div class="row align-items-center">
                    <div class="col-md-4 border-end">
                        <span class="text-muted small d-block uppercase fw-bold" style="font-size: 0.7rem;">Latest Period (${last.period})</span>
                        <div class="d-flex align-items-center flex-wrap gap-2 mt-1">
                            <span class="badge bg-primary">Sales: TSh ${last.revenue.toLocaleString()}</span>
                            <span class="badge bg-success">Cash: TSh ${(last.collected || 0).toLocaleString()}</span>
                        </div>
                    </div>
                    <div class="col-md-4 border-end text-center py-2 py-md-0">
                        <span class="text-muted small d-block uppercase fw-bold" style="font-size: 0.7rem;">Financial Reality</span>
                        <strong class="h5 mb-0 text-${actualCash >= 0 ? 'success' : 'danger'}">
                            Net Cash: ${actualCash >= 0 ? '+' : ''}TSh ${actualCash.toLocaleString()}
                        </strong>
                        <small class="d-block text-muted" style="font-size: 0.75rem;">(Actual In - Expenses)</small>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="text-muted small d-block uppercase fw-bold" style="font-size: 0.7rem;">Paper Profit</span>
                        <strong class="h6 mb-0 text-${paperProfit >= 0 ? 'primary' : 'danger'}">
                            TSh ${paperProfit.toLocaleString()}
                        </strong>
                        <small class="d-block text-muted" style="font-size: 0.75rem;">(Invoiced - Expenses)</small>
                    </div>
                </div>
            </div>
        `);
    }
}

function updateDashboardMetrics(data) {
    // Update specific metrics on the dashboard
    if (data.type === 'new_sale') {
        // Update today's sales count
        const todaySales = $('#todaySalesCount');
        const current = parseInt(todaySales.text()) || 0;
        todaySales.text(current + 1);
        
        // Update revenue
        const todayRevenue = $('#todayRevenue');
        const currentRevenue = parseFloat(todayRevenue.text().replace(/[^0-9.]/g, '')) || 0;
        todayRevenue.text('TSh ' + (currentRevenue + data.amount).toLocaleString());
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Alt + N for new sale/invoice
    if (e.altKey && e.key === 'n') {
        e.preventDefault();
        <?php if ($company_type != 'microfinance'): ?>
        window.location.href = 'pos';
        <?php else: ?>
        window.location.href = 'loan_application';
        <?php endif; ?>
    }
    
    // Alt + R for reports
    if (e.altKey && e.key === 'r') {
        e.preventDefault();
        window.location.href = 'reports';
    }
    
    // Alt + C for customers
    if (e.altKey && e.key === 'c') {
        e.preventDefault();
        window.location.href = 'customers';
    }
    
    // F5 to refresh
    if (e.key === 'F5') {
        e.preventDefault();
        location.reload();
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    transition: transform 0.2s;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.progress {
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
}

.list-group-item {
    border-left: none;
    border-right: none;
}

/* Recent Activities — long descriptions must wrap, not overflow */
.list-group-item h6 {
    word-break: break-word;
    overflow-wrap: break-word;
    min-width: 0;
    white-space: normal;
}

.list-group-item:first-child {
    border-top: none;
}

.list-group-item:last-child {
    border-bottom: none;
}

.badge {
    font-size: 0.75em;
}

.display-6 {
    font-size: 2.5rem;
}

/* Animation for new updates */
@keyframes highlightUpdate {
    0% { background-color: #fff3cd; }
    100% { background-color: transparent; }
}

.highlight-update {
    animation: highlightUpdate 2s;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .display-6 {
        font-size: 1.8rem;
    }
    
    .btn-group .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .card {
        background-color: #343a40;
        color: #fff;
    }
    
    .card-header.bg-light {
        background-color: #495057 !important;
        color: #fff;
    }
    
    .text-muted {
        color: #adb5bd !important;
    }
}
</style>

<!-- Alert Details Modal -->
<div class="modal fade" id="alertDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="alertModalTitle">Alert Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="alertModalBody">
                <!-- Content loaded via JS -->
            </div>
            <div class="modal-footer" id="alertModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Stock Modal -->
<div class="modal fade" id="quickAddStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickAddStockForm">
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="quickAddProductId">
                    <input type="hidden" name="movement_type" value="adjustment_in">
                    <input type="hidden" name="reason" value="Dashboard restock">
                    
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="quickAddProductName" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Warehouse</label>
                        <select class="form-select" name="warehouse_id" id="quickAddWarehouse" required onchange="fetchCurrentStock()">
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>"><?= htmlspecialchars($wh['warehouse_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity to Add</label>
                        <input type="number" step="any" class="form-control" name="quantity" id="quickAddQuantity" required oninput="calculatePreview()" placeholder="Enter quantity here...">
                    </div>

                    <!-- Live Preview Card -->
                    <div class="card bg-light mb-3 border-0 shadow-sm">
                        <div class="card-body p-3">
                            <h6 class="card-subtitle mb-2 text-muted small uppercase">Stock Preview</h6>
                            <div class="row text-center g-0">
                                <div class="col-4 border-end">
                                    <div class="text-muted extra-small">Before</div>
                                    <strong id="previewBefore" class="fs-5">0</strong>
                                </div>
                                <div class="col-4 border-end">
                                    <div class="text-muted extra-small">Add</div>
                                    <strong id="previewChange" class="fs-5 text-success">+0</strong>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted extra-small">After</div>
                                    <strong id="previewAfter" class="fs-5 text-primary">0</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="errorContainer" style="display: none;">
                        <div class="alert alert-danger py-2 small" id="errorMessage"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Reason for restocking..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="btnSubmitStock">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function handleAlertAction(alert) {
    const title = document.getElementById('alertModalTitle');
    const body = document.getElementById('alertModalBody');
    const footer = document.getElementById('alertModalFooter');
    
    // Clear previous footer buttons except Close
    footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';

    // Check if user has permission to add stock
    const canAddStock = <?= (isAdmin() || canEdit('products')) ? 'true' : 'false' ?>;
    
    if (alert.type === 'low_stock') {
        title.innerHTML = '<i class="bi bi-box text-danger"></i> Low Stock Alert';
        body.innerHTML = `
            <div class="text-center mb-4">
                <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                <h4>${alert.product_name}</h4>
                <p class="text-muted">SKU: ${alert.sku}</p>
            </div>
            <div class="list-group">
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    Current Stock <span class="badge bg-danger rounded-pill">${alert.stock_quantity}</span>
                </div>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    Reorder level <span class="badge bg-primary rounded-pill">${alert.reorder_level}</span>
                </div>
            </div>
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> It is recommended to restock this item soon to avoid out-of-stock situations.
            </div>
        `;
        
        if (canAddStock) {
            // Add "Add Stock" button
            const btnAdd = document.createElement('button');
            btnAdd.className = 'btn btn-success';
            btnAdd.innerHTML = '<i class="bi bi-plus-circle"></i> Add Stock';
            btnAdd.onclick = () => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('alertDetailsModal'));
                modal.hide();
                setTimeout(() => openQuickAddStock(alert.id, alert.product_name), 500);
            };
            footer.appendChild(btnAdd);
        }

        // Add "View Product" button
        const btnView = document.createElement('a');
        btnView.className = 'btn btn-primary';
        btnView.href = `product_view?id=${alert.id}`;
        btnView.innerHTML = '<i class="bi bi-eye"></i> View Product';
        footer.appendChild(btnView);
        
    } else if (alert.type === 'overdue') {
        title.innerHTML = '<i class="bi bi-clock-history text-danger"></i> Overdue Payment';
        body.innerHTML = `
            <div class="text-center mb-4">
                <i class="bi bi-cash-stack text-danger" style="font-size: 3rem;"></i>
                <h4>Invoice #${alert.reference}</h4>
                <p class="text-muted">Customer: ${alert.customer_name || 'Walk-in'}</p>
            </div>
            <div class="list-group">
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    Overdue Amount <span class="badge bg-danger rounded-pill">${new Intl.NumberFormat().format(alert.overdue_amount)}</span>
                </div>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    Days Overdue <span class="badge bg-warning text-dark rounded-pill">${alert.days_overdue} days</span>
                </div>
            </div>
        `;
        
        const btnView = document.createElement('a');
        btnView.className = 'btn btn-primary';
        btnView.href = `invoice_view?id=${alert.id}`;
        btnView.innerHTML = '<i class="bi bi-eye"></i> View Invoice';
        footer.appendChild(btnView);
        
    } else if (alert.type === 'expiring') {
        title.innerHTML = '<i class="bi bi-calendar-x text-warning"></i> Expiry Alert';
        body.innerHTML = `
            <div class="text-center mb-4">
                <i class="bi bi-hourglass-split text-warning" style="font-size: 3rem;"></i>
                <h4>${alert.product_name}</h4>
                <p class="text-muted">SKU: ${alert.sku}</p>
            </div>
            <div class="list-group">
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    Expiry Date <span class="badge bg-danger rounded-pill">${alert.expiry_date}</span>
                </div>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    Days Remaining <span class="badge bg-info rounded-pill">${alert.days_remaining} days</span>
                </div>
            </div>
        `;
        
        const btnView = document.createElement('a');
        btnView.className = 'btn btn-primary';
        btnView.href = `product_view?id=${alert.id}`;
        btnView.innerHTML = '<i class="bi bi-eye"></i> View Product';
        footer.appendChild(btnView);
    }
    
    new bootstrap.Modal(document.getElementById('alertDetailsModal')).show();
}

function calculatePreview() {
    const before = parseFloat(document.getElementById('previewBefore').innerText) || 0;
    const qtyInput = document.getElementById('quickAddQuantity');
    const qty = parseFloat(qtyInput.value) || 0;
    const after = before + qty;
    
    document.getElementById('previewChange').innerText = (qty >= 0 ? '+' : '') + qty;
    document.getElementById('previewChange').className = qty >= 0 ? 'fs-5 text-success' : 'fs-5 text-danger';
    document.getElementById('previewAfter').innerText = after.toFixed(2);
}

function fetchCurrentStock() {
    const productId = document.getElementById('quickAddProductId').value;
    const warehouseId = document.getElementById('quickAddWarehouse').value;
    const beforeEl = document.getElementById('previewBefore');
    
    beforeEl.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary"></span>';
    
    fetch(`../api/get_current_stock.php?product_id=${productId}&warehouse_id=${warehouseId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                beforeEl.innerText = data.stock;
                calculatePreview();
            } else {
                beforeEl.innerText = '0';
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            beforeEl.innerText = 'Error';
        });
}

function openQuickAddStock(id, name) {
    document.getElementById('quickAddProductId').value = id;
    document.getElementById('quickAddProductName').value = name;
    document.getElementById('quickAddQuantity').value = '';
    document.getElementById('errorContainer').style.display = 'none';
    
    const modal = new bootstrap.Modal(document.getElementById('quickAddStockModal'));
    modal.show();
    
    // Fetch initial stock for the default selected warehouse
    setTimeout(fetchCurrentStock, 300);
}

document.getElementById('quickAddStockForm').onsubmit = function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitStock');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
    
    const formData = new FormData(this);
    
    fetch('../api/create_stock_adjustment.php', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error. Raw response:', text);
            throw new Error('Server returned invalid data format. Please check the logs.');
        }
    })
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Stock updated successfully!',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            document.getElementById('errorContainer').style.display = 'block';
            document.getElementById('errorMessage').innerText = data.message;
            btn.disabled = false;
            btn.innerHTML = 'Save Changes';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('errorContainer').style.display = 'block';
        document.getElementById('errorMessage').innerText = error.message;
        btn.disabled = false;
        btn.innerHTML = 'Save Changes';
    });
};
</script>

<?php
// Include the footer
include("footer.php");
ob_end_flush();
?>