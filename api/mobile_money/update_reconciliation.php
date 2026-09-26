<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('mm_reconciliation')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

csrf_check();

$reconId = intval($_POST['recon_id'] ?? 0);
$action  = trim($_POST['action'] ?? '');

if (!$reconId || !in_array($action, ['resolve', 'disputed'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

$recon = $pdo->prepare("SELECT * FROM mm_reconciliations WHERE recon_id=?");
$recon->execute([$reconId]);
$recon = $recon->fetch(PDO::FETCH_ASSOC);
if (!$recon) { echo json_encode(['success' => false, 'message' => 'Reconciliation not found.']); exit; }
if ($recon['status'] !== 'open') {
    echo json_encode(['success' => false, 'message' => 'Only open reconciliations can be updated.']); exit;
}

$notes = trim($_POST['notes'] ?? '');

try {
    if ($action === 'resolve') {
        $actualCash  = (float)($_POST['actual_cash'] ?? -1);
        $actualFloat = (float)($_POST['actual_float'] ?? -1);
        if ($actualCash < 0 || $actualFloat < 0) {
            echo json_encode(['success' => false, 'message' => 'Actual cash and float are required.']); exit;
        }
        $cashVar  = $actualCash  - (float)$recon['computed_cash'];
        $floatVar = $actualFloat - (float)$recon['computed_float'];

        $pdo->prepare("
            UPDATE mm_reconciliations
            SET actual_cash=?, actual_float=?, cash_variance=?, float_variance=?,
                status='resolved', resolved_notes=?, closed_at=NOW(), closed_by=?
            WHERE recon_id=?
        ")->execute([$actualCash, $actualFloat, $cashVar, $floatVar, $notes ?: null, $_SESSION['user_id'], $reconId]);

        logActivity($pdo, $_SESSION['user_id'], "Resolved MM recon #{$recon['recon_code']} Cash var=$cashVar Float var=$floatVar");
        echo json_encode(['success' => true, 'message' => 'Reconciliation resolved.']);

    } elseif ($action === 'disputed') {
        $pdo->prepare("
            UPDATE mm_reconciliations
            SET status='disputed', resolved_notes=?, closed_by=?
            WHERE recon_id=?
        ")->execute([$notes ?: null, $_SESSION['user_id'], $reconId]);

        logActivity($pdo, $_SESSION['user_id'], "Marked MM recon #{$recon['recon_code']} as disputed");
        echo json_encode(['success' => true, 'message' => 'Reconciliation marked as disputed.']);
    }

} catch (Exception $e) {
    error_log("update_reconciliation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
