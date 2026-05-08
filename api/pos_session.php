<?php
// File: api/pos_session.php
// AJAX API for syncing POS cart to customer display
header('Content-Type: application/json');
session_start();

// Allow CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Update cart from main POS
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (isset($data['cart'])) {
        $_SESSION['pos_cart'] = $data['cart'];
        $_SESSION['pos_cart_updated'] = time();
        
        echo json_encode([
            'success' => true,
            'message' => 'Cart updated',
            'timestamp' => $_SESSION['pos_cart_updated']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid data'
        ]);
    }
    
} elseif ($method === 'GET') {
    // Get cart for customer display
    $cart = isset($_SESSION['pos_cart']) ? $_SESSION['pos_cart'] : [];
    $updated = isset($_SESSION['pos_cart_updated']) ? $_SESSION['pos_cart_updated'] : 0;
    
    // Calculate totals
    $subtotal = 0;
    $totalTax = 0;
    
    foreach ($cart as $item) {
        $itemSubtotal = $item['price'] * $item['quantity'];
        $itemTax = $itemSubtotal * ($item['tax_rate'] / 100);
        $subtotal += $itemSubtotal;
        $totalTax += $itemTax;
    }
    
    $total = $subtotal + $totalTax;
    
    echo json_encode([
        'success' => true,
        'cart' => $cart,
        'summary' => [
            'subtotal' => $subtotal,
            'tax' => $totalTax,
            'total' => $total,
            'item_count' => count($cart)
        ],
        'timestamp' => $updated
    ]);
    
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}
