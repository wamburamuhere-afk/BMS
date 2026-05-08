<?php
/**
 * POS Model - Database operations for Point of Sale
 * Handles all database interactions with proper error handling and transactions
 */

class POSModel {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Get products with filters and pagination
     * @param array $filters Filter criteria
     * @param array $pagination Pagination settings
     * @return array Products data
     */
    public function getProducts($filters = [], $pagination = ['page' => 1, 'limit' => 50]) {
        try {
            $page = max(1, $pagination['page']);
            $limit = min(100, max(1, $pagination['limit']));
            $offset = ($page - 1) * $limit;
            
            $where = ["p.status = 'active'"];
            $params = [];
            
            // Category filter
            if (!empty($filters['category_id'])) {
                $where[] = "p.category_id = ?";
                $params[] = $filters['category_id'];
            }
            
            // Search filter
            if (!empty($filters['search'])) {
                $where[] = "(p.product_name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // In stock filter
            if (!empty($filters['in_stock'])) {
                $where[] = "p.stock_quantity > 0";
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*) as total 
                FROM products p 
                WHERE {$whereClause}
            ");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get products
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.sku,
                    p.barcode,
                    p.selling_price,
                    p.stock_quantity,
                    p.unit,
                    p.image_url,
                    p.category_id,
                    c.category_name,
                    COALESCE(t.rate, 0) as tax_rate
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN tax_rates t ON p.tax_rate_id = t.tax_rate_id
                WHERE {$whereClause}
                ORDER BY p.product_name
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];
            
        } catch (PDOException $e) {
            error_log("Get products error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to retrieve products',
                'data' => []
            ];
        }
    }
    
