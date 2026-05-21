<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        exit;
    }

    $draw   = intval($_GET['draw']   ?? 1);
    $start  = intval($_GET['start']  ?? 0);
    $length = intval($_GET['length'] ?? 10);
    $search = trim($_GET['search']['value'] ?? '');
    $userId = $_SESSION['user_id'];

    $sortCols = [0 => 'id', 1 => 'id', 2 => 'signature_type', 3 => 'created_at', 4 => 'status', 5 => 'id'];
    $orderCol = $sortCols[intval($_GET['order'][0]['column'] ?? 3)] ?? 'created_at';
    $orderDir = (($_GET['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $where  = 'user_id = :uid';
    $params = [':uid' => $userId];

    if ($search !== '') {
        $where .= ' AND (signature_type LIKE :s1 OR status LIKE :s2)';
        $params[':s1'] = "%$search%";
        $params[':s2'] = "%$search%";
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_signatures WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT id, signature_type, file_path, thumbnail_path, status, created_at
        FROM user_signatures
        WHERE $where
        ORDER BY $orderCol $orderDir
        LIMIT :start, :length
    ");
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $dataStmt->bindValue(':start',  $start,  PDO::PARAM_INT);
    $dataStmt->bindValue(':length', $length, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $total,
        'data'            => $rows,
    ]);

} catch (Exception $e) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $e->getMessage()]);
}
