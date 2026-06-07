<?php
// api/get_chart_of_accounts.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

// Enable CORS if needed
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

try {
    // Get the request parameters for DataTables
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $searchValue = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    $orderColumn = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $orderDirection = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'ASC';
    $categoryId = isset($_GET['category_id']) ? $_GET['category_id'] : '';
    $accountType = isset($_GET['account_type']) ? $_GET['account_type'] : '';
    // Canonical category class (account_types.category) — drives the type tabs:
    // asset | liability | equity | revenue | cogs | expense | finance_cost
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';

    // Column mapping for ordering
    $columns = [
        0 => 'a.account_code',
        1 => 'a.account_name',
        2 => 'at.type_name',
        3 => 'c.category_name',
        4 => 'a.current_balance',
        5 => 'a.status'
    ];

    $orderBy = $columns[$orderColumn] . ' ' . $orderDirection;

    // Build the base query
    $baseQuery = "
        FROM accounts a
        LEFT JOIN account_categories c ON a.category_id = c.category_id
        LEFT JOIN accounts pa ON a.parent_account_id = pa.account_id
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN acct_tree atr ON atr.account_id = a.account_id
        WHERE 1=1
    ";

    // Apply category filter
    if (!empty($categoryId)) {
        $baseQuery .= " AND a.category_id = :category_id";
    }

    // Apply account type filter
    if (!empty($accountType)) {
        $baseQuery .= " AND at.type_name = :account_type";
    }

    // Apply canonical category filter (the type tab bar)
    if (!empty($category)) {
        $baseQuery .= " AND at.category = :category";
    }

    // Apply status filter
    if (!empty($status)) {
        $baseQuery .= " AND a.status = :status";
    }

    // Apply search filter
    if (!empty($searchValue)) {
        $baseQuery .= " AND (
            a.account_code LIKE :search OR
            a.account_name LIKE :search OR
            at.type_name LIKE :search OR
            c.category_name LIKE :search OR
            a.description LIKE :search
        )";
    }

    // Tree ordering: a materialized path (root code › child code › …) so every
    // account sorts directly beneath its parent — the indented structure of a
    // professional chart of accounts. Built once via a recursive CTE; the 1:1
    // join leaves COUNT(*) unchanged. Roots = no parent, self-loop, or orphan.
    $treeCte = "
        WITH RECURSIVE acct_tree AS (
            SELECT account_id, CAST(account_code AS CHAR(500)) AS sort_path
              FROM accounts
             WHERE parent_account_id IS NULL
                OR parent_account_id = account_id
                OR parent_account_id NOT IN (SELECT account_id FROM accounts)
            UNION ALL
            SELECT a.account_id, CONCAT(t.sort_path, '>', a.account_code)
              FROM accounts a
              JOIN acct_tree t ON a.parent_account_id = t.account_id
             WHERE a.account_id <> a.parent_account_id
        )
    ";

    // Count total records
    $countQuery = $treeCte . "SELECT COUNT(*) as total_count " . $baseQuery;
    $stmt = $pdo->prepare($countQuery);
    
    if (!empty($categoryId)) {
        $stmt->bindValue(':category_id', $categoryId);
    }
    
    if (!empty($accountType)) {
        $stmt->bindValue(':account_type', $accountType);
    }

    if (!empty($category)) {
        $stmt->bindValue(':category', $category);
    }

    if (!empty($status)) {
        $stmt->bindValue(':status', $status);
    }
    
    if (!empty($searchValue)) {
        $searchParam = "%$searchValue%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->execute();
    $totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total_count'];

    // Count filtered records
    $filteredQuery = $treeCte . "SELECT COUNT(*) as filtered_count " . $baseQuery;
    $stmt = $pdo->prepare($filteredQuery);
    
    if (!empty($categoryId)) {
        $stmt->bindValue(':category_id', $categoryId);
    }
    
    if (!empty($accountType)) {
        $stmt->bindValue(':account_type', $accountType);
    }

    if (!empty($category)) {
        $stmt->bindValue(':category', $category);
    }

    if (!empty($status)) {
        $stmt->bindValue(':status', $status);
    }
    
    if (!empty($searchValue)) {
        $searchParam = "%$searchValue%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->execute();
    $filteredRecords = $stmt->fetch(PDO::FETCH_ASSOC)['filtered_count'];

    // Get the actual data — ordered as a TREE (each account beneath its parent)
    $dataQuery = $treeCte . "
        SELECT
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name as account_type,
            a.category_id,
            c.category_name,
            a.description,
            a.opening_balance,
            a.current_balance,
            a.parent_account_id,
            pa.account_name as parent_account_name,
            a.level,
            a.is_system,
            a.normal_balance,
            at.category,
            a.status,
            a.created_at,
            a.updated_at
        " . $baseQuery . "
        ORDER BY atr.sort_path, a.account_id
        LIMIT :start, :length
    ";

    $stmt = $pdo->prepare($dataQuery);
    
    if (!empty($categoryId)) {
        $stmt->bindValue(':category_id', $categoryId);
    }
    
    if (!empty($accountType)) {
        $stmt->bindValue(':account_type', $accountType);
    }

    if (!empty($category)) {
        $stmt->bindValue(':category', $category);
    }

    if (!empty($status)) {
        $stmt->bindValue(':status', $status);
    }
    
    if (!empty($searchValue)) {
        $searchParam = "%$searchValue%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':length', $length, PDO::PARAM_INT);
    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Roll-up (MYOB-style): each account's balance INCLUDING its descendants.
    // One recursive pass maps every account → {self + all descendants}; we sum
    // current_balance per root and count descendants, then attach to the page
    // rows. Parent rows can then show the rolled-up total; leaves show their own.
    $rollup = [];
    try {
        $rsql = "
            WITH RECURSIVE subtree AS (
                SELECT account_id AS root_id, account_id AS node_id, current_balance
                  FROM accounts
                UNION ALL
                SELECT s.root_id, a.account_id, a.current_balance
                  FROM subtree s
                  JOIN accounts a ON a.parent_account_id = s.node_id
                 WHERE a.account_id <> a.parent_account_id      -- defensive: ignore self-loops
            )
            SELECT root_id,
                   SUM(current_balance) AS balance_incl,
                   COUNT(*) - 1         AS descendant_count
              FROM subtree
             GROUP BY root_id
        ";
        foreach ($pdo->query($rsql) as $r) {
            $rollup[(int)$r['root_id']] = [
                'balance_incl'     => $r['balance_incl'],
                'descendant_count' => (int)$r['descendant_count'],
            ];
        }
    } catch (Exception $e) {
        // Recursive CTE unsupported on this server → fall back to own balances.
        $rollup = [];
    }
    foreach ($data as &$row) {
        $aid = (int)$row['account_id'];
        $row['has_children'] = (isset($rollup[$aid]) && $rollup[$aid]['descendant_count'] > 0) ? 1 : 0;
        $row['balance_incl'] = isset($rollup[$aid]) ? $rollup[$aid]['balance_incl'] : $row['current_balance'];
    }
    unset($row);

    // Prepare the response
    $response = [
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data,
        'success' => true
    ];

    echo json_encode($response);

} catch (Exception $e) {
    // Handle errors
    $response = [
        'draw' => isset($draw) ? $draw : 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'success' => false,
        'message' => 'Error fetching accounts: ' . $e->getMessage()
    ];
    
    echo json_encode($response);
}
?>
