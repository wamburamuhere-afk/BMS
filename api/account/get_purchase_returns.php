<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!canView('purchase_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to view purchase returns']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $supplier_filter = intval($_GET['supplier'] ?? 0);
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    // Build query
    $query = "
        SELECT 
            pr.*,
            s.supplier_name,
            s.company_name,
            po.order_number as po_number,
            u1.username as created_by_name,
            u2.username as updated_by_name,
            COUNT(pri.return_item_id) as item_count,
            SUM(pri.quantity * pri.unit_price) as calculated_total
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        LEFT JOIN purchase_return_items pri ON pr.purchase_return_id = pri.purchase_return_id
        LEFT JOIN users u1 ON pr.created_by = u1.user_id
        LEFT JOIN users u2 ON pr.updated_by = u2.user_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($status_filter)) {
        $query .= " AND pr.status = ?";
        $params[] = $status_filter;
    }

    if ($supplier_filter > 0) {
        $query .= " AND pr.supplier_id = ?";
        $params[] = $supplier_filter;
    }

    if (!empty($date_from)) {
        $query .= " AND pr.return_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND pr.return_date <= ?";
        $params[] = $date_to;
    }

    $query .= " GROUP BY pr.purchase_return_id ORDER BY pr.return_date DESC, pr.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add actions HTML to each row
    $can_view   = canView('purchase_returns');
    $can_delete = hasPermission('delete_purchase_returns');
    $can_approve = hasPermission('approve_purchase_returns');
    $print_url   = getUrl('print_purchase_return');
    $view_url    = getUrl('purchase_return_view');

    foreach ($returns as &$ret) {
        $id = $ret['purchase_return_id'];
        $status = $ret['status'] ?? 'pending';

        $actions = '<div class="btn-group">';
        $actions .= '<button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>';
        $actions .= '<ul class="dropdown-menu dropdown-menu-end shadow">';

        if ($can_view) {
            $actions .= "<li><a class='dropdown-item' href='{$view_url}?id={$id}'><i class='bi bi-eye text-primary me-2'></i>View Details</a></li>";
        }
        $actions .= "<li><a class='dropdown-item' href='{$print_url}?id={$id}' target='_blank'><i class='bi bi-printer text-secondary me-2'></i>Print</a></li>";

        if ($can_approve && in_array($status, ['pending'])) {
            $actions .= "<li><a class='dropdown-item text-success' href='javascript:void(0)' onclick='updateReturnStatus({$id}, \"approved\")'><i class='bi bi-check-circle me-2'></i>Approve</a></li>";
        }
        $actions .= "<li><a class='dropdown-item' href='javascript:void(0)' onclick='editReturn({$id})'><i class='bi bi-pencil text-warning me-2'></i>Edit</a></li>";

        if ($can_delete) {
            $actions .= "<li><hr class='dropdown-divider'></li>";
            $actions .= "<li><a class='dropdown-item text-danger' href='javascript:void(0)' onclick='deleteReturn({$id})'><i class='bi bi-trash me-2'></i>Delete</a></li>";
        }

        $actions .= '</ul></div>';
        $ret['actions'] = $actions;

        // Format display fields
        $ret['return_date'] = date('d M Y', strtotime($ret['return_date']));
        $ret['total_amount'] = number_format($ret['total_amount'] ?? 0, 2);
        $ret['reason'] = ucwords(str_replace('_', ' ', $ret['reason'] ?? ''));
        $status_colors = ['pending' => 'warning', 'approved' => 'primary', 'completed' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
        $badge_color = $status_colors[$status] ?? 'secondary';
        $ret['status'] = "<span class='badge bg-{$badge_color}'>" . strtoupper($status) . "</span>";
    }
    unset($ret);

    echo json_encode([
        'success' => true,
        'data' => $returns,
        'recordsTotal' => count($returns),
        'recordsFiltered' => count($returns),
        'stats' => [
            'total_returns' => $total_returns,
            'total_amount'  => $total_amount,
            'pending_count' => $status_counts['pending'],
            'approved_count' => $status_counts['approved'],
            'completed_count' => $status_counts['completed'],
            'rejected_count' => $status_counts['rejected']
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching purchase returns: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
