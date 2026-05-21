<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    csrf_check();

    $document_id  = intval($_POST['document_id']  ?? 0);
    $signature_id = intval($_POST['signature_id'] ?? 0);
    $position     = $_POST['signature_position']  ?? 'bottom_right';

    if (!$document_id || !$signature_id) {
        throw new Exception('Document and signature are required');
    }

    $allowed_positions = ['bottom_right', 'bottom_left', 'bottom_center', 'custom'];
    if (!in_array($position, $allowed_positions, true)) {
        throw new Exception('Invalid signature position');
    }

    // Verify the signature belongs to the current user and is active
    $stmt = $pdo->prepare("SELECT id FROM user_signatures WHERE id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$signature_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Signature not found or not active');
    }

    // Verify document exists
    $stmt = $pdo->prepare("SELECT id FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Document not found');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userId = $_SESSION['user_id'];

    // If there is a pending signature request for this document+user, mark it signed
    $stmt = $pdo->prepare("
        SELECT id FROM document_signatures
        WHERE document_id = ? AND signed_by = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$document_id, $userId]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $stmt = $pdo->prepare("
            UPDATE document_signatures
            SET signature_id = ?, signature_position = ?, ip_address = ?, status = 'signed', signed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$signature_id, $position, $ip, $pending['id']]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO document_signatures
                (document_id, signature_id, requested_by, signed_by, signature_position, ip_address, status, signed_at)
            VALUES (?, ?, ?, ?, ?, ?, 'signed', NOW())
        ");
        $stmt->execute([$document_id, $signature_id, $userId, $userId, $position, $ip]);
    }

    logActivity($pdo, $userId, "Applied e-signature to document ID: $document_id");

    echo json_encode(['success' => true, 'message' => 'Signature applied successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