    /**
     * Get product by ID
     * @param int $productId Product ID
     * @return array Product data
     */
    public function getProductById($productId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.*,
                    c.category_name,
                    COALESCE(t.rate, 0) as tax_rate
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN tax_rates t ON p.tax_rate_id = t.tax_rate_id
                WHERE p.product_id = ? AND p.status = 'active'
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                return [
                    'success' => true,
                    'data' => $product
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Product not found'
            ];
            
        } catch (PDOException $e) {
            error_log("Get product error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to retrieve product'
            ];
        }
    }
    
    /**
     * Get active categories
     * @return array Categories data
     */
    public function getCategories() {
        try {
            $stmt = $this->pdo->query("
                SELECT category_id, category_name, description
                FROM categories
                WHERE status = 'active' AND type = 'product'
                ORDER BY category_name
            ");
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            error_log("Get categories error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'data' => []
            ];
        }
    }
    
    /**
     * Process sale transaction
     * @param array $saleData Sale data
     * @return array Result with sale_id
     */
    public function processSale($saleData) {
        try {
            $this->pdo->beginTransaction();
            
            // Insert sale record
            $stmt = $this->pdo->prepare("
                INSERT INTO sales (
                    receipt_number, customer_id, user_id, shift_id,
                    subtotal, tax_amount, discount_amount, shipping_amount, total_amount,
                    payment_method, amount_tendered, change_given,
                    status, sale_date, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), ?)
            ");
            
            $stmt->execute([
                $saleData['receipt_number'],
                $saleData['customer_id'] ?? null,
                $saleData['user_id'],
                $saleData['shift_id'] ?? null,
                $saleData['subtotal'],
                $saleData['tax'],
                $saleData['discount'],
                $saleData['shipping'],
                $saleData['total'],
                $saleData['payment_method'],
                $saleData['amount_tendered'],
                $saleData['change_given'],
                $saleData['user_id']
            ]);
            
            $saleId = $this->pdo->lastInsertId();
            
            // Insert sale items
            $itemStmt = $this->pdo->prepare("
                INSERT INTO sale_items (
                    sale_id, product_id, quantity, unit_price, tax_rate, total_price
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($saleData['items'] as $item) {
                $itemTotal = ($item['price'] * $item['quantity']) * (1 + ($item['tax_rate'] / 100));
                $itemStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['tax_rate'],
                    $itemTotal
                ]);
                
                // Update inventory
                $this->updateInventory($item['product_id'], -$item['quantity']);
            }
            
            // Record cash transaction if applicable
            if ($saleData['shift_id'] && $saleData['payment_method'] === 'cash') {
                $this->recordCashTransaction([
                    'shift_id' => $saleData['shift_id'],
                    'transaction_type' => 'sale',
                    'payment_method' => 'cash',
                    'amount' => $saleData['total'],
                    'reference' => $saleData['receipt_number']
                ]);
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Sale processed successfully',
                'data' => ['sale_id' => $saleId]
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Process sale error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to process sale: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Hold sale for later
     * @param array $saleData Sale data to hold
     * @return array Result
     */
    public function holdSale($saleData) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO held_sales (
                    user_id, shift_id, customer_id, hold_reference,
                    items_data, subtotal, tax_amount, total_amount, held_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $saleData['user_id'],
                $saleData['shift_id'] ?? null,
                $saleData['customer_id'] ?? null,
                $saleData['reference'] ?? null,
                json_encode($saleData['items']),
                $saleData['subtotal'],
                $saleData['tax'],
                $saleData['total']
            ]);
            
            return [
                'success' => true,
                'message' => 'Sale held successfully',
                'data' => ['hold_id' => $this->pdo->lastInsertId()]
            ];
            
        } catch (PDOException $e) {
            error_log("Hold sale error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to hold sale'
            ];
        }
    }
    
    /**
     * Get held sales
     * @param int $shiftId Optional shift ID filter
     * @return array Held sales
     */
    public function getHeldSales($shiftId = null) {
        try {
            $query = "
                SELECT 
                    h.*,
                    c.customer_name,
                    u.username,
                    JSON_LENGTH(h.items_data) as item_count
                FROM held_sales h
                LEFT JOIN customers c ON h.customer_id = c.customer_id
                LEFT JOIN users u ON h.user_id = u.user_id
                WHERE h.status = 'held'
            ";
            
            $params = [];
            if ($shiftId) {
                $query .= " AND h.shift_id = ?";
                $params[] = $shiftId;
            }
            
            $query .= " ORDER BY h.held_at DESC";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            error_log("Get held sales error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to retrieve held sales',
                'data' => []
            ];
        }
    }
    
    /**
     * Update product inventory
     * @param int $productId Product ID
     * @param int $quantity Quantity change (negative for decrease)
     * @return bool Success status
     */
    private function updateInventory($productId, $quantity) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE products 
                SET stock_quantity = stock_quantity + ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            return $stmt->execute([$quantity, $productId]);
            
        } catch (PDOException $e) {
            error_log("Update inventory error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Record cash register transaction
     * @param array $transactionData Transaction data
     * @return bool Success status
     */
    private function recordCashTransaction($transactionData) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cash_register_transactions (
                    shift_id, transaction_type, payment_method, amount, reference, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            return $stmt->execute([
                $transactionData['shift_id'],
                $transactionData['transaction_type'],
                $transactionData['payment_method'],
                $transactionData['amount'],
                $transactionData['reference'] ?? null
            ]);
            
        } catch (PDOException $e) {
            error_log("Record cash transaction error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get shift data
     * @param int $shiftId Shift ID
     * @return array Shift data
     */
    public function getShiftData($shiftId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM cash_register_shifts
                WHERE shift_id = ? AND status = 'active'
            ");
            $stmt->execute([$shiftId]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$shift) {
                return [
                    'success' => false,
                    'message' => 'Shift not found or not active'
                ];
            }
            
            return [
                'success' => true,
                'data' => $shift
            ];
            
        } catch (PDOException $e) {
            error_log("Get shift data error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to retrieve shift data'
            ];
        }
    }
    
    /**
     * End shift
     * @param int $shiftId Shift ID
     * @param float $endingCash Ending cash count
     * @param string $notes Optional notes
     * @return array Result
     */
    public function endShift($shiftId, $endingCash, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            
            // Get shift data
            $shiftResult = $this->getShiftData($shiftId);
            if (!$shiftResult['success']) {
                throw new Exception($shiftResult['message']);
            }
            
            $shift = $shiftResult['data'];
            $difference = $endingCash - $shift['starting_cash'];
            
            // Update shift
            $stmt = $this->pdo->prepare("
                UPDATE cash_register_shifts
                SET ending_cash = ?,
                    cash_difference = ?,
                    end_time = NOW(),
                    status = 'closed',
                    notes = ?
                WHERE shift_id = ?
            ");
            
            $stmt->execute([
                $endingCash,
                $difference,
                $notes,
                $shiftId
            ]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Shift ended successfully'
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("End shift error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to end shift: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check stock availability
     * @param int $productId Product ID
     * @return array Stock data
     */
    public function checkStock($productId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT product_id, product_name, stock_quantity, unit
                FROM products
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                return [
                    'success' => true,
                    'data' => $product
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Product not found'
            ];
            
        } catch (PDOException $e) {
            error_log("Check stock error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to check stock'
            ];
        }
    }
}
