<?php
/**
 * API: Generate Unique Receipt Number
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    global $pdo;
    
    // Logic: RCP-YYYYMMDD-XXXX (Random 4 digits)
    // Retry up to 5 times to find a unique one
    $max_retries = 5;
    $unique = false;
    $receipt_number = '';
    
    for ($i = 0; $i < $max_retries; $i++) {
        $prefix = 'RCP-' . date('Ymd') . '-';
        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $candidate = $prefix . $random;
        
        // Check if exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE receipt_number = ?");
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn() == 0) {
            $receipt_number = $candidate;
            $unique = true;
            break;
        }
    }
    
    if ($unique) {
        echo json_encode([
            'success' => true,
            'receipt_number' => $receipt_number
        ]);
    } else {
        throw new Exception("Failed to generate unique receipt number");
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
