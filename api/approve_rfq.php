<?php
// File: api/approve_rfq.php
// Phase 3 — Approve RFQ
// Moves status: review → approved
// Saves: approved_by, approved_by_name, approved_by_role, approved_at (snapshot at action time)

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    // ── Permission check ──────────────────────────────────────────
    if (!canApprove('rfq')) {
        http_response_code(403);
        throw new Exception('You do not have permission to approve RFQs.');
    }

    $rfq_id = intval($_POST['rfq_id'] ?? 0);
    if (!$rfq_id) throw new Exception('RFQ ID is required');

    // ── Fetch current RFQ ─────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfq WHERE rfq_id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rfq) throw new Exception('RFQ not found');

    // ── Sequential enforcement: must be 'review' ──────────────────
    if ($rfq['status'] !== 'review') {
        throw new Exception(
            $rfq['status'] === 'approved'
                ? 'This RFQ has already been approved.'
                : ($rfq['status'] === 'draft'
                    ? 'This RFQ must be reviewed before it can be approved. Please submit it for review first.'
                    : "Cannot approve an RFQ with status '{$rfq['status']}'.")
        );
    }

    // ── Build approver snapshot (frozen at time of action) ────────
    $approver_name = trim(
        ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')
    );
    if (!$approver_name) $approver_name = $_SESSION['username'] ?? 'Unknown';

    $approver_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Staff';

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
        $approver_name,
        $approver_role,
        $rfq_id
    ]);

    logActivity($pdo, $_SESSION['user_id'], "Approved RFQ #{$rfq['rfq_number']}");

    echo json_encode([
        'success'    => true,
        'message'    => "RFQ #{$rfq['rfq_number']} has been approved successfully.",
        'new_status' => 'approved'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}