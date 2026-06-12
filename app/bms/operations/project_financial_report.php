<?php
// app/bms/operations/project_financial_report.php
require_once __DIR__ . '/../../../roots.php';

if (!isAuthenticated()) {
    header('Location: ' . getUrl('login'));
    exit;
}

// Phase 5b — project financial reports gated by projects view
if (!canView('projects')) die("Access Denied");

// Ensure user info is in session for print footer
if (isset($_SESSION['user_id']) && (!isset($_SESSION['first_name']) || empty($_SESSION['first_name']) || !isset($_SESSION['username']))) {
    global $pdo;
    $stmtU = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE user_id = ?");
    $stmtU->execute([$_SESSION['user_id']]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $_SESSION['first_name'] = $uData['first_name'] ?? '';
        $_SESSION['last_name'] = $uData['last_name'] ?? '';
        $_SESSION['username'] = $uData['username'] ?? '';
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    die("Error: Project ID is required.");
}

// Phase B (scope) — block project-financial report for projects not in user scope
if (!userCan('project', $id)) {
    http_response_code(403);
    die('Access denied: this project is not in your scope.');
}

global $pdo;

try {
    // Get project basic info
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE project_id = ?");
    $stmt->execute([$id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        die("Error: Project not found.");
    }
    
    // Get Sales Orders
    $stmt = $pdo->prepare("
        SELECT so.*, c.customer_name
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        WHERE so.project_id = ? AND so.is_quote = 0
        ORDER BY so.order_date DESC
    ");
    $stmt->execute([$id]);
    $sales_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Invoices
    $stmt = $pdo->prepare("
        SELECT i.*, c.customer_name
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
    
    // Get Expenses
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name AS category_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.project_id = ?
        ORDER BY e.expense_date DESC
    ");
    $stmt->execute([$id]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Calculations (Similar to project_view.php logic)
    $total_revenue = 0;
    foreach ($invoices as $inv) {
        if (!in_array($inv['status'], ['cancelled', 'void', 'draft', 'pending'])) {
            $total_revenue += $inv['grand_total'];
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
    
    $profit = $total_revenue - $total_expense;
    $profit_margin = $total_revenue > 0 ? round(($profit / $total_revenue) * 100, 2) : 0;
    
    // Fetch company settings for print header
    $company_name = getSetting('company_name', 'BMS');
    $company_logo = getSetting('company_logo', '');
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Report - <?= htmlspecialchars($project['project_name'] ?? '') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; color: #333; }
        .report-container { max-width: 1000px; margin: 30px auto; background: #fff; padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.05); border-radius: 8px; }
        .header-section { border-bottom: 2px solid #0d6efd; padding-bottom: 20px; margin-bottom: 30px; }
        .company-name { font-size: 24px; font-weight: 800; color: #0d6efd; text-transform: uppercase; }
        .report-title { font-size: 18px; color: #6c757d; font-weight: 600; }
        .stat-card { border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; background-color: #fff !important; }
        .stat-label { font-size: 11px; font-weight: 700; color: #6c757d; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px; }
        .stat-value { font-size: 17px; font-weight: 900; color: #000 !important; }
        .section-header { background: #f8f9fa; padding: 10px 15px; font-weight: 700; border-left: 4px solid #0d6efd; margin-top: 30px; margin-bottom: 15px; }
        table { font-size: 13px; }
        .text-success { color: #198754 !important; }
        .text-danger { color: #dc3545 !important; }
        @media print {
            body { background: #fff; }
            .report-container { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; padding: 20px; }
            .d-print-none { display: none !important; }

            /* Fixed print footer on every page */
            .fixed-print-footer {
                position: fixed;
                bottom: 0px;
                left: 0;
                right: 0;
                text-align: center;
                background: white;
                padding: 10px 0;
                border-top: 1px solid #ddd;
                font-size: 10px;
                z-index: 1000;
            }
            .report-container { padding-bottom: 80px !important; }
        }
    </style>
</head>
<body>

<div class="report-container">
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <button onclick="window.close()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i> Close</button>
        <button onclick="window.print()" class="btn btn-primary px-4 shadow-sm"><i class="bi bi-printer me-2"></i> Print Report</button>
    </div>

    <div class="header-section text-center mb-5">
        <?php if(!empty($company_logo)): ?>
            <div class="mb-3">
                <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h1>
        <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT FINANCIAL ANALYSIS</h3>
        <div class="mx-auto bg-primary mb-3" style="width: 60px; height: 3px; border-radius: 2px;"></div>
        
        <div class="d-flex justify-content-center gap-4 mt-3">
            <div class="text-start">
                <div class="fw-bold text-dark"><?= htmlspecialchars($project['project_name'] ?? '') ?></div>
                <div class="small text-muted">Project ID: #<?= $project['project_id'] ?></div>
            </div>
            <div class="text-end border-start ps-4">
                <div class="small text-muted text-uppercase fw-bold">Generated</div>
                <div class="fw-bold"><?= date('M d, Y H:i') ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value"><?= format_currency($total_revenue) ?> TZS</div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-label">Total Expenses</div>
                <div class="stat-value"><?= format_currency($total_expense) ?> TZS</div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-label">Net Profit</div>
                <div class="stat-value"><?= format_currency($profit) ?> TZS</div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-label">Profit Margin</div>
                <div class="stat-value"><?= $profit_margin ?>%</div>
            </div>
        </div>
    </div>

    <div class="section-header">REVENUE DETAILS (INVOICES)</div>
    <table class="table table-striped table-hover">
        <thead class="table-light">
            <tr>
                <th style="width: 50px;">S/NO</th>
                <th>Date</th>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Status</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
                <tr><td colspan="6" class="text-center text-muted">No invoices found for this project.</td></tr>
            <?php else: ?>
                <?php $i = 1; foreach ($invoices as $inv): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= date('Y-m-d', strtotime($inv['invoice_date'])) ?></td>
                        <td><?= $inv['invoice_number'] ?></td>
                        <td><?= htmlspecialchars($inv['customer_name'] ?? '') ?></td>
                        <td><span class="badge bg-<?= $inv['status'] == 'paid' ? 'success' : 'secondary' ?>"><?= strtoupper($inv['status']) ?></span></td>
                        <td class="text-end fw-bold"><?= format_currency($inv['grand_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="table-light">
                <th colspan="5" class="text-end">Project Revenue Total:</th>
                <th class="text-end"><?= format_currency($total_revenue) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="section-header">EXPENSE DETAILS (VOUCHERS & EXPENSES)</div>
    <table class="table table-striped table-hover">
        <thead class="table-light">
            <tr>
                <th style="width: 50px;">S/NO</th>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Status</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $all_expenses = array_merge(
                array_map(function($v){ return ['date'=>$v['vouch_date'], 'cat'=>$v['category_name'], 'desc'=>$v['description'] ?? $v['notes'] ?? '', 'status'=>$v['status'], 'amt'=>$v['amount']]; }, $payment_vouchers),
                array_map(function($e){ return ['date'=>$e['expense_date'], 'cat'=>$e['category_name'], 'desc'=>$e['description'] ?? '', 'status'=>$e['status'], 'amt'=>$e['amount']]; }, $expenses)
            );
            usort($all_expenses, function($a, $b){ return strtotime($b['date']) - strtotime($a['date']); });
            
            if (empty($all_expenses)): ?>
                <tr><td colspan="6" class="text-center text-muted">No expense records found.</td></tr>
            <?php else: ?>
                <?php $i = 1; foreach ($all_expenses as $exp): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= date('Y-m-d', strtotime($exp['date'])) ?></td>
                        <td><?= htmlspecialchars($exp['cat'] ?? '') ?></td>
                        <td><?= htmlspecialchars($exp['desc'] ?? '') ?></td>
                        <td><span class="badge bg-<?= $exp['status'] == 'paid' ? 'success' : 'secondary' ?>"><?= strtoupper($exp['status']) ?></span></td>
                        <td class="text-end fw-bold"><?= format_currency($exp['amt']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="table-light">
                <th colspan="5" class="text-end">Project Expense Total:</th>
                <th class="text-end"><?= format_currency($total_expense) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="section-header">PURCHASE ORDERS & COMMITTED COSTS</div>
    <table class="table table-striped table-hover">
        <thead class="table-light">
            <tr>
                <th style="width: 50px;">S/NO</th>
                <th>Date</th>
                <th>PO #</th>
                <th>Supplier</th>
                <th>Status</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($purchase_orders)): ?>
                <tr><td colspan="6" class="text-center text-muted">No purchase orders found for this project.</td></tr>
            <?php else: ?>
                <?php $i = 1; $total_po = 0; foreach ($purchase_orders as $po): $total_po += $po['grand_total']; ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= date('Y-m-d', strtotime($po['order_date'])) ?></td>
                        <td><?= $po['order_number'] ?></td>
                        <td><?= htmlspecialchars($po['supplier_name'] ?? '') ?></td>
                        <td><span class="badge bg-<?= getStatusBadgeColor($po['status']) ?>"><?= strtoupper($po['status']) ?></span></td>
                        <td class="text-end fw-bold"><?= format_currency($po['grand_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="table-light">
                <th colspan="5" class="text-end">Committed Costs Total:</th>
                <th class="text-end"><?= format_currency($total_po ?? 0) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="fixed-print-footer d-none d-print-block">
        This report was <strong>Printed</strong> by <span class="text-dark fw-bold"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= $_SESSION['username'] ?? 'Staff' ?></span> 
        on <span class="text-dark fw-bold"><?= date('d M, Y \a\t H:i:s') ?></span>
        <div class="mt-1 fw-bold text-primary">Powered By BJP Technologies</div>
    </div>
</div>

<?php
function getStatusBadgeColor($s) {
    if ($s === 'approved' || $s === 'active' || $s === 'paid' || $s === 'completed' || $s === 'received') return 'success';
    if ($s === 'pending' || $s === 'ordered') return 'warning';
    if ($s === 'cancelled' || $s === 'rejected') return 'danger';
    return 'secondary';
}
?>

</body>
</html>
