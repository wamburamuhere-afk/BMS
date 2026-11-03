<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/vat.php';
require_once __DIR__ . '/../../core/workflow.php';          // workflowActorSnapshot / CaptureSignature
require_once __DIR__ . '/../../core/revenue_posting.php';   // postInvoiceRevenue / postInvoiceCOGS
require_once __DIR__ . '/../../core/sales_invoicing.php';   // sales-order remaining-quantity guard
require_once __DIR__ . '/../../core/sales_line_discount.php';  // salesLineMath — % or fixed-amount line discount

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

    // Ledger lock — a posted invoice is immutable. Corrections go through
    // void/reverse, never an in-place edit (keeps the GL + audit trail intact).
    if ($is_update) {
        require_once __DIR__ . '/../../core/code_generator.php';
        if (documentGlPosted($pdo, 'invoice', $invoice_id)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This invoice is posted to the ledger and locked. Void or reverse it to make changes.']);
            exit;
        }
    }

    $pdo->beginTransaction();

    $customer_id = $_POST['customer_id'] ?? 0;
    $invoice_date = $_POST['invoice_date'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $currency = bmsEnforceDocCurrency($_POST['currency'] ?? bmsCompanyCurrency());   // core/currency.php: TZS company unchanged; other → own currency
    $notes = $_POST['notes'] ?? '';
    $terms = $_POST['terms_conditions'] ?? '';
    $discount = $_POST['discount_amount'] ?? 0;
    $shipping = $_POST['shipping_cost'] ?? 0;
    $order_id = $_POST['order_id'] ?? null;
    $delivery_id = !empty($_POST['delivery_id']) ? intval($_POST['delivery_id']) : null;
    $customer_lpo_id = !empty($_POST['customer_lpo_id']) ? intval($_POST['customer_lpo_id']) : null;

    // New invoices enter the review → approve workflow (pending → reviewed → approved).
    // GL posting fires only on approval via approve_invoice.php.
    // On update, preserve the existing row's status.
    if ($is_update) {
        $existing = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
        $existing->execute([$invoice_id]);
        $status = $existing->fetchColumn() ?: 'pending';
    } else {
        $status = 'pending';
    }
    $project_id   = !empty($_POST['project_id'])   ? intval($_POST['project_id'])   : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;
    $is_service_invoice = isset($_POST['is_service_invoice']);
    $items = $_POST['items'] ?? [];

    if (empty($customer_id) || empty($invoice_date) || empty($items)) {
        throw new Exception("Missing required fields");
    }
    // Required on creation only — existing invoices may predate this field
    // (invoice_edit.php has no Service-Invoice toggle to know their intent).
    // ...and only when a line actually holds stock: an all-services invoice
    // needs no warehouse even if the Service toggle was left off.
    $stockLineIds = array_filter(array_map(fn($it) => (int)($it['product_id'] ?? 0), $items));
    $hasStockLine = false;
    if ($stockLineIds) {
        $ph = implode(',', array_fill(0, count($stockLineIds), '?'));
        $sl = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_id IN ($ph)
                              AND COALESCE(is_service, 0) = 0 AND COALESCE(track_inventory, 1) = 1");
        $sl->execute(array_values($stockLineIds));
        $hasStockLine = (int)$sl->fetchColumn() > 0;
    }
    if (!$is_update && !$is_service_invoice && $hasStockLine && !$warehouse_id) {
        throw new Exception(isShopLabel() ? "Shop is required for inventory invoices" : "Warehouse is required for inventory invoices");
    }

    // Calculate totals. VAT is charged on the line after its discount, the same
    // rule save_quotation.php / save_sales_order.php use, so an invoice raised
    // from a sales order reproduces the order's figures exactly.
    $subtotal = 0;
    $tax_total = 0;
    $line_discount_total = 0;
    $lines = [];

    foreach ($items as $key => $item) {
        // Discount as % or a fixed amount — core/sales_line_discount.php.
        $name = trim((string)($item['product_name'] ?? '')) ?: 'Line ' . ((int)$key + 1);
        // A line raised from a sales-order line keeps that line's agreed discount.
        $soDisc = soLineDiscountFor($pdo, (int)($item['order_item_id'] ?? 0), (float)($item['quantity'] ?? 0), (int)$invoice_id);
        $m = salesLineMath($soDisc !== null ? array_merge($item, $soDisc) : $item, $name);

        assertSalesAmountsFit([
            "Unit price of $name" => $m['price'],
            "Line total of $name" => $m['line_total'],
        ]);

        $subtotal += $m['line_subtotal'];
        $line_discount_total += $m['line_discount'];
        $tax_total += $m['line_tax'];
        $lines[$key] = $m;
    }

    $discount = floatval($discount) + $line_discount_total;
    $grand_total = $subtotal - $discount + $tax_total + $shipping;
    assertSalesAmountsFit(['Invoice subtotal' => $subtotal, 'Invoice total' => $grand_total]);

    // Orders this invoice billed before the edit — their quantities are freed
    // or re-counted once the new lines are saved.
    $priorSoIds = $is_update ? soIdsForInvoice($pdo, (int)$invoice_id) : [];

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
                invoice_number = ?, customer_id = ?, order_id = ?, delivery_id = ?, customer_lpo_id = ?, project_id = ?, warehouse_id = ?, invoice_date = ?, due_date = ?,
                subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                currency = ?, notes = ?, terms_conditions = ?, status = ?, updated_by = ?, updated_at = NOW()
            WHERE invoice_id = ?
        ");
        $stmt->execute([
            $invoice_number, $customer_id, $order_id ?: null, $delivery_id, $customer_lpo_id, $project_id, $warehouse_id, $invoice_date, $due_date,
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
                invoice_number, customer_id, order_id, delivery_id, customer_lpo_id, project_id, warehouse_id, invoice_date, due_date,
                subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                paid_amount, balance_due,
                currency, notes, terms_conditions, status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $invoice_number, $customer_id, $order_id ?: null, $delivery_id, $customer_lpo_id, $project_id, $warehouse_id, $invoice_date, $due_date,
            $subtotal, $tax_total, $discount, $shipping, $grand_total,
            $grand_total,
            $currency, $notes, $terms, $status, $_SESSION['user_id']
        ]);
        $invoice_id = $pdo->lastInsertId();

        // ── e-signature capture (Created By) ──────────────────────────────────
        $wfActor = workflowActorSnapshot();
        workflowCaptureSignature(
            $pdo, 'invoice', (int)$invoice_id, 'created',
            (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
        );
        // Reviewed and Approved signatures are captured later by
        // review_invoice.php and approve_invoice.php respectively.
    }

    // A sales-order line can only be billed up to the quantity still open on it.
    $touchedSoIds = soAssertInvoiceable($pdo, $items, $order_id ? (int)$order_id : null, (int)$invoice_id);

    // Older tenants may not have run the line-discount migration yet.
    $hasLineDiscount = (bool)$pdo->query("SHOW COLUMNS FROM invoice_items LIKE 'discount_amount'")->fetch();

    // Insert Items - NOTE: column name must match table `invoice_items` (line_total)
    $itemStmt = $pdo->prepare($hasLineDiscount ? "
        INSERT INTO invoice_items (
            invoice_id, order_item_id, product_id, product_name, description,
            quantity, unit, unit_price, tax_rate, tax_amount, discount_percent, discount_amount, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    " : "
        INSERT INTO invoice_items (
            invoice_id, order_item_id, product_id, product_name, description,
            quantity, unit, unit_price, tax_rate, tax_amount, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $key => $item) {
        $l = $lines[$key];
        // Optional price term — verified against the DB; a line raised from a
        // sales-order line inherits that line's term.
        $termId = $item['price_term_id'] ?? null;
        if (empty($termId) && !empty($item['order_item_id'])) {
            $termId = sptTermOfSalesOrderLine($pdo, (int)$item['order_item_id']);
        }
        $term = sptResolveLineTerm($pdo, $item['product_id'] ?? null, $termId,
            (string)($item['description'] ?? ''), trim((string)($item['product_name'] ?? '')) ?: 'a line');
        $row = [
            $invoice_id,
            !empty($item['order_item_id']) ? (int)$item['order_item_id'] : null,
            !empty($item['product_id']) ? (int)$item['product_id'] : null,
            $item['product_name'],
            $term['description'],
            $l['qty'],
            $item['unit'] ?? 'pcs',
            $l['price'],
            $l['tax_rate'],
            $l['line_tax'],
        ];
        if ($hasLineDiscount) {
            $row[] = $l['discount_percent'];
            $row[] = $l['line_discount'];
        }
        $row[] = $l['line_total'];
        $itemStmt->execute($row);
        $lineId = (int)$pdo->lastInsertId();
        sptStampLine($pdo, 'invoice_items', $lineId, $term);
        salesStampLineDiscount($pdo, 'invoice_items', 'invoice_item_id', $lineId, $l);
        $resolvedLines[] = ['term' => $term, 'qty' => $l['qty'], 'name' => trim((string)($item['product_name'] ?? '')) ?: 'a line'];
    }
    sptAssertPlanLines($resolvedLines ?? []);   // one plan, qty 1, add-ons follow it

    soRefreshInvoicedQuantities($pdo, array_merge($priorSoIds, $touchedSoIds, $order_id ? [(int)$order_id] : []));

    // GL posting (Dr AR / Cr Revenue / Cr Output VAT + Dr COGS / Cr Inventory)
    // fires only on approval via approve_invoice.php — not on create.

    $pdo->commit();

    // Log activity
    require_once __DIR__ . '/../../helpers.php';
    $invoice_num = $invoice_number ?? ($_POST['invoice_number'] ?? "ID: $invoice_id");
    if ($is_update) {
        logActivity($pdo, $_SESSION['user_id'], 'Edit invoice', "User edited invoice: $invoice_num (ID $invoice_id)");
    } else {
        logActivity($pdo, $_SESSION['user_id'], 'Create invoice', "User created a new invoice: $invoice_num (ID $invoice_id)");
    }

    // Invoices are auto-approved on creation, so there is no "needs review" step
    // to notify about (the previous invoice.needs_review dispatch was removed).

    echo json_encode(['success' => true, 'message' => 'Invoice saved successfully', 'invoice_id' => $invoice_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
