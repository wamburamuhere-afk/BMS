<?php
/**
 * API: Hold POS Sale
 * Save current cart for later retrieval
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    global $pdo;
    
    $user_id = $_SESSION['user_id'];
    $customer_id = $_POST['customer_id'] ?? null;
    $reference = $_POST['reference'] ?? null;
    $items = json_decode($_POST['items'] ?? '[]', true);
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax = floatval($_POST['tax'] ?? 0);
    
    if (empty($items)) {
        throw new Exception("No items to hold");
    }
    
    // Get active shift
    $stmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$user_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    $shift_id = $shift['shift_id'] ?? null;
    
    // Generate hold reference
    $hold_reference = $reference ?: 'HOLD-' . date('Ymd-His');
    
    // Insert held sale
    $stmt = $pdo->prepare("
        INSERT INTO pos_held_sales (
            user_id, shift_id, customer_id, hold_reference,
            items_data, subtotal, tax_amount, total_amount, held_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $total = $subtotal + $tax;
    
    $stmt->execute([
        $user_id,
        $shift_id,
        $customer_id,
        $hold_reference,
        json_encode($items),
        $subtotal,
        $tax,
        $total
    ]);
    
    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Hold POS Sale', "$username held a POS sale (Ref: $hold_reference, Total: " . number_format($total, 2) . ")");

    echo json_encode([
        'success' => true,
        'message' => 'Sale held successfully',
        'hold_id' => $pdo->lastInsertId(),
        'reference' => $hold_reference
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
