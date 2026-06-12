<?php
/**
 * api/account/get_account_sub_types.php
 * -------------------------------------
 * Returns the account sub-types (Bank, Cash, Accounts Receivable, Fixed Asset …)
 * for the chart-of-accounts "Sub Type" cascade. GET, read-only.
 *
 *   ?type_id=1     → sub-types under that account_type
 *   ?category=asset → sub-types under the class with that category
 *   (no filter)    → every active sub-type
 */

require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated())            throw new Exception('Unauthorized');
    if (!canView('chart_of_accounts')) throw new Exception('Permission denied');

    $typeId   = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
    $category = trim($_GET['category'] ?? '');

    $where  = ["st.status = 'active'"];
    $params = [];

    if ($typeId > 0) {
        $where[] = "st.type_id = ?";
        $params[] = $typeId;
    } elseif ($category !== '') {
        $where[] = "at.category = ?";
        $params[] = $category;
    }

    $sql = "SELECT st.sub_type_id, st.type_id, st.name, st.code,
                   st.cash_flow_category, st.is_bank, st.liquidity, st.display_order,
                   at.type_name, at.category
              FROM account_sub_types st
              JOIN account_types at ON st.type_id = at.type_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY at.type_id ASC, st.display_order ASC, st.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
}
