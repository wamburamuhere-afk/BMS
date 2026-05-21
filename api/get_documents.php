<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    $draw   = isset($_GET['draw'])   ? intval($_GET['draw'])   : 1;
    $start  = isset($_GET['start'])  ? intval($_GET['start'])  : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = $_GET['search']['value'] ?? '';

    $params = [];

    $where = "WHERE 1=1";

    if (!empty($search)) {
        $where .= " AND (d.document_name LIKE :s1 OR c.category_name LIKE :s2)";
        $params[':s1'] = "%$search%";
        $params[':s2'] = "%$search%";
    }

    $countSql = "SELECT COUNT(*) FROM documents d
                 LEFT JOIN document_categories c ON d.category_id = c.id
                 $where";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $totalFiltered = (int)$countStmt->fetchColumn();

    $totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();

    $sql = "SELECT d.id, d.document_name, d.file_path, d.file_size, d.file_type, d.uploaded_at,
                   c.category_name
            FROM documents d
            LEFT JOIN document_categories c ON d.category_id = c.id
            $where
            ORDER BY d.uploaded_at DESC
            LIMIT :start, :length";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':start',  $start,  PDO::PARAM_INT);
    $stmt->bindValue(':length', $length, PDO::PARAM_INT);
    $stmt->execute();
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $totalFiltered,
        'data'            => $documents,
    ]);

} catch (Exception $e) {
    error_log('get_documents.php error: ' . $e->getMessage());
    echo json_encode([
        'draw'            => intval($_GET['draw'] ?? 1),
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => $e->getMessage(),
    ]);
}
