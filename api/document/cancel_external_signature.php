<?php
/**
 * cancel_external_signature.php — lets the person who requested an external
 * signature (or an admin) withdraw it before the signer acts on it. Sets
 * document_signatures.status = 'rejected' (an existing valid enum value —
 * no schema change) and immediately invalidates any outstanding token, so a
 * link sent to the wrong address, or one the sender wants to retract, stops
 * working right away instead of staying live for its full 7-day window.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    csrf_check();

    $signature_id = (int)($_POST['signature_id'] ?? 0);
    if ($signature_id <= 0) {
        throw new Exception('Invalid signature request');
    }

    $stmt = $pdo->prepare("
        SELECT ds.id, ds.document_id, ds.requested_by, ds.signer_name, ds.signer_email, ds.status, ds.signer_type,
               d.document_name
        FROM document_signatures ds
        JOIN documents d ON d.id = ds.document_id
        WHERE ds.id = ?
    ");
    $stmt->execute([$signature_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Signature request not found');
    }
    if ($row['signer_type'] !== 'external') {
        throw new Exception('Only external signature requests can be cancelled here');
    }
    if ((int)$row['requested_by'] !== (int)$_SESSION['user_id'] && !isAdmin()) {
        http_response_code(403);
        throw new Exception('Access denied: only the person who sent this request can cancel it');
    }
    if ($row['status'] !== 'pending') {
        throw new Exception('This request is no longer pending — it has already been ' . $row['status']);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE document_signatures SET status = 'rejected', updated_at = NOW() WHERE id = ?")
            ->execute([$signature_id]);
        $pdo->prepare("UPDATE document_signature_tokens SET used_at = NOW() WHERE signature_id = ? AND used_at IS NULL")
            ->execute([$signature_id]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    logActivity($pdo, $_SESSION['user_id'],
        "Cancelled external signature request on '{$row['document_name']}' (was pending for {$row['signer_name']} <{$row['signer_email']}>)");
    logAudit($pdo, $_SESSION['user_id'], 'cancel_external_signature', [
        'activity_type' => 'update',
        'description'   => "Cancelled external signature request for {$row['signer_name']} <{$row['signer_email']}>",
        'entity_type'   => 'document',
        'entity_id'     => $row['document_id'],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Signature request cancelled — the link sent to ' . $row['signer_email'] . ' no longer works.',
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
