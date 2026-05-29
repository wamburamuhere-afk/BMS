<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Please login to continue']);
    exit();
}

// Check permissions - Key for GRN is 'grn' based on permission mapping
if (!canCreate('grn') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied: You do not have permission to create GRN']);
    exit();
}

try {
    // Ensure attachment table exists outside transaction to avoid implicit commits
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_receipt_attachments (
        attachment_id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_type VARCHAR(100),
        file_size INT,
        uploaded_by INT,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        description TEXT,
        INDEX (receipt_id)
    )");

    // AUTO-MIGRATION: Ensure columns exist in receipt_items (for online server stability)
    $columns_to_check = [
        'purchase_order_item_id' => "INT NULL AFTER receipt_id",
        'unit'                   => "VARCHAR(20) DEFAULT 'pcs' AFTER expiry_date"
    ];
    foreach ($columns_to_check as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM receipt_items LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE receipt_items ADD COLUMN $col $definition");
        }
    }

    // Start transaction
    $pdo->beginTransaction();

    // Get form data
    $receipt_number = $_POST['receipt_number'] ?? '';
    
    // Ensure unique receipt number (handle duplicates causing 1062 errors)
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM purchase_receipts WHERE receipt_number = ?");
    $stmtCheck->execute([$receipt_number]);
    if ($stmtCheck->fetchColumn() > 0) {
        // Receipt number exists, generate a new one
        $prefix = explode('-', $receipt_number)[0]; 
        if (!$prefix) $prefix = 'GRN';
        $random = mt_rand(1000, 9999); // Use 4 digits for better uniqueness
        $receipt_number = $prefix . '-' . date('Ymd') . '-' . $random;
        
        // Double check just in case
        while(true) {
             $stmtCheck->execute([$receipt_number]);
             if ($stmtCheck->fetchColumn() == 0) break;
             $receipt_number = $prefix . '-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        }
    }
    $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0);
    $delivery_note = $_POST['delivery_note'] ?? '';
    $delivery_id   = intval($_POST['delivery_id'] ?? 0) ?: null;
    // Three-approval rule: every new GRN starts at 'pending'. Stock-receipt
    // side-effects now fire from api/approve_grn.php when the GRN passes the
    // canonical approval gate. The legacy direct-to-completed shortcut is
    // intentionally disabled.
    $status = 'pending';
    $notes = $_POST['notes'] ?? '';
    $items_json = $_POST['items'] ?? '[]';
    $received_by = $_POST['created_by'] ?? $_SESSION['user_id'];
    
    // Get project_id from form or PO if available
    $project_id = isset($_POST['project_id']) && !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    if (!$project_id && $purchase_order_id > 0) {
        $stmtPOProj = $pdo->prepare("SELECT project_id FROM purchase_orders WHERE purchase_order_id = ?");
        $stmtPOProj->execute([$purchase_order_id]);
        $project_id = $stmtPOProj->fetchColumn();
    }

    // Phase E — project-scope gate: can only create GRN for a project in scope
    if (!empty($project_id) && function_exists('userCan') && !userCan('project', (int)$project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: project not in your scope.');
    }

    // Parse items
    $items = json_decode($_POST['items'], true);
    
    if (empty($items)) {
        throw new Exception('No items received');
    }

    // Insert into purchase_receipts
    $stmt = $pdo->prepare("
        INSERT INTO purchase_receipts (
            receipt_number, purchase_order_id, project_id, supplier_id, warehouse_id,
            receipt_date, delivery_note, delivery_id, status, notes,
            received_by, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $receipt_number,
        $purchase_order_id ?: null,
        $project_id,
        $supplier_id,
        $warehouse_id,
        $receipt_date,
        $delivery_note,
        $delivery_id,
        $status,
        $notes,
        $received_by,
        $_SESSION['user_id']
    ]);
    
    $receipt_id = $pdo->lastInsertId();

    // ── e-signature capture (Created By) ─ Issue 1 fix
    if (!function_exists('workflowCaptureSignature')) {
        require_once __DIR__ . '/../core/workflow.php';
    }
    $wfActor = workflowActorSnapshot();
    workflowCaptureSignature(
        $pdo, 'grn', (int)$receipt_id, 'created',
        (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
    );

    // Process items
    $stmtItem = $pdo->prepare("
        INSERT INTO receipt_items (
            receipt_id, purchase_order_item_id, product_id,
            quantity_received, unit_price, tax_rate, tax_amount,
            batch_number, expiry_date, unit
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Stock side-effects no longer fire on create — they move to approve_grn.php
    // when the GRN reaches 'approved' status per three_approval.md §1 rule 6.
    $updateStock = false;

    foreach ($items as $item) {
        $po_item_id = intval($item['purchase_order_item_id'] ?? 0);
        $product_id = intval($item['product_id']);
        $qty        = floatval($item['quantity_received']);
        $price      = floatval($item['unit_price']);
        $raw_rate   = floatval($item['tax_rate'] ?? 0);
        $tax_rate   = ($raw_rate == 18) ? 18 : 0;
        $tax_amount = $qty * $price * ($tax_rate / 100);
        $batch      = $item['batch_number'] ?? null;
        $expiry     = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
        $unit       = $item['unit'] ?? 'pcs';

        // Skip invalid items
        if ($product_id <= 0 || $qty <= 0) continue;

        $stmtItem->execute([
            $receipt_id,
            $po_item_id ?: null,
            $product_id,
            $qty,
            $price,
            $tax_rate,
            $tax_amount,
            $batch,
            $expiry,
            $unit
        ]);
        
        // Check product type — block services from GRN
        $prodType = $pdo->prepare("SELECT is_service, track_inventory, product_name FROM products WHERE product_id = ?");
        $prodType->execute([$product_id]);
        $prodData = $prodType->fetch(PDO::FETCH_ASSOC);
        if ($prodData && $prodData['is_service']) {
            // Skip service products entirely — they don't belong in GRN
            continue;
        }
        $isTracked = $prodData ? (bool)$prodData['track_inventory'] : true;

        // Update stock if completed AND product is tracked
        if ($updateStock && $isTracked) {
            // 1. Update global product stock level
            $stmtStock = $pdo->prepare("
                UPDATE products 
                SET current_stock = current_stock + ?,
                    stock_quantity = stock_quantity + ?
                WHERE product_id = ?
            ");
            $stmtStock->execute([$qty, $qty, $product_id]);

            // 2. Update specific warehouse stock level (product_stocks table)
            // Check if product exists in this warehouse
            $stmtCheckPS = $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
            $stmtCheckPS->execute([$product_id, $warehouse_id]);
            $stockId = $stmtCheckPS->fetchColumn();

            $is_project = ($project_id > 0);
            $reserve_qty = $is_project ? $qty : 0;

            if ($stockId) {
                // Update existing warehouse stock and reservation
                $stmtUpdatePS = $pdo->prepare("
                    UPDATE product_stocks 
                    SET stock_quantity = IFNULL(stock_quantity, 0) + ?,
                        reserved_quantity = IFNULL(reserved_quantity, 0) + ?,
                        last_updated = NOW()
                    WHERE stock_id = ?
                ");
                $stmtUpdatePS->execute([$qty, $reserve_qty, $stockId]);
            } else {
                // Insert new record for this product in this warehouse
                $stmtInsertPS = $pdo->prepare("
                    INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity, last_updated)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmtInsertPS->execute([$product_id, $warehouse_id, $qty, $reserve_qty]);
            }
            
            // 3. Record stock movement — this branch is currently unreachable
            // ($updateStock is hard-coded false above as part of the GRN
            // three-approval slice; the side-effect now fires from
            // api/approve_grn.php). Kept ENUM-safe so any future re-enable
            // doesn't re-introduce the silent-truncation bug.
            $stmtMove = $pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, warehouse_id, project_id, movement_type,
                    quantity, reference_id, reference_type,
                    movement_date, created_by, notes
                ) VALUES (?, ?, ?, 'purchase_in', ?, ?, 'purchase_order', ?, ?, ?)
            ");
            $stmtMove->execute([
                $product_id,
                $warehouse_id,
                $project_id,
                $qty,
                $receipt_id,
                $receipt_date,
                $_SESSION['user_id'],
                "GRN: $receipt_number"
            ]);
        }
    }
    
    // PO status is updated only when GRN is approved (update_grn_status.php), not on creation.

    // Handle Attachments
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/finance/grn/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $attachment_names = $_POST['attachment_names'] ?? [];

        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $original_name = $_FILES['attachments']['name'][$i];
                $extension = pathinfo($original_name, PATHINFO_EXTENSION);

                // Sanitize filename
                $clean_name = preg_replace("/[^a-zA-Z0-9]/", "_", pathinfo($original_name, PATHINFO_FILENAME));
                $file_name = 'GRN_' . $receipt_id . '_' . time() . '_' . $i . '.' . $extension;
                $file_path = 'uploads/finance/grn/' . $file_name;
                $dest_path = $upload_dir . $file_name;

                if (move_uploaded_file($tmp_name, $dest_path)) {
                    $doc_name = !empty($attachment_names[$i]) ? $attachment_names[$i] : $original_name;
                    
                    $attStmt = $pdo->prepare("
                        INSERT INTO purchase_receipt_attachments (
                            receipt_id, file_name, file_path, file_type, file_size, 
                            uploaded_by, uploaded_at, description
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $attStmt->execute([
                        $receipt_id, $doc_name, $file_path, 
                        $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i],
                        $_SESSION['user_id'], $doc_name
                    ]);
                }
            }
        }
    }

    $pdo->commit();
    
    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], "create", [
        'activity_type' => 'create',
        'entity_type' => 'grn',
        'entity_id' => $receipt_id,
        'description' => "Created Goods Received Note #$receipt_number for supplier ID $supplier_id"
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'GRN created successfully',
        'receipt_id' => $receipt_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error creating GRN: ' . $e->getMessage()
    ]);
}
