<?php
/**
 * api/account/po_to_supplier_invoice.php
 *
 * Converts an approved Purchase Order into a Supplier (Received) Invoice.
 * Creates a new supplier_invoice record (status=pending) linked via po_id,
 * with line items copied from purchase_order_items.
 *
 * POST  po_id — the purchase_order_id to convert
 *
 * Returns { success, invoice_ref, invoice_id }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
// scope-audit: skip — record access is guarded by assertScopeForRecord below
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('received_invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$po_id = intval($_POST['po_id'] ?? 0);
if ($po_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid PO ID']);
    exit;
}

// Scope check — must be within user's project scope
assertScopeForRecord('purchase_orders', 'purchase_order_id', $po_id);

try {
    // Fetch PO
    $poStmt = $pdo->prepare("
        SELECT po.*, s.supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        WHERE po.purchase_order_id = ? AND po.status != 'cancelled'
    ");
    $poStmt->execute([$po_id]);
    $po = $poStmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'Purchase Order not found']);
        exit;
    }
    if ($po['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Only approved Purchase Orders can be converted to invoices (current status: ' . $po['status'] . ')']);
        exit;
    }

    // Fetch PO line items
    $itemStmt = $pdo->prepare("
        SELECT product_id,
               COALESCE(NULLIF(product_name,''), item_name) AS item_name,
               quantity, unit_of_measure AS unit, unit_price, tax_rate
        FROM purchase_order_items
        WHERE purchase_order_id = ?
        ORDER BY item_id
    ");
    $itemStmt->execute([$po_id]);
    $po_items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($po_items)) {
        echo json_encode(['success' => false, 'message' => 'Purchase Order has no line items to convert']);
        exit;
    }

    // ── Bill only the REMAINING un-invoiced balance ──────────────────────────
    // A PO is a commitment billed incrementally. If it is already partly invoiced,
    // converting the FULL PO again would over-invoice (and the cap would block it),
    // so we bill only what's left. We scale each PO line proportionally by the
    // remaining fraction (= remaining / PO total), so the invoice stays itemised and
    // its total equals the remaining balance exactly. When nothing is billed yet the
    // fraction is 1.0 → identical to a full-PO conversion.
    $billing = ri_po_billing($pdo, $po_id);
    if ($billing['remaining'] <= 0.001) {
        echo json_encode(['success' => false, 'message' =>
            "PO {$po['order_number']} is already fully invoiced (billed " . number_format($billing['billed'], 0)
            . " of " . number_format($billing['po_total'], 0) . "). Nothing left to convert."]);
        exit;
    }
    $po_grand = (float)$po['grand_total'];
    $fraction = ($po_grand > 0) ? min(1.0, $billing['remaining'] / $po_grand) : 1.0;
    $is_partial = $fraction < 0.9999;

    // Compute totals from line items (same math as ri_compute_items), scaled by $fraction
    $subtotal  = 0.0;
    $tax_total = 0.0;
    $item_rows = [];
    foreach ($po_items as $it) {
        $qty   = (float)$it['quantity'] * $fraction;   // remaining quantity for this line
        $price = (float)$it['unit_price'];
        $rate  = (float)($it['tax_rate'] ?? 0);
        $line_subtotal = $qty * $price;
        $line_tax      = $line_subtotal * ($rate / 100);
        $subtotal  += $line_subtotal;
        $tax_total += $line_tax;
        $item_rows[] = [
            'product_id' => !empty($it['product_id']) ? (int)$it['product_id'] : null,
            'item_name'  => $it['item_name'],
            'quantity'   => round($qty, 2),
            'unit'       => $it['unit'] ?: null,
            'unit_price' => $price,
            'tax_rate'   => $rate,
            'tax_amount' => round($line_tax, 2),
            'line_total' => round($line_subtotal + $line_tax, 2),
        ];
    }
    $amount    = round($subtotal + $tax_total, 2);
    $subtotal  = round($subtotal, 2);
    $tax_total = round($tax_total, 2);

    // PO cumulative cap — safety net (with remaining-only billing this should never fire).
    $cap = ri_check_po_cap($pdo, $po_id, $amount, null);
    if (!$cap['ok']) {
        echo json_encode(['success' => false, 'message' => $cap['message']]);
        exit;
    }

    // Generate next invoice_ref  (INV-YYYY-NNNN)
    // Company-prefixed sequential supplier-invoice ref (BFS-SINV-0001).
    require_once __DIR__ . '/../../core/code_generator.php';
    $invoice_ref = nextCode($pdo, 'SINV');

    $pdo->beginTransaction();

    // Insert supplier_invoice
    $ins = $pdo->prepare("
        INSERT INTO supplier_invoices
            (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded,
             po_id, project_id, warehouse_id,
             amount, subtotal, tax_amount, notes, status, recorded_by)
        VALUES ('supplier', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $ins->execute([
        $po['supplier_id'],
        $invoice_ref,
        $po['order_date'],   // date_raised = PO order date
        date('Y-m-d'),       // date_recorded = today
        $po_id,
        $po['project_id']   ?: null,
        $po['warehouse_id'] ?: null,
        $amount,
        $subtotal,
        $tax_total,
        $is_partial
            ? 'Auto-created from PO #' . $po['order_number'] . ' — remaining balance ('
              . number_format($billing['billed_pct'], 0) . '% already invoiced)'
            : 'Auto-created from PO #' . $po['order_number'],
        $_SESSION['user_id']
    ]);
    $invoice_id = (int)$pdo->lastInsertId();

    // Insert line items
    $itemIns = $pdo->prepare("
        INSERT INTO supplier_invoice_items
            (invoice_id, product_id, item_name, quantity, unit, unit_price, tax_rate, tax_amount, line_total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($item_rows as $r) {
        $itemIns->execute([
            $invoice_id, $r['product_id'], $r['item_name'], $r['quantity'],
            $r['unit'], $r['unit_price'], $r['tax_rate'], $r['tax_amount'], $r['line_total']
        ]);
    }

    // Workflow signature — stamp creator
    $actor = workflowActorSnapshot();
    workflowCaptureSignature($pdo, 'supplier_invoice', $invoice_id, 'created',
        (int)$_SESSION['user_id'], $actor['name'], $actor['role']);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'],
        "Converted PO #{$po['order_number']} to supplier invoice {$invoice_ref} (ID {$invoice_id})");

    echo json_encode([
        'success'     => true,
        'message'     => $is_partial
            ? 'Invoice created for the remaining balance (' . number_format($amount, 0) . ').'
            : 'Invoice created successfully',
        'invoice_id'  => $invoice_id,
        'invoice_ref' => $invoice_ref,
        'is_partial'  => $is_partial,
        'amount'      => $amount,
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('po_to_supplier_invoice error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
