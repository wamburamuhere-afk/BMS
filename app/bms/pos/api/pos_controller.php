<?php
/**
 * POS API Controller
 * Handles all API endpoints for Point of Sale operations
 */

// Include required files
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../models/POSModel.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize classes
$security = new POSSecurity($pdo);
$posModel = new POSModel($pdo);

// Set JSON header
header('Content-Type: application/json');

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Validate session
if (!$security->validateSession()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Session expired or invalid'
    ]);
    exit;
}

// Route requests
try {
    switch ($action) {
        case 'get_products':
            handleGetProducts();
            break;
            
        case 'get_product':
            handleGetProduct();
            break;
            
        case 'get_categories':
            handleGetCategories();
            break;
            
        case 'process_sale':
            handleProcessSale();
            break;
            
        case 'hold_sale':
            handleHoldSale();
            break;
            
        case 'get_held_sales':
            handleGetHeldSales();
            break;
            
        case 'load_held_sale':
            handleLoadHeldSale();
            break;
            
        case 'delete_held_sale':
            handleDeleteHeldSale();
            break;
            
        case 'check_stock':
            handleCheckStock();
            break;
            
        case 'end_shift':
            handleEndShift();
            break;
            
        case 'get_cash_balance':
            handleGetCashBalance();
            break;
            
        case 'open_cash_drawer':
            handleOpenCashDrawer();
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
    }
    
} catch (Exception $e) {
    error_log("POS API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

/**
 * Get products with filters and pagination
 */
function handleGetProducts() {
    global $posModel, $security;
    
    // Check rate limit
    if (!$security->checkRateLimit('get_products', 100, 60)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many requests. Please try again later.'
        ]);
        return;
    }
    
    $filters = [
        'category_id' => $_GET['category_id'] ?? null,
        'search' => $security->sanitizeInput($_GET['search'] ?? '', 'string'),
        'in_stock' => isset($_GET['in_stock']) ? (bool)$_GET['in_stock'] : false
    ];
    
    $pagination = [
        'page' => (int)($_GET['page'] ?? 1),
        'limit' => (int)($_GET['limit'] ?? 50)
    ];
    
    $result = $posModel->getProducts($filters, $pagination);
    echo json_encode($result);
}

/**
 * Get single product by ID
 */
function handleGetProduct() {
    global $posModel, $security;
    
    $productId = (int)($_GET['id'] ?? 0);
    
    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid product ID'
        ]);
        return;
    }
    
    $result = $posModel->getProductById($productId);
    echo json_encode($result);
}

/**
 * Get active categories
 */
function handleGetCategories() {
    global $posModel;
    
    $result = $posModel->getCategories();
    echo json_encode($result);
}

/**
 * Process sale transaction
 */
function handleProcessSale() {
    global $posModel, $security;
    
    // Check rate limit
    if (!$security->checkRateLimit('process_sale', 20, 60)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many sale attempts. Please wait.'
        ]);
        return;
    }
    
    // Validate request
    $validation = $security->validateAPIRequest(['items', 'total', 'payment_method']);
    
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validation['errors']
        ]);
        return;
    }
    
    $data = $validation['data'];
    
    // Validate sale data
    $rules = [
        'items' => ['required' => true],
        'total' => ['required' => true, 'numeric' => true, 'min' => 0],
        'payment_method' => ['required' => true, 'in' => ['cash', 'card', 'mobile_money', 'bank_transfer', 'credit']]
    ];
    
    $inputValidation = $security->validateInput($data, $rules);
    
    if (!$inputValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid sale data',
            'errors' => $inputValidation['errors']
        ]);
        return;
    }
    
    // Sanitize data
    $saleData = [
        'receipt_number' => $security->sanitizeInput($data['receipt_number'], 'string'),
        'customer_id' => isset($data['customer_id']) ? (int)$data['customer_id'] : null,
        'user_id' => $_SESSION['user_id'],
        'shift_id' => isset($data['shift_id']) ? (int)$data['shift_id'] : null,
        'subtotal' => (float)$data['subtotal'],
        'tax' => (float)$data['tax'],
        'discount' => (float)($data['discount'] ?? 0),
        'shipping' => (float)($data['shipping'] ?? 0),
        'total' => (float)$data['total'],
        'payment_method' => $data['payment_method'],
        'amount_tendered' => (float)($data['amount_tendered'] ?? $data['total']),
        'change_given' => (float)($data['change_given'] ?? 0),
        'items' => $data['items']
    ];
    
    // Process sale
    $result = $posModel->processSale($saleData);
    
    // Log audit
    if ($result['success']) {
        $security->logAudit('sale_processed', [
            'entity_type' => 'sale',
            'entity_id' => $result['data']['sale_id'],
            'new_values' => [
                'total' => $saleData['total'],
                'payment_method' => $saleData['payment_method']
            ]
        ]);
    }
    
    echo json_encode($result);
}

/**
 * Hold sale for later
 */
