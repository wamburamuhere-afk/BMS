<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        SELECT id, document_name, file_type 
        FROM documents 
        ORDER BY document_name ASC
    ");
    $stmt->execute();
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
