<?php
// File: api/account/save_quotation.php
// Creates or updates a quotation in the dedicated `quotations` table.
// A quotation is the first document issued to a customer — it carries no PO
// reference and does not consume stock. A newly created quotation starts at
// status 'pending' (the entry point of the pending -> reviewed -> approved
// workflow). Editing a quotation never changes its workflow status.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/sales_invoicing.php';   // assertSalesAmountsFit
require_once __DIR__ . '/../../core/sales_line_discount.php';

header('Content-Type: application/json');

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

$quotation_id = isset($_POST['quotation_id']) ? intval($_POST['quotation_id']) : 0;
$is_update = ($quotation_id > 0);

if ($is_update) {
    if (!canEdit('quotations')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to edit quotations']);
        exit;
    }
} else {
    if (!canCreate('quotations')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create quotations']);
        exit;
    }
}

try {
    global $pdo;

    // Phase C — scope checks (run before opening a transaction):
    $quotation_id_check = isset($_POST['quotation_id']) ? intval($_POST['quotation_id']) : 0;
    if ($quotation_id_check > 0) {
        assertScopeForRecord('quotations', 'sales_order_id', $quotation_id_check);
    }
    if (!empty($_POST['project_id']) && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
        exit;
    }

    $pdo->beginTransaction();

    $quotation_id     = isset($_POST['quotation_id']) ? intval($_POST['quotation_id']) : 0;
    $is_update        = ($quotation_id > 0);
    $customer_id      = $_POST['customer_id'] ?? 0;
    $order_date       = $_POST['order_date'] ?? '';
    $delivery_date    = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
    $salesperson_id   = $_POST['salesperson_id'] ?? $_SESSION['user_id'];
    $currency         = bmsEnforceDocCurrency($_POST['currency'] ?? bmsCompanyCurrency());   // core/currency.php: TZS company unchanged; other → own currency
    $payment_terms    = $_POST['payment_terms'] ?? '';
    $reference        = $_POST['reference'] ?? '';
    $notes            = $_POST['notes'] ?? '';
    $terms_conditions = $_POST['terms_conditions'] ?? '';
    $valid_until      = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
    $items            = json_decode($_POST['items'] ?? '[]', true);
    $project_id       = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $warehouse_id     = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;

    if (empty($customer_id) || empty($order_date) || empty($items)) {
        throw new Exception("Missing required fields (Customer, Date, or Items)");
    }

    // An approved quotation is locked — it cannot be edited.
    if ($is_update) {
        $cur = $pdo->prepare("SELECT status FROM quotations WHERE sales_order_id = ?");
        $cur->execute([$quotation_id]);
        $curStatus = $cur->fetchColumn();
        if ($curStatus === false) {
            throw new Exception("Quotation not found");
        }
        if ($curStatus === 'approved') {
            throw new Exception("An approved quotation can no longer be edited.");
        }
    }

    // Calculate totals from the submitted items (discount as % or a fixed amount — core/sales_line_discount.php).
    $subtotal = 0; $tax_total = 0; $total_ordered = 0; $discount_amount_total = 0;
    $lines = [];
    foreach ($items as $key => $item) {
        $line_name = trim((string)($item['product_name'] ?? '')) ?: 'an item';
        $m = salesLineMath($item + ['quantity' => 1], $line_name);

        $subtotal              += $m['line_subtotal'];
        $discount_amount_total += $m['line_discount'];
        $tax_total             += $m['line_tax'];
        $total_ordered         += $m['qty'];
        $lines[$key] = $m;

        assertSalesAmountsFit([
            "Unit price of $line_name" => $m['price'],
            "Line total of $line_name" => $m['line_total'],
        ]);
    }

    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);

    $grand_total = ($subtotal - $discount_amount_total) + $tax_total + $shipping_cost;
    assertSalesAmountsFit(['Subtotal' => $subtotal, 'Grand total' => $grand_total]);

    if ($is_update) {
        // Re-code a legacy quotation number on edit. The approved-lock guard above
        // already blocks locked quotes, so reaching here means it's still editable.
        require_once __DIR__ . '/../../core/code_generator.php';
        $curQt = $pdo->prepare("SELECT order_number FROM quotations WHERE sales_order_id = ?");
        $curQt->execute([$quotation_id]);
        $order_number = codeForEdit($pdo, 'QT', (string)$curQt->fetchColumn(), '(QT|QUO)-[0-9].*', 'quotations', (int)$quotation_id);

        // Editing never changes the workflow status.
        $stmt = $pdo->prepare("
            UPDATE quotations SET
                order_number = ?, customer_id = ?, order_date = ?, delivery_date = ?, salesperson_id = ?,
                currency = ?, payment_terms = ?, reference = ?,
                subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                total_ordered = ?, notes = ?, terms_conditions = ?, is_quote = 1,
                project_id = ?, warehouse_id = ?, quote_valid_until = ?,
                updated_at = NOW(), updated_by = ?
            WHERE sales_order_id = ?
        ");
        $stmt->execute([
            $order_number, $customer_id, $order_date, $delivery_date, $salesperson_id,
            $currency, $payment_terms, $reference,
            $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
            $total_ordered, $notes, $terms_conditions,
            $project_id, $warehouse_id, $valid_until,
            $_SESSION['user_id'], $quotation_id,
        ]);

        $pdo->prepare("DELETE FROM quotation_items WHERE order_id = ?")->execute([$quotation_id]);
    } else {
        // A new quotation always enters the workflow at 'pending'.
        require_once __DIR__ . '/../../core/code_generator.php';
        $order_number = nextCode($pdo, 'QT');   // e.g. BFS-QT-0001

        $stmt = $pdo->prepare("
            INSERT INTO quotations (
                order_number, customer_id, order_date, delivery_date, salesperson_id,
                currency, payment_terms, reference,
                subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                total_ordered, notes, terms_conditions, status, is_quote,
                project_id, warehouse_id, quote_valid_until, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_number, $customer_id, $order_date, $delivery_date, $salesperson_id,
            $currency, $payment_terms, $reference,
            $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
            $total_ordered, $notes, $terms_conditions,
            $project_id, $warehouse_id, $valid_until, $_SESSION['user_id'],
        ]);
        $quotation_id = $pdo->lastInsertId();

        // ── e-signature capture (Created By) ─ Issue 1 fix
        if (!function_exists('workflowCaptureSignature')) {
            require_once __DIR__ . '/../../core/workflow.php';
        }
        $wfActor = workflowActorSnapshot();
        workflowCaptureSignature(
            $pdo, 'quotation', (int)$quotation_id, 'created',
            (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
        );
    }

    // Insert line items into quotation_items.
    $itemStmt = $pdo->prepare("
        INSERT INTO quotation_items (
            order_id, product_id, product_name, sku, quantity, unit,
            unit_price, tax_rate, discount_percent, line_total, description, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    foreach ($items as $key => $item) {
        $m = $lines[$key];

        // Optional price term (core/service_price_terms.php) — verified against the DB.
        $term = sptResolveLineTerm($pdo, $item['product_id'] ?? null, $item['price_term_id'] ?? null,
            (string)($item['description'] ?? ''), trim((string)($item['product_name'] ?? '')) ?: 'a line');

        $itemStmt->execute([
            $quotation_id, $item['product_id'] ?: null, $item['product_name'], $item['sku'] ?? '',
            $m['qty'], $item['unit'] ?? 'pcs', $m['price'], $m['tax_rate'], $m['discount_percent'], $m['line_total'],
            $term['description'] !== '' ? $term['description'] : null,
        ]);
        $lineId = (int)$pdo->lastInsertId();
        sptStampLine($pdo, 'quotation_items', $lineId, $term);
        salesStampLineDiscount($pdo, 'quotation_items', 'order_item_id', $lineId, $m);
        $resolvedLines[] = ['term' => $term, 'qty' => $m['qty'], 'name' => trim((string)($item['product_name'] ?? '')) ?: 'a line'];
    }
    sptAssertPlanLines($resolvedLines ?? []);   // one plan, qty 1, add-ons follow it

    // Made from a company's subscription request (Quotations → Requests from systems):
    // the request is marked quoted in the same transaction, so it is used once only.
    $srLink = null;
    $srId = !$is_update ? (int)($_POST['subscription_request_id'] ?? 0) : 0;
    if ($srId > 0) {
        require_once __DIR__ . '/../../core/vendor_context.php';
        require_once __DIR__ . '/../../core/subscription_requests.php';
        if (!isPlatformVendorContext()) throw new Exception('Subscription requests are handled only at BJP.');
        $sr = srGet($pdo, $srId);
        if (!$sr || !srMarkQuoted($pdo, $srId, (int)$quotation_id, (int)$_SESSION['user_id'])) {
            throw new Exception('That request was already handled. Open Quotations → Requests from systems again.');
        }
        if (!empty($_POST['link_tenant'])) $srLink = $sr;
    }

    $pdo->commit();

    // Link the requesting company to the customer chosen (control DB, after the commit).
    $linkNote = '';
    if ($srLink !== null) {
        require_once __DIR__ . '/../../core/vendor_link.php';
        require_once __DIR__ . '/../../core/vendor_bridge.php';
        $t = vlFindTenant(null, (int)$srLink['tenant_id']);
        if (!canEdit('customers')) {
            $linkNote = ' The company was not linked: you need permission to edit customers.';
        } elseif ($t && (int)($t['vendor_customer_id'] ?? 0) <= 0) {
            $c = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE customer_id = ?");
            $c->execute([(int)$customer_id]);
            $c = $c->fetch(PDO::FETCH_ASSOC);
            $me  = (int)$_SESSION['user_id'];
            $who = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ('user #' . $me);
            $r = $c ? vlSetTenantCustomer((int)$t['id'], (int)$c['customer_id'], $who, $c['customer_code'] . ' — ' . $c['customer_name']) : ['ok' => false, 'error' => 'Customer not found.'];
            if ($r['ok']) {
                logActivity($pdo, $me, 'Link platform company', "{$c['customer_code']} now pays for {$t['subdomain']} (from request {$srLink['request_number']})");
                vendorRequeueCustomer($pdo, (int)$c['customer_id']);
                logAudit($pdo, $me, 'vendor_customer_linked', ['entity_type' => 'customer', 'entity_id' => (int)$c['customer_id'],
                    'new_values' => ['tenant_id' => (int)$t['id'], 'subdomain' => $t['subdomain'], 'request' => $srLink['request_number']]]);
                $linkNote = " {$t['subdomain']} is now linked to this customer.";
            } else {
                $linkNote = ' The company could not be linked: ' . $r['error'];
            }
        }
    }

    $log_number  = $order_number ?? '';
    if ($is_update && $log_number === '') {
        $s = $pdo->prepare("SELECT order_number FROM quotations WHERE sales_order_id = ?");
        $s->execute([$quotation_id]);
        $log_number = $s->fetchColumn();
    }
    if ($is_update) {
        logActivity($pdo, $_SESSION['user_id'], 'Edit quotation', "User edited quotation: $log_number (ID $quotation_id)");
    } else {
        logActivity($pdo, $_SESSION['user_id'], 'Create quotation', "User created a new quotation: $log_number (ID $quotation_id)");
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Quotation saved successfully.' . ($srId > 0 ? " Request {$sr['request_number']} is now quoted." : '') . $linkNote,
        'quotation_id' => $quotation_id,
        'order_number' => $log_number,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving quotation: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
