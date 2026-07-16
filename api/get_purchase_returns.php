<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

// Suppress errors for clean JSON output AFTER including detailed config
error_reporting(0);
ini_set('display_errors', 0);

// Clear any previous output (e.g. from includes)
if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

// Check permission
if (!hasPermission('purchase_returns')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {

    // DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    $order_column_index = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $order_dir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'desc';

    // Filters
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $supplierId = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

    // Columns for ordering
    $columns = [
        0 => 'pr.return_number',
        1 => 'pr.return_date',
        2 => 's.supplier_name',
        3 => 'grn.receipt_number',
        4 => 'total_items', // Calculated column, handling in ORDER BY might be tricky without subquery or view
        5 => 'total_value', // Calculated column
        6 => 'pr.reason',
        7 => 'pr.status',
        8 => 'pr.purchase_return_id'
    ];
    
    $order_column = isset($columns[$order_column_index]) ? $columns[$order_column_index] : 'pr.created_at';

    // Base Query
    $query = "
        SELECT
            pr.purchase_return_id,
            pr.return_number,
            pr.return_date,
            pr.supplier_id,
            pr.purchase_order_id,
            pr.reason,
            pr.reason_details,
            pr.status,
            pr.notes,
            pr.created_by,
            pr.created_at,
            pr.updated_at,
            s.supplier_name,
            s.company_name,
            grn.receipt_number,
            COUNT(pri.return_item_id) as total_items,
            SUM(pri.quantity * pri.unit_price) as total_value
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_receipts grn ON pr.receipt_id = grn.receipt_id
        LEFT JOIN purchase_return_items pri ON pr.purchase_return_id = pri.purchase_return_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        WHERE 1=1
    ";
    
    $params = [];
    $where_clause = "";

    // Filters
    if (!empty($status)) {
        $where_clause .= " AND pr.status = ?";
        $params[] = $status;
    }

    if ($supplierId > 0) {
        $where_clause .= " AND pr.supplier_id = ?";
        $params[] = $supplierId;
    }

    if (!empty($dateFrom)) {
        $where_clause .= " AND pr.return_date >= ?";
        $params[] = $dateFrom;
    }

    if (!empty($dateTo)) {
        $where_clause .= " AND pr.return_date <= ?";
        $params[] = $dateTo;
    }

    // Search
    if (!empty($search)) {
        $where_clause .= " AND (
            pr.return_number LIKE ? OR 
            s.supplier_name LIKE ? OR 
            grn.receipt_number LIKE ? OR
            pr.notes LIKE ? OR
            pr.reason LIKE ?
        )";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $where_clause .= scopeFilterSqlNullable('project', 'po');
    $query .= $where_clause;

    // Group By is needed for aggregate functions
    $query .= " GROUP BY pr.purchase_return_id";

    // Count Total Records (without filters)
    $count_total_stmt = $pdo->query("SELECT COUNT(*) FROM purchase_returns");
    $recordsTotal = $count_total_stmt->fetchColumn();

    // Count Filtered Records (requires a subquery or running the full query without limit)
    // For simplicity/performance with GROUP BY, we can wrap in a count
    $count_filtered_sql = "SELECT COUNT(*) FROM ($query) as filtered_table";
    $stmt = $pdo->prepare($count_filtered_sql);
    $stmt->execute($params);
    $recordsFiltered = $stmt->fetchColumn();

    // Order By and Limit
    // Note: Ordering by calculated columns (total_items, total_amount) works in standard SQL if included in select
    $query .= " ORDER BY $order_column $order_dir LIMIT $start, $length";

    // Execute Main Query
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format Data
    $formatted_data = [];
    foreach ($data as $row) {
        // Actions
        $actions = '<div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-gear"></i>
            </button>
            <ul class="dropdown-menu">';
            
        $actions .= '<li><a class="dropdown-item" href="#" onclick="viewReturn(' . $row['purchase_return_id'] . ')"><i class="bi bi-eye"></i> View Details</a></li>';

        // Print, with a chevron toggle revealing the alternate templates (hidden by
        // default) — the plain "Print" link still opens the default template directly.
        $rid = $row['purchase_return_id'];
        $actions .= '<li>';
        $actions .= '<div class="d-flex align-items-center dropdown-item py-0 pe-1">';
        $actions .= '<a class="flex-grow-1 py-2 text-decoration-none text-dark" href="' . getUrl('print_purchase_return') . '?id=' . $rid . '" target="_blank"><i class="bi bi-printer"></i> Print</a>';
        $actions .= '<button type="button" class="btn btn-sm border-0 p-1 text-muted" title="Choose a different template" onclick="event.stopPropagation(); event.preventDefault(); $(\'#retTplSub' . $rid . '\').toggleClass(\'d-none\'); $(this).find(\'i\').toggleClass(\'bi-chevron-down bi-chevron-up\');"><i class="bi bi-chevron-down"></i></button>';
        $actions .= '</div>';
        $actions .= '<ul class="list-unstyled ms-4 mb-1 d-none" id="retTplSub' . $rid . '">';
        $actions .= '<li><a class="dropdown-item py-1 small text-muted" href="' . getUrl('print_purchase_return_navy') . '?id=' . $rid . '" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Navy Template</a></li>';
        $actions .= '<li><a class="dropdown-item py-1 small text-muted" href="' . getUrl('print_purchase_return_corporate') . '?id=' . $rid . '" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Corporate Template</a></li>';
        $actions .= '<li><a class="dropdown-item py-1 small text-muted" href="' . getUrl('print_purchase_return_banded') . '?id=' . $rid . '" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Banded Template</a></li>';
        $actions .= '</ul>';
        $actions .= '</li>';

        // Three-approval workflow: pending -> reviewed -> approved.
        // Each transition must go through the canonical endpoint so the
        // workflow_signatures row + reviewed_by/approved_by stamps are captured.
        if ($row['status'] == 'pending') {
            if (canCreate('purchase_returns')) {
                $actions .= '<li><a class="dropdown-item" href="#" onclick="editReturn(' . $row['purchase_return_id'] . ')"><i class="bi bi-pencil"></i> Edit Return</a></li>';
            }
            if (canReview('purchase_returns')) {
                $actions .= '<li><a class="dropdown-item text-warning" href="#" onclick="sendForReviewPR(' . $row['purchase_return_id'] . ')"><i class="bi bi-send-check"></i> Send for Review</a></li>';
            }
        }

        if ($row['status'] == 'reviewed' && canApprove('purchase_returns')) {
            $actions .= '<li><a class="dropdown-item text-success" href="#" onclick="approvePR(' . $row['purchase_return_id'] . ')"><i class="bi bi-check-circle"></i> Approve</a></li>';
        }

        if ($row['status'] == 'approved' && canCreate('purchase_returns')) {
            $actions .= '<li><a class="dropdown-item text-success" href="#" onclick="updateReturnStatus(' . $row['purchase_return_id'] . ', \'completed\')"><i class="bi bi-check2-all"></i> Mark as Completed</a></li>';
        }

        if (in_array($row['status'], ['pending', 'reviewed', 'approved']) && canCreate('purchase_returns')) {
            $actions .= '<li><a class="dropdown-item text-warning" href="#" onclick="updateReturnStatus(' . $row['purchase_return_id'] . ', \'cancelled\')"><i class="bi bi-x-octagon"></i> Cancel</a></li>';
        }

        if ($row['status'] == 'pending' && canCreate('purchase_returns')) {
            $actions .= '<li><a class="dropdown-item text-danger" href="#" onclick="updateReturnStatus(' . $row['purchase_return_id'] . ', \'rejected\')"><i class="bi bi-x-circle"></i> Reject</a></li>';
        }
        
        if (hasPermission('delete_purchase_returns')) {
             $actions .= '<li><hr class="dropdown-divider"></li>';
             $actions .= '<li><a class="dropdown-item text-danger" href="#" onclick="deleteReturn(' . $row['purchase_return_id'] . ')"><i class="bi bi-trash"></i> Delete</a></li>';
        }

        $actions .= '</ul></div>';

        // Status Badge
        $status_badges = [
            'pending' => 'warning',
            'approved' => 'primary',
            'completed' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'secondary'
        ];
        $badge_class = $status_badges[$row['status']] ?? 'secondary';
        $status_html = '<span class="badge bg-' . $badge_class . '">' . ucfirst($row['status']) . '</span>';
        
        // Return Number (Clickable)
        $return_number_html = '<code>' . htmlspecialchars($row['return_number']) . '</code>';
        
        // Supplier
        $supplier_html = '<strong>' . htmlspecialchars($row['supplier_name']) . '</strong>';
        if (!empty($row['company_name'])) {
            $supplier_html .= '<br><small class="text-muted">' . htmlspecialchars($row['company_name']) . '</small>';
        }

        $formatted_data[] = [
            'return_number' => $return_number_html,
            'return_date' => date('d M Y', strtotime($row['return_date'])),
            'supplier_name' => $supplier_html,
            'receipt_number' => $row['receipt_number'] ?: '<span class="text-muted">N/A</span>',
            'order_number' => $row['receipt_number'] ?: '<span class="text-muted">N/A</span>', // Fallback for table column mapping
            'total_items' => '<span class="badge bg-secondary">' . $row['total_items'] . '</span>',
            'total_amount' => '<strong>' . number_format($row['total_value'], 2) . '</strong>',
            'reason' => htmlspecialchars(ucwords(str_replace('_', ' ', $row['reason']))),
            'status' => $status_html,
            'actions' => $actions
        ];
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $formatted_data
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
