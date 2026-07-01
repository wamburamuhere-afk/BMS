<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    csrf_check();

    if (!canDelete('e_signatures')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete signatures');
    }

    $signature_id = $_POST['id'] ?? 0;
    
    if (!$signature_id) {
        throw new Exception('Signature ID is required');
    }
    
    // Get signature details first
    $stmt = $pdo->prepare("SELECT file_path, thumbnail_path FROM user_signatures WHERE id = ? AND user_id = ?");
    $stmt->execute([$signature_id, $_SESSION['user_id']]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$signature) {
        throw new Exception("Signature not found or you don't have permission to delete it");
    }

    // Delete files from server
    if ($signature['file_path'] && file_exists($signature['file_path'])) {
        unlink($signature['file_path']);
    }
    if ($signature['thumbnail_path'] && file_exists($signature['thumbnail_path'])) {
        unlink($signature['thumbnail_path']);
    }

    // Delete database record
    $stmt = $pdo->prepare("DELETE FROM user_signatures WHERE id = ?");
    $stmt->execute([$signature_id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete signature", "deleted user signature with id $signature_id");

    echo json_encode([
        'success' => true,
        'message' => 'Signature deleted successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
