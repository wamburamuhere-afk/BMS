<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $bracket_name = $_POST['bracket_name'] ?? '';
    $country = $_POST['country'] ?? 'Tanzania';
    $min_income = $_POST['min_income'] ?? 0;
    $max_income = $_POST['max_income'] ?? null;
    $tax_rate = $_POST['tax_rate'] ?? 0;
    $effective_from = $_POST['effective_from'] ?? date('Y-m-d');
    
    if (empty($bracket_name) || empty($tax_rate)) {
        throw new Exception('Bracket name and tax rate are required');
    }
    
    // Convert empty max_income to NULL
    if (empty($max_income)) {
        $max_income = null;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO tax_brackets 
        (bracket_name, country, min_income, max_income, tax_rate, effective_from, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    
    $stmt->execute([
        $bracket_name,
        $country,
        $min_income,
        $max_income,
        $tax_rate,
        $effective_from
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Tax bracket added successfully',
        'bracket_id' => $pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
