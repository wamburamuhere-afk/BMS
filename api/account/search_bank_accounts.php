<?php
/**
 * api/account/search_bank_accounts.php
 * ------------------------------------
 * Select2 AJAX source for bank/cash account pickers. Returns the same "bank
 * nature" set the rest of the system uses — asset accounts classified Bank/Cash
 * (Sub Type is_bank = 1) OR tagged cash_flow_category='cash' (legacy fallback),
 * leaf-only (postable) — searchable by code or name.
 *
 * Label format: "CODE — Account Name" (code on the left, as requested).
 * Response: { results: [{id, text}], pagination: { more } }  (Select2 format)
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated())                  throw new Exception('Unauthorized');
    if (!canView('bank_reconciliation') && !canView('bank_accounts') && !canView('chart_of_accounts')) {
        throw new Exception('Permission denied');
    }

    $q       = trim($_GET['q'] ?? $_GET['search'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $where  = "a.status = 'active' AND a.account_type = 'asset'
               AND (st.is_bank = 1 OR a.cash_flow_category = 'cash')
               AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_account_id = a.account_id AND c.account_id <> a.account_id)";
    $params = [];

    if ($q !== '') {
        $where .= " AND (a.account_code LIKE ? OR a.account_name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    // Count for pagination
    $countSql = "SELECT COUNT(*) FROM accounts a
                  LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
                 WHERE $where";
    $cs = $pdo->prepare($countSql);
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $sql = "SELECT a.account_id, a.account_code, a.account_name
              FROM accounts a
              LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
             WHERE $where
          ORDER BY a.account_code, a.account_name
             LIMIT $perPage OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $results = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'id'   => (int)$r['account_id'],
            'text' => $r['account_code'] . ' — ' . $r['account_name'],
        ];
    }

    echo json_encode([
        'results'    => $results,
        'pagination' => ['more' => ($offset + $perPage) < $total],
    ]);

} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