function handleHoldSale() {
    global $posModel, $security;
    
    $validation = $security->validateAPIRequest(['items', 'total']);
    
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validation['errors']
        ]);
        return;
    }
    
    $data = $validation['data'];
    
    $saleData = [
        'user_id' => $_SESSION['user_id'],
        'shift_id' => isset($data['shift_id']) ? (int)$data['shift_id'] : null,
        'customer_id' => isset($data['customer_id']) ? (int)$data['customer_id'] : null,
        'reference' => $security->sanitizeInput($data['reference'] ?? '', 'string'),
        'items' => $data['items'],
        'subtotal' => (float)$data['subtotal'],
        'tax' => (float)$data['tax'],
        'total' => (float)$data['total']
    ];
    
    $result = $posModel->holdSale($saleData);
    
    if ($result['success']) {
        $security->logAudit('sale_held', [
            'entity_type' => 'held_sale',
            'entity_id' => $result['data']['hold_id']
        ]);
    }
    
    echo json_encode($result);
}

/**
 * Get held sales
 */
function handleGetHeldSales() {
    global $posModel;
    
    $shiftId = isset($_GET['shift_id']) ? (int)$_GET['shift_id'] : null;
    $result = $posModel->getHeldSales($shiftId);
    
    echo json_encode($result);
}

/**
 * Load held sale
 */
function handleLoadHeldSale() {
    global $pdo, $security;
    
    $holdId = (int)($_GET['hold_id'] ?? 0);
    
    if ($holdId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid hold ID'
        ]);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM held_sales
            WHERE hold_id = ? AND status = 'held'
        ");
        $stmt->execute([$holdId]);
        $heldSale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$heldSale) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Held sale not found'
            ]);
            return;
        }
        
        $data = [
            'customer_id' => $heldSale['customer_id'],
            'items' => json_decode($heldSale['items_data'], true)
        ];
        
        // Mark as loaded
        $updateStmt = $pdo->prepare("
            UPDATE held_sales SET status = 'loaded' WHERE hold_id = ?
        ");
        $updateStmt->execute([$holdId]);
        
        $security->logAudit('held_sale_loaded', [
            'entity_type' => 'held_sale',
            'entity_id' => $holdId
        ]);
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        
    } catch (PDOException $e) {
        error_log("Load held sale error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load held sale'
        ]);
    }
}

/**
 * Delete held sale
 */
function handleDeleteHeldSale() {
    global $pdo, $security;
    
    $validation = $security->validateAPIRequest(['hold_id']);
    
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validation['errors']
        ]);
        return;
    }
    
    $holdId = (int)$validation['data']['hold_id'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE held_sales SET status = 'deleted' WHERE hold_id = ?
        ");
        $stmt->execute([$holdId]);
        
        $security->logAudit('held_sale_deleted', [
            'entity_type' => 'held_sale',
            'entity_id' => $holdId
        ]);
        // Activity Log feed (audit_log.md): never silent.
        if (function_exists('logActivity') && !empty($_SESSION['user_id'])) {
            logActivity($pdo, (int)$_SESSION['user_id'], 'Delete held sale',
                "deleted held sale with id {$holdId}");
        }

        echo json_encode([
            'success' => true,
            'message' => 'Held sale deleted'
        ]);
        
    } catch (PDOException $e) {
        error_log("Delete held sale error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete held sale'
        ]);
    }
}

/**
 * Check stock availability
 */
function handleCheckStock() {
    global $posModel;
    
    $productId = (int)($_GET['product_id'] ?? 0);
    
    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid product ID'
        ]);
        return;
    }
    
    $result = $posModel->checkStock($productId);
    echo json_encode($result);
}

/**
 * End shift
 */
function handleEndShift() {
    global $posModel, $security;
    
    $validation = $security->validateAPIRequest(['shift_id', 'ending_cash']);
    
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validation['errors']
        ]);
        return;
    }
    
    $data = $validation['data'];
    $shiftId = (int)$data['shift_id'];
    $endingCash = (float)$data['ending_cash'];
    $notes = $security->sanitizeInput($data['notes'] ?? '', 'string');
    
    $result = $posModel->endShift($shiftId, $endingCash, $notes);
    
    if ($result['success']) {
        unset($_SESSION['shift_id']);
        
        $security->logAudit('shift_ended', [
            'entity_type' => 'shift',
            'entity_id' => $shiftId,
            'new_values' => [
                'ending_cash' => $endingCash
            ]
        ]);
    }
    
    echo json_encode($result);
}

/**
 * Get cash balance
 */
function handleGetCashBalance() {
    global $pdo;
    
    $shiftId = (int)($_GET['shift_id'] ?? 0);
    
    if ($shiftId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid shift ID'
        ]);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END), 0) as cash_in,
                COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) as cash_out,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'sale' THEN amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'refund' THEN amount ELSE 0 END), 0) as cash_refunds
            FROM cash_register_transactions 
            WHERE shift_id = ?
        ");
        $stmt->execute([$shiftId]);
        $cashData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $shiftStmt = $pdo->prepare("SELECT starting_cash FROM cash_register_shifts WHERE shift_id = ?");
        $shiftStmt->execute([$shiftId]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
        
        $startingCash = $shift['starting_cash'] ?? 0;
        $balance = $startingCash + 
                   $cashData['cash_in'] - 
                   $cashData['cash_out'] + 
                   $cashData['cash_sales'] - 
                   $cashData['cash_refunds'];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'balance' => number_format($balance, 2)
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Get cash balance error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to get cash balance'
        ]);
    }
}

/**
 * Open cash drawer
 */
function handleOpenCashDrawer() {
    global $security;
    
    // Log the action
    $security->logAudit('cash_drawer_opened', []);
    
    // In a real implementation, this would send a command to the cash drawer
    // For now, just return success
    echo json_encode([
        'success' => true,
        'message' => 'Cash drawer opened'
    ]);
}
