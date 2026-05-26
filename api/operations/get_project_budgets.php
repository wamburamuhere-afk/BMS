<?php
// File: api/operations/get_project_budgets.php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$project_id = intval($_GET['project_id'] ?? 0);
$page       = max(1, intval($_GET['page'] ?? 1));
$per_page_raw = $_GET['per_page'] ?? '10';
$per_page   = $per_page_raw === 'all' ? 0 : max(1, intval($per_page_raw));
$year       = isset($_GET['year'])  && $_GET['year']  !== 'all' ? intval($_GET['year'])  : null;
$month      = isset($_GET['month']) && $_GET['month'] !== 'all' ? intval($_GET['month']) : null;
$type       = $_GET['type'] ?? 'all'; // 'all', 'inventory', 'non_inventory'

if (!$project_id) { echo json_encode(['success'=>false,'message'=>'Project ID required']); exit; }
assertScopeForRecord('projects', 'project_id', $project_id);

try {
    $where  = ['b.project_id = ?'];
    $params = [$project_id];
    if ($year)  { $where[] = 'b.budget_year  = ?'; $params[] = $year;  }
    if ($month) { $where[] = 'b.budget_month = ?'; $params[] = $month; }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    // Fetch all matching (type filter applied in PHP due to JSON field)
    $stmt = $pdo->prepare("
        SELECT b.budget_id, b.category_id, b.budget_year, b.budget_month,
               b.allocated_amount, b.actual_amount, b.status, b.notes,
               b.line_items, b.payment_reference, b.attachment,
               b.rejection_reason, b.created_at,
               ec.name AS category_name,
               u1.username AS created_by_name
        FROM budgets b
        LEFT JOIN expense_categories ec ON b.category_id = ec.id
        LEFT JOIN users u1 ON b.created_by = u1.user_id
        $where_sql
        ORDER BY b.budget_year DESC, b.budget_month DESC, b.budget_id DESC
    ");
    $stmt->execute($params);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply type filter
    if ($type !== 'all') {
        $all = array_values(array_filter($all, function($b) use ($type) {
            $is_svc = false;
            if (!empty($b['line_items'])) {
                $p = json_decode($b['line_items'], true);
                if (is_array($p) && isset($p['is_service'])) $is_svc = (bool)$p['is_service'];
            }
            return $type === 'non_inventory' ? $is_svc : !$is_svc;
        }));
    }

    $total = count($all);
    $pages = $per_page > 0 ? (int)ceil($total / $per_page) : 1;
    $page  = min($page, max(1, $pages));

    // Paginate
    $slice = $per_page > 0 ? array_slice($all, ($page - 1) * $per_page, $per_page) : $all;

    // Get actual expenses for each budget item
    $exp_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0)
        FROM expenses e
        JOIN expense_category_map ecm ON e.expense_id = ecm.expense_id
        WHERE ecm.category_id = ?
          AND e.status IN ('approved','paid')
          AND e.project_id = ?
    ");
    foreach ($slice as &$b) {
        $exp_stmt->execute([$b['category_id'], $project_id]);
        $b['actual_amount'] = floatval($exp_stmt->fetchColumn());
    }
    unset($b);

    echo json_encode([
        'success'  => true,
        'data'     => array_values($slice),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => $pages
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
