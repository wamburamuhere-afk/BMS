<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['results' => []]); exit;
}

$term  = trim($_GET['q'] ?? '');
$page  = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$off   = ($page - 1) * $limit;

try {
    $like   = "%$term%";
    $params = [$like];

    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM marketing_campaigns
        WHERE is_deleted = 0 AND campaign_name LIKE ?
    ");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $off;
    $stmt = $pdo->prepare("
        SELECT campaign_id AS id, campaign_name AS text
        FROM marketing_campaigns
        WHERE is_deleted = 0 AND campaign_name LIKE ?
        ORDER BY campaign_name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'results'    => $rows,
        'pagination' => ['more' => ($off + $limit) < $total],
    ]);

} catch (PDOException $e) {
    error_log('get_campaigns_select error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
