<?php
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

    $document_id = $_POST['document_id'] ?? null;
    $document_name = $_POST['document_name'] ?? null;
    $source = $_POST['source'] ?? null;

    if (!$document_id || !$document_name) {
        throw new Exception('Missing required fields');
    }

    $stmt = $pdo->prepare("UPDATE documents SET document_name = ?, source = ? WHERE id = ?");
    $stmt->execute([$document_name, $source, $document_id]);

    logActivity($pdo, $_SESSION['user_id'], "Updated Document Metadata", "Document ID: $document_id, Name: $document_name");

    echo json_encode([
        'success' => true,
        'message' => 'Document updated successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
