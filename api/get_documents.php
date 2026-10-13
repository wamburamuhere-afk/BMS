<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/document_access.php';
global $pdo;

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }
    if (!canView('document_library')) {
        http_response_code(403);
        throw new Exception('Access Denied');
    }

    $draw   = isset($_GET['draw'])   ? intval($_GET['draw'])   : 1;
    $start  = isset($_GET['start'])  ? intval($_GET['start'])  : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = $_GET['search']['value'] ?? '';

    $params = [];

    $where = "WHERE 1=1";

    // Visibility: this list feeds the signing wizard's document picker, so it
    // must offer exactly the documents the user is actually allowed to fetch —
    // the same rule core/document_access.php enforces on download/view. Without
    // it the picker listed every document, and a non-admin only discovered the
    // refusal at the final step as "Could not fetch original PDF (HTTP 403)".
    if (!canSeeAllDocuments()) {
        $where .= " AND (d.access_level = 'public'
                      OR d.access_level = ''
                      OR d.uploaded_by = :vis_user1
                      OR d.id IN (SELECT document_id FROM document_assignees WHERE user_id = :vis_user2))";
        $params[':vis_user1'] = $_SESSION['user_id'];
        $params[':vis_user2'] = $_SESSION['user_id'];
    }

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

    if (canSeeAllDocuments()) {
        $totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
    } else {
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM documents d
            WHERE d.access_level = 'public'
               OR d.access_level = ''
               OR d.uploaded_by = :vis_user1
               OR d.id IN (SELECT document_id FROM document_assignees WHERE user_id = :vis_user2)
        ");
        $totalStmt->execute([
            ':vis_user1' => $_SESSION['user_id'],
            ':vis_user2' => $_SESSION['user_id'],
        ]);
        $totalRecords = (int)$totalStmt->fetchColumn();
    }

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
