<?php
/**
 * api/account/search_customers.php
 *
 * Select2 AJAX source for the Sales Report customer filter. Returns a short,
 * searchable list (show a few, type to find more, per §UI-3).
 *
 * Project-scoped (security.md §23): a non-admin only sees customers who have
 * invoices within their assigned projects (+ untagged). Admins see all
 * customers that have any invoice. Resolved via the invoices.project_id
 * relationship using scopeFilterSqlNullable().
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit;
}
if (!canView('financial_reports')) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    // Only customers that appear on invoices the user is allowed to see — the
    // dropdown can't leak customers belonging solely to other projects.
    $sql = "SELECT DISTINCT c.customer_id, c.customer_name, c.customer_code
              FROM customers c
              JOIN invoices i ON i.customer_id = c.customer_id
             WHERE c.status != 'deleted'";
    $params = [];
    if ($q !== '') {
        $sql     .= " AND (c.customer_name LIKE ? OR c.customer_code LIKE ?)";
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= scopeFilterSqlNullable('project', 'i');
    $sql .= " ORDER BY c.customer_name ASC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = $r['customer_name'];
        if ($r['customer_code']) $text .= ' (' . $r['customer_code'] . ')';
        $results[] = ['id' => (int)$r['customer_id'], 'text' => $text];
    }
    echo json_encode(['results' => $results]);

} catch (Throwable $e) {
    error_log('search_customers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
