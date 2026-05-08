<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unit_name = trim($_POST['unit_name']);
    $unit_code = trim($_POST['unit_code']);
    
    if (empty($unit_name) || empty($unit_code)) {
        echo json_encode(['success' => false, 'message' => 'Both name and code are required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO product_units (unit_code, unit_name) VALUES (?, ?)");
        $stmt->execute([$unit_code, $unit_name]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Unit added successfully',
            'unit' => ['unit_code' => $unit_code, 'unit_name' => $unit_name]
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => 'Unit code already exists']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
}
?>
