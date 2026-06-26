<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canDelete('crm_leads')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. CSRF + Input validation
csrf_check();

$lead_id = intval($_POST['lead_id'] ?? $_POST['id'] ?? 0);
if (!$lead_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid lead ID']);
    exit;
}

try {
    $scope = scopeFilterSqlNullable('project', 'cl');
    $chk = $pdo->prepare("SELECT lead_code, converted FROM crm_leads cl WHERE cl.lead_id = ? AND cl.status != 'deleted' $scope");
    $chk->execute([$lead_id]);
    $lead = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$lead) {
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }

    if ((int)$lead['converted'] === 1) {
        echo json_encode(['success' => false, 'message' => 'This lead has been converted to a customer and cannot be deleted.']);
        exit;
    }

    // 5. Soft delete
    $pdo->prepare("UPDATE crm_leads SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE lead_id = ?")
        ->execute([$_SESSION['user_id'], $lead_id]);

    // 6. Activity log
    logActivity($pdo, $_SESSION['user_id'], "Delete lead", "deleted lead {$lead['lead_code']} with id $lead_id");

    echo json_encode(['success' => true, 'message' => "Lead {$lead['lead_code']} deleted."]);

} catch (PDOException $e) {
    error_log("delete_lead error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
