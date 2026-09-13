<?php
// File: api/approve_rfq.php
// Phase 3 — Approve RFQ
// Moves status: review → approved
// Saves: approved_by, approved_by_name, approved_by_role, approved_at (snapshot at action time)

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';
global $pdo;
header('Content-Type: application/json');
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

try {
    if (!isAuthenticated()) throw new Exception(t('Unauthorized'));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception(t('Invalid method'));

    // ── Permission check ──────────────────────────────────────────
    if (!canApprove('rfq')) {
        http_response_code(403);
        throw new Exception(t('You do not have permission to approve RFQs.'));
    }

    $rfq_id = intval($_POST['rfq_id'] ?? 0);
    if (!$rfq_id) throw new Exception(t('RFQ ID is required'));

    // Phase C — block approvals against RFQs on projects not in user scope
    assertScopeForRecord('rfq', 'rfq_id', $rfq_id);

    // ── Fetch current RFQ ─────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfq WHERE rfq_id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rfq) throw new Exception(t('RFQ not found'));

    // ── Sequential enforcement: must be 'review' ──────────────────
    if ($rfq['status'] !== 'review') {
        throw new Exception(
            $rfq['status'] === 'approved'
                ? t('This RFQ has already been approved.')
                : ($rfq['status'] === 'draft'
                    ? t('This RFQ must be reviewed before it can be approved. Please submit it for review first.')
                    : t("Cannot approve an RFQ with status") . " '{$rfq['status']}'.")
        );
    }

    // ── Build approver snapshot (frozen at time of action) ────────
    $actor = workflowActorSnapshot();

    // ── Update RFQ ────────────────────────────────────────────────
    $pdo->prepare("
        UPDATE rfq
        SET status           = 'approved',
            approved_by      = ?,
            approved_by_name = ?,
            approved_by_role = ?,
            approved_at      = NOW(),
            updated_at       = NOW()
        WHERE rfq_id = ?
    ")->execute([
        $_SESSION['user_id'],
        $actor['name'],
        $actor['role'],
        $rfq_id
    ]);

    workflowCaptureSignature($pdo, 'rfq', $rfq_id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity($pdo, $_SESSION['user_id'], "Approved RFQ #{$rfq['rfq_number']}");

    echo json_encode([
        'success'    => true,
        'message'    => sprintf(t('RFQ #%s has been approved successfully.'), $rfq['rfq_number']),
        'new_status' => 'approved'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}