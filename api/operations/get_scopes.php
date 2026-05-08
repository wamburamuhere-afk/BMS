<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    
    $project_id = $_GET['project_id'] ?? null;
    $scope_type = $_GET['scope_type'] ?? 'original';
    $addendum_no = $_GET['addendum_no'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');
    
    // Check for metadata request (used nos)
    if (isset($_GET['meta_only']) && $scope_type === 'variation') {
        $stmtMeta = $pdo->prepare("SELECT DISTINCT IFNULL(addendum_no, '') FROM project_milestones WHERE project_id = ? AND scope_type = 'variation' ORDER BY CAST(addendum_no AS UNSIGNED) ASC");
        $stmtMeta->execute([$project_id]);
        $used_nos = $stmtMeta->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'used_nos' => $used_nos]);
        exit;
    }

    // Check for summary request
    if (isset($_GET['summary_only'])) {
        $summary = [];
        $types = ['original', 'revised', 'variation', 'additional'];
        foreach ($types as $type) {
            $stmtSum = $pdo->prepare("SELECT SUM((scope * amount) + tax_amount) FROM project_milestones WHERE project_id = ? AND scope_type = ?");
            $stmtSum->execute([$project_id, $type]);
            $summary[$type] = (float)$stmtSum->fetchColumn();
        }
        echo json_encode(['success' => true, 'summary' => $summary]);
        exit;
    }

    $sql = "SELECT * FROM project_milestones WHERE project_id = ? AND scope_type = ?";
    $params = [$project_id, $scope_type];

    if ($scope_type === 'variation') {
        $sql .= " AND (addendum_no = ? OR addendum_no IS NULL)";
        $params[] = $addendum_no;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also fetch the signed document for this combination
    $sqlDoc = "SELECT file_name, file_path FROM project_scope_documents WHERE project_id = ? AND scope_type = ?";
    $paramsDoc = [$project_id, $scope_type];
    if ($scope_type === 'variation') {
        $sqlDoc .= " AND (addendum_no = ? OR addendum_no IS NULL)";
        $paramsDoc[] = $addendum_no;
    } else {
        $sqlDoc .= " AND addendum_no IS NULL";
    }
    $stmtDoc = $pdo->prepare($sqlDoc);
    $stmtDoc->execute($paramsDoc);
    $document = $stmtDoc->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $items, 'document' => $document]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
