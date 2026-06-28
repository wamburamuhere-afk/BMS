<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/vat.php';

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check permissions
$invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
$is_update = ($invoice_id > 0);

if ($is_update) {
    if (!canEdit('invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit invoices']);
        exit;
    }
} else {
    if (!canCreate('invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create invoices']);
        exit;
    }
}

try {
    global $pdo;

    // Phase C — scope checks:
    //  - When editing, block updates against invoices on projects not in user scope.
    //  - When creating, the submitted project_id must be in user scope.
    if ($is_update) {
        assertScopeForRecord('invoices', 'invoice_id', $invoice_id);
    }
    if (!empty($_POST['project_id']) && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
        exit;
    }

    $pdo->beginTransaction();

    $customer_id = $_POST['customer_id'] ?? 0;
    $invoice_date = $_POST['invoice_date'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $currency = $_POST['currency'] ?? 'TZS';
    $notes = $_POST['notes'] ?? '';
    $terms = $_POST['terms_conditions'] ?? '';
    $discount = $_POST['discount_amount'] ?? 0;
    $shipping = $_POST['shipping_cost'] ?? 0;
    $order_id = $_POST['order_id'] ?? null;

    // Three-approval rule: every new invoice starts at 'pending'. On update,
    // preserve the existing row's status (status transitions happen via
    // dedicated review/approve APIs, payment recording, etc.).
    if ($is_update) {
        $existing = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
        $existing->execute([$invoice_id]);
        $status = $existing->fetchColumn() ?: 'pending';
    } else {
        $status = 'pending';
    }
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $items = $_POST['items'] ?? [];

    if (empty($customer_id) || empty($invoice_date) || empty($items)) {
        throw new Exception("Missing required fields");
    }

    // Calculate totals
    $subtotal = 0;
    $tax_total = 0;
    
    // Validate items and calculate
    foreach ($items as $item) {
        $qty = floatval($item['quantity']);
        $price = floatval($item['unit_price']);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        
        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate / 100);
        
        $subtotal += $line_subtotal;
        $tax_total += $line_tax;
    }
    
    $grand_total = $subtotal + $tax_total - $discount + $shipping;

    if ($invoice_id > 0) {
        // Re-code on edit, but only while the invoice is NOT yet posted to the GL
        // (a posted invoice keeps the number already shown on statements/PDFs).
        require_once __DIR__ . '/../../core/code_generator.php';
        $curNo = $pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_id = ?");
        $curNo->execute([$invoice_id]);
        $invoice_number = codeForEditUnlessPosted(
            $pdo, 'INV', (string)$curNo->fetchColumn(), 'INV-[0-9].*',
            'invoice', (int)$invoice_id, 'invoices'
        );

        // Update existing
        $stmt = $pdo->prepare("
            UPDATE invoices SET
                invoice_number = ?, customer_id = ?, order_id = ?, project_id = ?, invoice_date = ?, due_date = ?,
                subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                currency = ?, notes = ?, terms_conditions = ?, status = ?, updated_by = ?, updated_at = NOW()
            WHERE invoice_id = ?
        ");
        $stmt->execute([
            $invoice_number, $customer_id, $order_id ?: null, $project_id, $invoice_date, $due_date,
            $subtotal, $tax_total, $discount, $shipping, $grand_total,
            $currency, $notes, $terms, $status, $_SESSION['user_id'], $invoice_id
        ]);

        // If this invoice already recognised output VAT (it was approved before
        // this edit), keep the VAT control account in sync with the new tax:
        // reverse the previously-posted amount, then re-post the new tax_amount.
        $wasPosted = $pdo->prepare("SELECT output_vat_posted FROM invoices WHERE invoice_id = ?");
        $wasPosted->execute([$invoice_id]);
        if ($wasPosted->fetchColumn() !== null) {
            reverseOutputVat($pdo, (int)$invoice_id);
            postOutputVat($pdo, (int)$invoice_id);
        }

        // Clear existing items to re-insert (simplest approach for now)
        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
        
    } else {
        // Create new — auto-generate the company invoice number (BFS-INV-0001)
        // unless one was explicitly supplied; on a clash, allocate a fresh one.
        require_once __DIR__ . '/../../core/code_generator.php';
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        if ($invoice_number === '') {
            $invoice_number = nextCode($pdo, 'INV');
        } else {
            $stmt = $pdo->prepare("SELECT count(*) FROM invoices WHERE invoice_number = ?");
            $stmt->execute([$invoice_number]);
            if ($stmt->fetchColumn() > 0) {
                $invoice_number = nextCode($pdo, 'INV');
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO invoices (
                invoice_number, customer_id, order_id, project_id, invoice_date, due_date,
                subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                paid_amount, balance_due,
                currency, notes, terms_conditions, status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $invoice_number, $customer_id, $order_id ?: null, $project_id, $invoice_date, $due_date,
            $subtotal, $tax_total, $discount, $shipping, $grand_total,
            $grand_total,
            $currency, $notes, $terms, $status, $_SESSION['user_id']
        ]);
        $invoice_id = $pdo->lastInsertId();

        // ── e-signature capture (Created By) ─ Issue 1 fix
        if (!function_exists('workflowCaptureSignature')) {
            require_once __DIR__ . '/../../core/workflow.php';
        }
        $wfActor = workflowActorSnapshot();
        workflowCaptureSignature(
            $pdo, 'invoice', (int)$invoice_id, 'created',
            (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
        );
    }

    // Insert Items - NOTE: column name must match table `invoice_items` (line_total)
    $itemStmt = $pdo->prepare("
        INSERT INTO invoice_items (
            invoice_id, order_item_id, product_id, product_name, description,
            quantity, unit, unit_price, tax_rate, tax_amount, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $qty = floatval($item['quantity']);
        $price = floatval($item['unit_price']);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate / 100);
        $line_total = $line_subtotal + $line_tax;

        $itemStmt->execute([
            $invoice_id,
            $item['order_item_id'] ?: null,
            $item['product_id'] ?: null,
            $item['product_name'],
            $item['description'] ?? '',
            $qty,
            $item['unit'] ?? 'pcs',
            $price,
            $tax_rate,
            $line_tax,
            $line_total
        ]);
    }
    
    // Update Sales Order Status if linked
    if ($order_id) {
         // Logic to update sales order status/invoiced amount could go here
    }

    $pdo->commit();

    // Log activity
    require_once __DIR__ . '/../../helpers.php';
    $invoice_num = $invoice_number ?? ($_POST['invoice_number'] ?? "ID: $invoice_id");
    if ($is_update) {
        logActivity($pdo, $_SESSION['user_id'], 'Edit invoice', "User edited invoice: $invoice_num (ID $invoice_id)");
    } else {
        logActivity($pdo, $_SESSION['user_id'], 'Create invoice', "User created a new invoice: $invoice_num (ID $invoice_id)");
    }

    echo json_encode(['success' => true, 'message' => 'Invoice saved successfully', 'invoice_id' => $invoice_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
