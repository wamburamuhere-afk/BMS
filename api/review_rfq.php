<?php
// File: api/review_rfq.php
// Phase 3 — Submit RFQ for Review
// Moves status: draft → review
// Saves: reviewed_by, reviewed_by_name, reviewed_by_role, reviewed_at (snapshot at action time)

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid request method');

    // ── Permission check ──────────────────────────────────────────
    if (!canReview('rfq')) {
        http_response_code(403);
        throw new Exception('You do not have permission to review RFQs.');
    }

    $rfq_id = intval($_POST['rfq_id'] ?? 0);
    if (!$rfq_id) throw new Exception('RFQ ID is required');

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('rfq', 'rfq_id', $rfq_id);
    }

    // ── Fetch current RFQ ─────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfq WHERE rfq_id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rfq) throw new Exception('RFQ not found');

    // ── Sequential enforcement: must be draft ─────────────────────
    if ($rfq['status'] !== 'draft') {
        throw new Exception(
            $rfq['status'] === 'review'
                ? 'This RFQ has already been submitted for review.'
                : "Cannot review an RFQ with status '{$rfq['status']}'. Only draft RFQs can be submitted for review."
        );
    }

    // ── Build reviewer snapshot (frozen at time of action) ────────
    $actor = workflowActorSnapshot();

    // ── Update RFQ ────────────────────────────────────────────────
    $pdo->prepare("
        UPDATE rfq
        SET status           = 'review',
            reviewed_by      = ?,
            reviewed_by_name = ?,
            reviewed_by_role = ?,
            reviewed_at      = NOW(),
            updated_at       = NOW()
        WHERE rfq_id = ?
    ")->execute([
        $_SESSION['user_id'],
        $actor['name'],
        $actor['role'],
        $rfq_id
    ]);

    workflowCaptureSignature($pdo, 'rfq', $rfq_id, 'reviewed',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity($pdo, $_SESSION['user_id'], "Submitted RFQ #{$rfq['rfq_number']} for review");

    echo json_encode([
        'success' => true,
        'message' => "RFQ #{$rfq['rfq_number']} has been submitted for review successfully.",
        'new_status' => 'review'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
