<?php
// API: Save designation competency targets (Tier 3, Phase 3.2).
// Upserts expected 1–5 star ratings per indicator for a designation
// (INSERT … ON DUPLICATE KEY UPDATE on uniq_desig_ind). A rating of 0 / empty
// means "no target" and removes any existing row for that indicator.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to set targets']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

try {
    $designation_id = intval($_POST['designation_id'] ?? 0);
    $targets = $_POST['target'] ?? [];   // [indicator_id => rating]
    if (!$designation_id) throw new Exception('Designation is required');
    if (!is_array($targets)) throw new Exception('Invalid targets payload');

    $chk = $pdo->prepare("SELECT designation_id FROM designations WHERE designation_id = ? AND status = 'active'");
    $chk->execute([$designation_id]);
    if (!$chk->fetch()) throw new Exception('Designation does not exist or is inactive');

    $pdo->beginTransaction();

    $upsert = $pdo->prepare("
        INSERT INTO designation_indicator_targets (designation_id, indicator_id, expected_rating, created_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE expected_rating = VALUES(expected_rating), updated_by = VALUES(created_by)
    ");
    $del = $pdo->prepare("DELETE FROM designation_indicator_targets WHERE designation_id = ? AND indicator_id = ?");

    $saved = 0; $cleared = 0;
    foreach ($targets as $indicator_id => $rating) {
        $indicator_id = (int)$indicator_id;
        $rating = (int)$rating;
        if (!$indicator_id) continue;
        if ($rating < 1 || $rating > 5) {
            $del->execute([$designation_id, $indicator_id]);
            $cleared++;
            continue;
        }
        $upsert->execute([$designation_id, $indicator_id, $rating, $_SESSION['user_id']]);
        $saved++;
    }

    logActivity($pdo, $_SESSION['user_id'], 'Save designation targets',
        "set competency targets for designation #$designation_id ($saved set, $cleared cleared)");
    logAudit($pdo, $_SESSION['user_id'], 'update', [
        'activity_type' => 'update',
        'entity_type'   => 'designation_targets',
        'entity_id'     => $designation_id,
        'description'   => "Updated competency target matrix for designation #$designation_id",
        'new_values'    => ['set' => $saved, 'cleared' => $cleared],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Targets saved ($saved set, $cleared cleared)"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
