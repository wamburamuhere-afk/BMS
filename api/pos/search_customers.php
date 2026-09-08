<?php
/**
 * API: Select2 AJAX customer search for the POS terminal (Phase 10, pos_upgrade_plan.md §7)
 * Replaces the old plain <select> limited to 50 rows with no search.
 * GET ?q=<term>
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/pos_credit_limit.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['results' => []]); exit; }

global $pdo;

$q = trim($_GET['q'] ?? '');

try {
    $sql = "SELECT customer_id, customer_name, customer_code, phone, mobile, loyalty_points_balance, default_price_group_id, credit_limit
              FROM customers c
             WHERE status = 'active'";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (customer_name LIKE ? OR customer_code LIKE ? OR phone LIKE ? OR mobile LIKE ?)";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
    }
    // Project-scope (security.md §23) — customers.project_id exists; a non-admin
    // must not see customers tagged to a project they are not assigned to.
    $sql .= scopeFilterSqlNullable('project', 'c');
    $sql .= " ORDER BY customer_name ASC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = $r['customer_name'];
        $phone = $r['mobile'] ?: $r['phone'];
        if ($phone) $text .= ' — ' . $phone;
        $results[] = [
            'id' => (int)$r['customer_id'],
            'text' => $text,
            'loyalty_points' => (int)$r['loyalty_points_balance'],
            // Phase 14 (pos_upgrade_plan.md §8) — auto-applies this customer's
            // price tier when selected; null when they have none set.
            'default_price_group_id' => $r['default_price_group_id'] !== null ? (int)$r['default_price_group_id'] : null,
            // Phase 19 (pos_upgrade_plan.md §8) — shown next to the customer
            // once selected so a cashier sees available credit before typing
            // a credit sale, rather than finding out only after it's blocked.
            'credit_limit' => (float)$r['credit_limit'],
            'outstanding_balance' => customerOutstandingBalance($pdo, (int)$r['customer_id']),
        ];
    }
    echo json_encode(['results' => $results]);

} catch (Throwable $e) {
    error_log('pos/search_customers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
