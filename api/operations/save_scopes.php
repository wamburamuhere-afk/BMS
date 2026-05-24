<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $project_id = $_POST['project_id'] ?? null;
    $scope_type = $_POST['scope_type'] ?? 'original';
    $addendum_no = $_POST['addendum_no'] ?? null;
    $items = json_decode($_POST['items'] ?? '[]', true);

    if (!$project_id) throw new Exception('Project ID is required');

    $pdo->beginTransaction();

    // Delete existing items for this specific combination
    if ($scope_type === 'variation') {
        $stmtDelete = $pdo->prepare("DELETE FROM project_milestones WHERE project_id = ? AND scope_type = ? AND addendum_no = ?");
        $stmtDelete->execute([$project_id, $scope_type, $addendum_no]);
    } else {
        $stmtDelete = $pdo->prepare("DELETE FROM project_milestones WHERE project_id = ? AND scope_type = ?");
        $stmtDelete->execute([$project_id, $scope_type]);
    }

    $stmtInsert = $pdo->prepare("INSERT INTO project_milestones (project_id, scope_type, addendum_no, description, unit, scope, amount, tax_rate, tax_amount, weight_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $item) {
        $stmtInsert->execute([
            $project_id,
            $scope_type,
            $addendum_no,
            $item['description'],
            $item['unit'],
            $item['scope'],
            $item['amount'],
            $item['tax_rate'] ?? 0,
            $item['tax_amount'] ?? 0,
            $item['weight_percent'] ?? 0
        ]);
    }

    $pdo->commit();

    // Phase 3c — scopes define billable items; baseline for IPCs.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Saved Project Scopes", "Project ID: " . ($project_id ?? 'unknown'));

    echo json_encode(['success' => true, 'message' => 'Scope saved successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
