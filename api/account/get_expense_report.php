<?php
/**
 * api/account/get_expense_report.php
 *
 * AJAX data source for the Expense Report — summary, three chart datasets, and
 * expense rows as JSON. Read-only.
 *
 * Project-scoped per security.md §23 (expenses.project_id): the same $where_sql
 * feeds the summary, every chart, and the rows.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('expense_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$date_from   = $_GET['date_from']          ?? date('Y-01-01');
$date_to     = $_GET['date_to']            ?? date('Y-12-31');
$account_id  = $_GET['expense_account_id'] ?? '';
$status      = $_GET['status']             ?? '';
$project_id  = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["e.expense_date BETWEEN ? AND ?"];
    if ($account_id !== '') {
        $where[]  = "e.expense_account_id = ?";
        $params[] = (int)$account_id;
    }
    if ($status !== '') {
        $where[]  = "e.status = ?";
        $params[] = $status;
    }
    $scope_sql = '';
    if ($project_id !== null) {
        $where[]  = "e.project_id = ?";
        $params[] = $project_id;
    } else {
        $scope_sql = scopeFilterSqlNullable('project', 'e');
    }
    $where_sql = implode(' AND ', $where) . $scope_sql;

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                          AS entry_count,
               COALESCE(SUM(e.amount), 0)        AS total_amount,
               COALESCE(AVG(e.amount), 0)        AS avg_amount,
               COALESCE(SUM(CASE WHEN e.status IN ('approved','paid') THEN e.amount ELSE 0 END), 0) AS approved_amount
          FROM expenses e
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Chart 1: monthly trend ────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS label,
               COALESCE(SUM(e.amount), 0)           AS value
          FROM expenses e
         WHERE $where_sql
      GROUP BY label ORDER BY label ASC LIMIT 24
    ");
    $stmt->execute($params);
    $monthly_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2: by expense account ───────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(ea.account_name, 'Unclassified') AS name,
               COALESCE(SUM(e.amount), 0)                AS total
          FROM expenses e
          LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
         WHERE $where_sql
      GROUP BY e.expense_account_id, ea.account_name
      ORDER BY total DESC LIMIT 10
    ");
    $stmt->execute($params);
    $by_account = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 3: by status ────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT e.status AS status, COALESCE(SUM(e.amount), 0) AS total
          FROM expenses e
         WHERE $where_sql
      GROUP BY e.status ORDER BY total DESC
    ");
    $stmt->execute($params);
    $by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Detail rows ───────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT e.expense_date, e.reference_number, e.description, e.amount, e.status,
               COALESCE(ea.account_name, 'Unclassified') AS expense_account_name,
               CASE
                   WHEN e.paid_to_type = 'supplier'       THEN (SELECT supplier_name FROM suppliers       WHERE supplier_id  = e.paid_to_id)
                   WHEN e.paid_to_type = 'sub_contractor' THEN (SELECT supplier_name FROM sub_contractors WHERE supplier_id  = e.paid_to_id)
                   WHEN e.paid_to_type = 'staff'          THEN (SELECT CONCAT(first_name,' ',last_name) FROM employees WHERE employee_id = e.paid_to_id)
                   ELSE e.vendor
               END AS paid_to_name
          FROM expenses e
          LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
         WHERE $where_sql
      ORDER BY e.expense_date DESC, e.expense_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'entry_count'     => (int)($summary['entry_count'] ?? 0),
            'total_amount'    => (float)($summary['total_amount'] ?? 0),
            'avg_amount'      => (float)($summary['avg_amount'] ?? 0),
            'approved_amount' => (float)($summary['approved_amount'] ?? 0),
        ],
        'charts' => [
            'monthly_trend' => $monthly_trend,
            'by_account'    => $by_account,
            'by_status'     => $by_status,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_expense_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
