<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    $stmt = $pdo->prepare("
        SELECT id, signature_type, file_path, thumbnail_path
        FROM user_signatures
        WHERE user_id = ? AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $signatures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($signatures);

} catch (Exception $e) {
    echo json_encode([]);
}
