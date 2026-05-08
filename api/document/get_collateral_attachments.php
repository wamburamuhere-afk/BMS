<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get collateral ID
$collateral_id = $_GET['collateral_id'] ?? 0;

if (!$collateral_id) {
    echo json_encode(['success' => false, 'message' => 'Collateral ID is required']);
    exit();
}

try {
    // Fetch collateral attachments
    $query = "SELECT 
                id,
                collateral_id,
                loan_id,
                file_path,
                original_name,
                file_type,
                file_size,
                uploaded_at
              FROM collateral_attachments
              WHERE collateral_id = :collateral_id
              ORDER BY uploaded_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':collateral_id', $collateral_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $attachments,
        'count' => count($attachments)
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching collateral attachments: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching attachments: ' . $e->getMessage()
    ]);
}
?>
