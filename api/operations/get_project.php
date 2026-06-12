<?php
// api/operations/get_project.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;
$id = $_GET['id'] ?? null;

try {
    // Phase B — project-scope gate: short-circuit if this user isn't
    // assigned to the requested project. Admin bypasses inside userCan().
    if (!userCan('project', (int)$id)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Access denied: this project is not in your scope"]);
        exit;
    }

    // Get project basic info
    $stmt = $pdo->prepare("
        SELECT p.*, c.customer_name, c.company_name AS customer_company
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.customer_id
        WHERE p.project_id = ?
    ");
    $stmt->execute([$id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        echo json_encode(["success" => false, "message" => "Project not found"]);
        exit;
    }
    
    // Get Sales Orders
    $stmt = $pdo->prepare("
        SELECT so.*, c.customer_name, c.company_name
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        WHERE so.project_id = ? AND so.is_quote = 0
        ORDER BY so.order_date DESC
    ");
    $stmt->execute([$id]);
    $sales_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Invoices
    $stmt = $pdo->prepare("
        SELECT i.*, c.customer_name, c.company_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        WHERE i.project_id = ?
        ORDER BY i.invoice_date DESC
    ");
    $stmt->execute([$id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Payment Vouchers
    $stmt = $pdo->prepare("
        SELECT pv.*, ea.account_name AS category_name
        FROM payment_vouchers pv
        LEFT JOIN accounts ea ON pv.expense_account_id = ea.account_id
        WHERE pv.project_id = ?
        ORDER BY pv.vouch_date DESC
    ");
    $stmt->execute([$id]);
    $payment_vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Purchase Orders
    $stmt = $pdo->prepare("
        SELECT po.*, s.supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        WHERE po.project_id = ?
        ORDER BY po.order_date DESC
    ");
    $stmt->execute([$id]);
    $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get GRNs (Purchase Receipts) linked to this project via PO
    $stmt = $pdo->prepare("
        SELECT pr.*, s.supplier_name, po.order_number
        FROM purchase_receipts pr
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        WHERE po.project_id = ?
        ORDER BY pr.receipt_date DESC
    ");
    $stmt->execute([$id]);
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Delivery Notes (DN) — wrapped in try/catch in case migration not run yet
    $dns = [];
    try {
        $stmt = $pdo->prepare("
            SELECT d.delivery_id, d.delivery_number, d.delivery_date, d.status,
                   d.contact_person, d.notes,
                   s.supplier_name, w.warehouse_name,
                   (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = d.delivery_id) as total_items,
                   (SELECT COALESCE(SUM(di.quantity_delivered),0) FROM delivery_items di WHERE di.delivery_id = d.delivery_id) as total_qty,
                   (SELECT do2.do_number FROM delivery_orders do2 WHERE do2.dn_id = d.delivery_id LIMIT 1) as do_number,
                   (SELECT do2.do_id FROM delivery_orders do2 WHERE do2.dn_id = d.delivery_id LIMIT 1) as do_id
            FROM deliveries d
            LEFT JOIN suppliers s  ON d.supplier_id  = s.supplier_id
            LEFT JOIN warehouses w ON d.warehouse_id = w.warehouse_id
            WHERE d.project_id = ?
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$id]);
        $dns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Migration not yet run — deliveries table missing project_id column
        $dns = [];
    }

    // Get Delivery Orders (DO) — wrapped in try/catch in case migration not run yet
    $dos = [];
    try {
        $stmt = $pdo->prepare("
            SELECT do.do_id, do.do_number, do.do_date, do.expected_date, do.status,
                   do.driver_name, do.vehicle_number, do.delivered_at,
                   dn.delivery_number as dn_number, dn.delivery_id as dn_id,
                   s.supplier_name, w.warehouse_name,
                   (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = do.dn_id) as total_items,
                   (SELECT COALESCE(SUM(di.quantity_delivered),0) FROM delivery_items di WHERE di.delivery_id = do.dn_id) as total_qty
            FROM delivery_orders do
            LEFT JOIN deliveries dn ON do.dn_id       = dn.delivery_id
            LEFT JOIN suppliers s   ON do.supplier_id  = s.supplier_id
            LEFT JOIN warehouses w  ON do.warehouse_id = w.warehouse_id
            WHERE do.project_id = ?
            ORDER BY do.created_at DESC
        ");
        $stmt->execute([$id]);
        $dos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Migration not yet run — delivery_orders table missing
        $dos = [];
    }

    // Get Purchase Returns linked to this project
    $stmt = $pdo->prepare("
        SELECT pr.*, s.supplier_name, po.order_number,
               (SELECT COUNT(*) FROM purchase_return_items WHERE purchase_return_id = pr.purchase_return_id) as total_items,
               (SELECT SUM(quantity * unit_price) FROM purchase_return_items WHERE purchase_return_id = pr.purchase_return_id) as total_value
        FROM purchase_returns pr
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        WHERE pr.project_id = ?
        ORDER BY pr.return_date DESC
    ");
    $stmt->execute([$id]);
    $purchase_returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Debit Notes linked to this project. A debit note resolves its project
    // either directly (debit_notes.project_id) or via its origin purchase return
    // (purchase_returns.project_id). Guarded so older DBs without the table or
    // column degrade to an empty list rather than 500.
    $debit_notes = [];
    try {
        $dnTableOk = (bool)$pdo->query("SHOW TABLES LIKE 'debit_notes'")->fetch();
        $dnHasProject = $dnTableOk && (bool)$pdo->query("SHOW COLUMNS FROM debit_notes LIKE 'project_id'")->fetch();
        if ($dnTableOk) {
            $projClause = $dnHasProject
                ? "COALESCE(dn.project_id, pr.project_id) = ?"
                : "pr.project_id = ?";
            $stmt = $pdo->prepare("
                SELECT dn.debit_note_id, dn.debit_note_number, dn.debit_date,
                       dn.grand_total, dn.status, dn.purchase_return_id,
                       s.supplier_name, pr.return_number,
                       (SELECT COUNT(*) FROM debit_note_items WHERE debit_note_id = dn.debit_note_id) AS total_items
                  FROM debit_notes dn
             LEFT JOIN purchase_returns pr ON dn.purchase_return_id = pr.purchase_return_id
             LEFT JOIN suppliers s         ON dn.supplier_id        = s.supplier_id
                 WHERE dn.status != 'deleted'
                   AND {$projClause}
              ORDER BY dn.debit_date DESC, dn.debit_note_id DESC
            ");
            $stmt->execute([$id]);
            $debit_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $debit_notes = [];
    }

    // Get Budgets with calculated spent amounts
    $stmt = $pdo->prepare("
        SELECT b.*, ec.name AS category_name,
               (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE budget_id = b.budget_id AND status != 'rejected') as spent_amount
        FROM budgets b
        LEFT JOIN expense_categories ec ON b.category_id = ec.id
        WHERE b.project_id = ?
        ORDER BY b.budget_year DESC, b.budget_month DESC
    ");
    $stmt->execute([$id]);
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Inject variance for each budget
    foreach ($budgets as &$b) {
        $b['variance'] = $b['allocated_amount'] - $b['spent_amount'];
        // remaining_balance can now be negative (over-budget)
        $b['remaining_balance'] = $b['variance'];
    }
    unset($b);
    
    // Get Expenses with rich association data
    $stmt = $pdo->prepare("
        SELECT e.*, ba.account_name, v.voucher_number,
            CASE
                WHEN e.paid_to_type = 'supplier'        THEN sup.supplier_name
                WHEN e.paid_to_type = 'staff'           THEN CONCAT(u.first_name, ' ', u.last_name)
                WHEN e.paid_to_type = 'sub_contractor'  THEN sc.supplier_name
                ELSE NULL
            END AS payee_name
        FROM expenses e
        LEFT JOIN accounts ba              ON e.bank_account_id = ba.account_id
        LEFT JOIN payment_vouchers v       ON e.voucher_id   = v.id
        LEFT JOIN suppliers sup            ON e.paid_to_type = 'supplier'       AND e.paid_to_id = sup.supplier_id
        LEFT JOIN users u                  ON e.paid_to_type = 'staff'          AND e.paid_to_id = u.user_id
        LEFT JOIN sub_contractors sc       ON e.paid_to_type = 'sub_contractor' AND e.paid_to_id = sc.supplier_id
        WHERE e.project_id = ?
        ORDER BY e.expense_date DESC
    ");
    $stmt->execute([$id]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expenses as &$exp) {
        if (!empty($exp['expense_items'])) {
            $exp['expense_items'] = json_decode($exp['expense_items'], true) ?: [];
        } else {
            $exp['expense_items'] = [];
        }
    }
    unset($exp);
    
    // Fetch categories for all expenses (Many-to-Many)
    if (!empty($expenses)) {
        $expenseIds = array_column($expenses, 'expense_id');
        $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
        
        $catStmt = $pdo->prepare("
            SELECT ecm.expense_id, ec.id as category_id, ec.name as category_name 
            FROM expense_category_map ecm
            JOIN expense_categories ec ON ecm.category_id = ec.id
            WHERE ecm.expense_id IN ($placeholders)
        ");
        $catStmt->execute($expenseIds);
        $allCategories = $catStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        
        foreach ($expenses as &$exp) {
            $exp['categories'] = $allCategories[$exp['expense_id']] ?? [];
        }
        unset($exp);
    }

    
    // Get total allocated budget from budgets table for this project
    // Only count APPROVED budgets as per user request
    // Get total from allocated budget items (Approved only)
    $stmt = $pdo->prepare("SELECT SUM(allocated_amount) FROM budgets WHERE project_id = ? AND status = 'approved'");
    $stmt->execute([$id]);
    $allocated_budget_items = $stmt->fetchColumn() ?: 0;
    
    // Calculate Project Grand Total from Scopes (New requirement: Master Contract Sum)
    $stmtScope = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN scope_type = 'original' THEN (scope * amount) + tax_amount ELSE 0 END) as original_total,
            SUM(CASE WHEN scope_type = 'revised' THEN (scope * amount) + tax_amount ELSE 0 END) as revised_total,
            SUM(CASE WHEN scope_type = 'variation' THEN (scope * amount) + tax_amount ELSE 0 END) as variation_total,
            SUM(CASE WHEN scope_type = 'additional' THEN (scope * amount) + tax_amount ELSE 0 END) as additional_total
        FROM project_milestones
        WHERE project_id = ?
    ");
    $stmtScope->execute([$id]);
    $scope_sums = $stmtScope->fetch(PDO::FETCH_ASSOC);

    $scope_baseline = ($scope_sums['revised_total'] > 0) ? $scope_sums['revised_total'] : ($scope_sums['original_total'] ?: 0);
    $scope_grand_total = (float)$scope_baseline + (float)$scope_sums['variation_total'] + (float)$scope_sums['additional_total'];

    // Final Logic for Financial Display vs. Contract Sum:
    // 1. Project Budget (Financial Card): ONLY approved budgets from the budgets table
    $total_project_budget = $allocated_budget_items;

    // 2. Contract Sum (Project Detail): Priority to Scope-based total, fallback to project-level budget
    $project['form_contract_sum'] = (float)($project['contract_sum'] ?: 0); // raw value from New Project form
    $project['contract_sum'] = ($scope_grand_total > 0) ? $scope_grand_total : ($project['budget'] ?: 0);

    
    // Calculate Financial Summary
    $total_revenue = 0;
    foreach ($invoices as $inv) {
        if (!in_array($inv['status'], ['cancelled', 'void', 'draft', 'pending'])) {
            $total_revenue += $inv['grand_total'];
        }
    }

    // Total Paid: actual cash received from invoices with status paid, partial, or sent
    $total_paid = 0;
    foreach ($invoices as $inv) {
        if (in_array($inv['status'], ['paid', 'partial', 'sent'])) {
            $total_paid += $inv['paid_amount'];
        }
    }
    
    $total_orders = 0;
    foreach ($sales_orders as $so) {
        if ($so['status'] != 'cancelled') {
            $total_orders += $so['grand_total'];
        }
    }
    
    $total_expense = 0;
    foreach ($payment_vouchers as $pv) {
        if (in_array($pv['status'], ['approved', 'paid'])) {
            $total_expense += $pv['amount'];
        }
    }
    
    foreach ($expenses as $exp) {
        if (in_array($exp['status'], ['approved', 'paid'])) {
            $total_expense += $exp['amount'];
        }
    }
    
    $total_committed = 0;
    foreach ($purchase_orders as $po) {
        if ($po['status'] != 'cancelled') {
            $total_committed += $po['grand_total'];
        }
    }
    
    $profit = $total_revenue - $total_expense;
    $profit_margin = $total_revenue > 0 ? round(($profit / $total_revenue) * 100, 2) : 0;
    
    // ===== INTELLIGENT PROGRESS CALCULATION =====
    
    // 1. Financial Completion (40% weight)
    // Based on how much has been invoiced vs total expected (sales orders)
    $financial_progress = 0;
    if ($total_orders > 0) {
        $financial_progress = min(100, round(($total_revenue / $total_orders) * 100, 2));
    } elseif ($total_revenue > 0) {
        $financial_progress = 100; // If revenue exists but no orders, assume complete
    }
    
    // 2. Timeline Progress (30% weight)
    // Based on elapsed time vs total project duration
    $timeline_progress = 0;
    // Normalize dates to midnight for consistent day-based calculation
    $start_date_raw = $project['start_date'] ?? date('Y-m-d');
    $start_date_str = date('Y-m-d', strtotime($start_date_raw));
    $deadline_str = ($project['deadline'] && $project['deadline'] !== '0000-00-00') ? date('Y-m-d', strtotime($project['deadline'])) : null;
    $today_str = date('Y-m-d');
    
    $start_date = strtotime($start_date_str . ' 00:00:00');
    $deadline = $deadline_str ? strtotime($deadline_str . ' 00:00:00') : null;
    $today = strtotime($today_str . ' 00:00:00');
    
    if ($deadline && $start_date < $deadline) {
        $total_duration = $deadline - $start_date;
        $elapsed = $today - $start_date;
        $timeline_progress = ($total_duration > 0) ? min(100, max(0, round(($elapsed / $total_duration) * 100, 2))) : 100;
    } elseif ($start_date <= $today) {
        // No deadline set, estimate based on 90 days default
        $default_duration = 90 * 24 * 60 * 60; // 90 days in seconds
        $elapsed = $today - $start_date;
        $timeline_progress = min(100, round(($elapsed / $default_duration) * 100, 2));
    }
    
    // 3. Budget Utilization (30% weight)
    // Based on expenses vs budget
    $budget_progress = 0;
    if ($total_project_budget > 0) {
        $budget_progress = min(100, round(($total_expense / $total_project_budget) * 100, 2));
    }
    
    // Calculate weighted average
    $calculated_progress = round(
        ($financial_progress * 0.40) + 
        ($timeline_progress * 0.30) + 
        ($budget_progress * 0.30),
        2
    );
    
    // Use manual progress if set, otherwise use calculated
    $actual_progress = $project['progress_percent'];
    $auto_progress = $calculated_progress;
    
    // Progress analysis
    $progress_analysis = [
        'financial_completion' => $financial_progress,
        'timeline_progress' => $timeline_progress,
        'budget_utilization' => $budget_progress,
        'calculated_progress' => $calculated_progress,
        'manual_progress' => $actual_progress,
        'is_manual' => ($actual_progress > 0),
        'recommendation' => $calculated_progress
    ];

    // ===== REAL PERFORMANCE TOTAL FROM MILESTONE REPORTS (GLOBAL CUMULATIVE SUM MODEL) =====
    // Use the centralized sync function to ensure "Overall Progress" matches "Aggregated Milestone Totals"
    $performance_total = syncProjectProgress($pdo, $id);
    $actual_perf_value = (float)$performance_total;
    
    $progress_analysis['performance_total'] = $actual_perf_value;
    $progress_analysis['has_performance_data'] = true;
    $progress_analysis['calculated_progress'] = $actual_perf_value;
    
    // Status Determination Parameters
    $timeline_progress = (float)$progress_analysis['timeline_progress'];
    $performance_total = (float)$progress_analysis['performance_total'];

    // Determine if project is on track
    $days_remaining = $deadline ? round(($deadline - $today) / (24 * 60 * 60)) : null;
    $is_overdue = $deadline && $today > $deadline;
    
    // Improved logic: Only mark as behind if progress is significantly lower than time elapsed (20% gap)
    // AND don't mark as behind if there's more than 30 days remaining (unless it's really stalling)
    $is_behind_schedule = ($timeline_progress > ($performance_total + 20)) && ($days_remaining < 30 || $timeline_progress > 50); 
    $is_ahead_schedule = $performance_total > ($timeline_progress + 10); 
    
    $progress_status = 'on_track';
    if ($is_overdue) {
        $progress_status = 'overdue';
    } elseif ($is_behind_schedule) {
        $progress_status = 'behind';
    } elseif ($is_ahead_schedule) {
        $progress_status = 'ahead';
    }
    
    $progress_analysis['status'] = $progress_status;
    $progress_analysis['days_remaining'] = $days_remaining;
    $progress_analysis['is_overdue'] = $is_overdue;
    
    // Get Project Documents (Unified Library)
    $stmt = $pdo->prepare("
        SELECT d.*, u.username as uploader_name
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.user_id
        WHERE d.project_id = ?
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute([$id]);
    $project_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Project Staff
    $stmt = $pdo->prepare("
        SELECT 
            e.employee_id, e.employee_number, e.first_name, e.last_name, e.email, e.phone,
            d.department_name, 
            des.designation_name
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN designations des ON e.designation_id = des.designation_id
        WHERE e.project_id = ? AND e.status != 'terminated'
        ORDER BY e.first_name, e.last_name ASC
    ");
    $stmt->execute([$id]);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Project Suppliers
    $stmt = $pdo->prepare("
        SELECT s.*, sc.category_name
        FROM suppliers s
        LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
        WHERE s.project_id = ? AND s.status != 'deleted'
        ORDER BY s.supplier_name ASC
    ");
    $stmt->execute([$id]);
    $project_suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare response
    $response = [
        "success" => true,
        "data" => $project,
        "project_documents" => $project_documents,
        "project_suppliers" => $project_suppliers,
        "staff" => $staff,
        "financial_summary" => [
            "total_revenue" => $total_revenue,
            "total_paid"    => $total_paid,
            "total_orders"  => $total_orders,
            "total_expense" => $total_expense,
            "total_committed" => $total_committed,
            "profit"        => $profit,
            "profit_margin" => $profit_margin,
            "budget"        => $total_project_budget
        ],
        "progress_analysis" => $progress_analysis,
        "sales_orders" => $sales_orders,
        "invoices" => $invoices,
        "payment_vouchers" => $payment_vouchers,
        "purchase_orders" => $purchase_orders,
        "grns" => $grns,
            "dns"  => $dns,
            "dos"  => $dos,
        "purchase_returns" => $purchase_returns,
        "debit_notes" => $debit_notes,
        "expenses" => $expenses,
        "budgets" => $budgets,
        "inventory" => [
            "purchased_items" => (function($pdo, $id) {
                $stmt = $pdo->prepare("
                    SELECT poi.*, p.product_name, p.sku, p.unit as product_unit, po.order_number, po.status as po_status
                    FROM purchase_order_items poi
                    JOIN products p ON poi.product_id = p.product_id
                    JOIN purchase_orders po ON poi.purchase_order_id = po.purchase_order_id
                    WHERE po.project_id = ?
                    ORDER BY po.order_date DESC
                ");
                $stmt->execute([$id]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            })($pdo, $id),
            "sold_items" => (function($pdo, $id) {
                $stmt = $pdo->prepare("
                    SELECT soi.*, p.product_name, p.sku, p.unit as product_unit, so.order_number, so.status as so_status
                    FROM sales_order_items soi
                    JOIN products p ON soi.product_id = p.product_id
                    JOIN sales_orders so ON soi.order_id = so.sales_order_id
                    WHERE so.project_id = ?
                    ORDER BY so.order_date DESC
                ");
                $stmt->execute([$id]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            })($pdo, $id),
            "adjustments" => (function($pdo, $id) {
                $stmt = $pdo->prepare("
                    SELECT sm.*, p.product_name, p.sku, w.warehouse_name, u.username as adjusted_by
                    FROM stock_movements sm
                    JOIN products p ON sm.product_id = p.product_id
                    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                    LEFT JOIN users u ON sm.created_by = u.user_id
                    WHERE sm.project_id = ? AND sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft', 'adjustment', 'stock_adjustment')
                    ORDER BY sm.movement_date DESC, sm.created_at DESC
                ");
                $stmt->execute([$id]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            })($pdo, $id),
            "movements" => (function($pdo, $id) {
                $stmt = $pdo->prepare("
                    SELECT sm.*, p.product_name, p.sku, w.warehouse_name
                    FROM stock_movements sm
                    JOIN products p ON sm.product_id = p.product_id
                    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                    WHERE sm.project_id = ?
                    ORDER BY sm.movement_date DESC, sm.created_at DESC
                ");
                $stmt->execute([$id]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            })($pdo, $id),
            "stock_summary" => (function($pdo, $id) {
                // Calculate current balance for this project specifically
                $stmt = $pdo->prepare("
                    SELECT 
                        p.product_id, 
                        p.product_name, 
                        p.sku, 
                        p.unit,
                        p.cost_price as default_cost,
                        c.category_name,
                        w.warehouse_name,
                        SUM(CASE 
                            WHEN sm.movement_type IN ('purchase_in', 'adjustment_in', 'transfer_in', 'return_in', 'found', 'production_in') THEN sm.quantity 
                            WHEN sm.movement_type IN ('sale_out', 'adjustment_out', 'transfer_out', 'return_out', 'damaged', 'expired', 'theft', 'production_out') THEN -sm.quantity
                            ELSE 0 
                        END) as project_balance,
                        SUM(CASE 
                            WHEN sm.movement_type IN ('purchase_in', 'adjustment_in', 'transfer_in', 'return_in', 'found', 'production_in') THEN (sm.quantity * sm.unit_cost)
                            WHEN sm.movement_type IN ('sale_out', 'adjustment_out', 'transfer_out', 'return_out', 'damaged', 'expired', 'theft', 'production_out') THEN -(sm.quantity * sm.unit_cost)
                            ELSE 0 
                        END) as project_value
                    FROM stock_movements sm
                    JOIN products p ON sm.product_id = p.product_id
                    LEFT JOIN categories c ON p.category_id = c.category_id
                    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                    WHERE sm.project_id = ?
                    GROUP BY p.product_id, w.warehouse_id
                    HAVING project_balance != 0
                    ORDER BY p.product_name ASC
                ");
                $stmt->execute([$id]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            })($pdo, $id),
            "warehouses" => (function($pdo, $id) {
                $stmt = $pdo->prepare("
                    SELECT w.*, u.username as creator_name
                    FROM warehouses w
                    LEFT JOIN users u ON w.created_by = u.user_id
                    WHERE w.project_id = ?
                    ORDER BY w.warehouse_name ASC
                ");
                $stmt->execute([$id]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            })($pdo, $id)
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
