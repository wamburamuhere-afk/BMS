<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = trim(($_GET['action'] ?? $_POST['action'] ?? ''));

/**
 * Compute money from posted line items using the SAME math as the customer
 * invoice (invoice_create.php / save_invoice.php):
 *   line subtotal = qty * unit_price        (ex-tax)
 *   line tax      = line subtotal * rate/100
 *   amount        = Σ line subtotal + Σ line tax   (subtotal + VAT)
 * Returns [subtotal, tax_total, grand_total, normalisedRows].
 */
function ri_compute_items($items) {
    $subtotal = 0.0; $tax_total = 0.0; $rows = [];
    foreach ((array)$items as $it) {
        $name = trim($it['item_name'] ?? '');
        if ($name === '') continue;
        $qty   = (float)($it['quantity']   ?? 0);
        $price = (float)($it['unit_price'] ?? 0);
        $rate  = (float)($it['tax_rate']   ?? 0);
        $line_subtotal = $qty * $price;
        $line_tax      = $line_subtotal * ($rate / 100);
        $subtotal  += $line_subtotal;
        $tax_total += $line_tax;
        $rows[] = [
            'product_id' => !empty($it['product_id']) ? (int)$it['product_id'] : null,
            'item_name'  => $name,
            'quantity'   => $qty,
            'unit'       => trim($it['unit'] ?? '') ?: null,
            'unit_price' => $price,
            'tax_rate'   => $rate,
            'tax_amount' => round($line_tax, 2),
            'line_total' => round($line_subtotal + $line_tax, 2), // incl-tax, as invoice_items
        ];
    }
    return [round($subtotal, 2), round($tax_total, 2), round($subtotal + $tax_total, 2), $rows];
}

/** Replace all line items for an invoice with the given normalised rows. */
function ri_save_items($pdo, $invoice_id, array $rows) {
    $pdo->prepare("DELETE FROM supplier_invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
    if (!$rows) return;
    $ins = $pdo->prepare("INSERT INTO supplier_invoice_items
        (invoice_id, product_id, item_name, quantity, unit, unit_price, tax_rate, tax_amount, line_total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $ins->execute([$invoice_id, $r['product_id'], $r['item_name'], $r['quantity'],
                       $r['unit'], $r['unit_price'], $r['tax_rate'], $r['tax_amount'], $r['line_total']]);
    }
}

// ── GET actions (no CSRF) ──────────────────────────────────────────────────

if ($method === 'GET') {

    if ($action === 'list') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $type        = $_GET['type']        ?? '';
        $supplier_id = intval($_GET['supplier_id'] ?? 0);
        $status      = $_GET['status']      ?? '';
        $project_id  = intval($_GET['project_id']  ?? 0);

        $where  = ["si.status != 'deleted'"];
        $params = [];

        if ($type)        { $where[] = 'si.invoice_type = ?'; $params[] = $type; }
        if ($supplier_id) { $where[] = 'si.supplier_id = ?';  $params[] = $supplier_id; }
        if ($status)      { $where[] = 'si.status = ?';        $params[] = $status; }
        if ($project_id)  { $where[] = 'si.project_id = ?';   $params[] = $project_id; }

        // Phase C — project-scope filter appended after the array WHERE
        $scopeSI = scopeFilterSql('project', 'si');

        $sql = "
            SELECT si.*,
                   COALESCE(s.supplier_name, sc.supplier_name) AS party_name,
                   po.order_number                             AS po_number,
                   p.project_name,
                   CONCAT(u.first_name, ' ', u.last_name)     AS recorded_by_name
            FROM supplier_invoices si
            LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'        AND s.supplier_id   = si.supplier_id
            LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor'  AND sc.supplier_id  = si.supplier_id
            LEFT JOIN purchase_orders po ON si.po_id       = po.purchase_order_id
            LEFT JOIN projects p         ON si.project_id  = p.project_id
            LEFT JOIN users u            ON si.recorded_by = u.user_id
            WHERE " . implode(' AND ', $where) . $scopeSI . "
            ORDER BY si.date_recorded DESC, si.id DESC
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('received_invoices list: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); exit; }

        try {
            $stmt = $pdo->prepare("
                SELECT si.*,
                       COALESCE(s.supplier_name, sc.supplier_name)  AS party_name,
                       po.order_number                               AS po_number,
                       p.project_name,
                       CONCAT(u.first_name, ' ', u.last_name)        AS recorded_by_name
                FROM supplier_invoices si
                LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'       AND s.supplier_id  = si.supplier_id
                LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor' AND sc.supplier_id = si.supplier_id
                LEFT JOIN purchase_orders po ON si.po_id       = po.purchase_order_id
                LEFT JOIN projects p         ON si.project_id  = p.project_id
                LEFT JOIN users u            ON si.recorded_by = u.user_id
                WHERE si.id = ? AND si.status != 'deleted'
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }
            // Phase C — scope gate by the invoice's project
            if (!empty($row['project_id']) && !userCan('project', (int)$row['project_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied: this invoice belongs to a project not in your scope.']);
                exit;
            }
            // Line items (supplier invoices) for edit / view / print.
            $itemsStmt = $pdo->prepare("SELECT product_id, item_name, quantity, unit, unit_price, tax_rate, tax_amount, line_total
                                          FROM supplier_invoice_items WHERE invoice_id = ? ORDER BY item_id");
            $itemsStmt->execute([$id]);
            $row['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $row]);
        } catch (PDOException $e) {
            error_log('received_invoices get: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_suppliers') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $rows = $pdo->query("
            SELECT supplier_id AS id, supplier_name AS text
            FROM suppliers
            WHERE status != 'deleted'
            ORDER BY supplier_name
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // Active warehouses, optionally filtered by project (matches the PO-create
    // rule: a project shows its warehouses; no project shows company-wide ones).
    if ($action === 'get_warehouses') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $project_id = intval($_GET['project_id'] ?? 0);
        if ($project_id) {
            // Project chosen → only that project's warehouses (verify it's in scope).
            if (!userCan('project', $project_id)) {
                echo json_encode(['success' => true, 'data' => []]); exit;
            }
            $stmt = $pdo->prepare("SELECT warehouse_id AS id, warehouse_name AS text
                                     FROM warehouses
                                    WHERE status = 'active' AND project_id = ?
                                 ORDER BY warehouse_name");
            $stmt->execute([$project_id]);
        } else {
            // No project → only company-wide warehouses (not tied to any project).
            $stmt = $pdo->query("SELECT warehouse_id AS id, warehouse_name AS text
                                   FROM warehouses
                                  WHERE status = 'active' AND project_id IS NULL
                               ORDER BY warehouse_name");
        }
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_sub_contractors') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $rows = $pdo->query("
            SELECT supplier_id AS id, supplier_name AS text
            FROM sub_contractors
            WHERE status != 'deleted'
            ORDER BY supplier_name
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    if ($action === 'po_summary') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $po_id      = intval($_GET['po_id']      ?? 0);
        $exclude_id = intval($_GET['exclude_id'] ?? 0); // when editing an invoice, exclude itself from the SUM
        if (!$po_id) { echo json_encode(['success' => false, 'message' => 'po_id required']); exit; }

        try {
            $po = $pdo->prepare("SELECT po.purchase_order_id, po.order_number, po.grand_total,
                                        po.project_id, p.project_name
                                 FROM purchase_orders po
                                 LEFT JOIN projects p ON po.project_id = p.project_id
                                 WHERE po.purchase_order_id = ?");
            $po->execute([$po_id]);
            $poRow = $po->fetch(PDO::FETCH_ASSOC);
            if (!$poRow) { echo json_encode(['success' => false, 'message' => 'PO not found']); exit; }

            $sumSql = "SELECT COALESCE(SUM(amount), 0) AS invoiced_total, COUNT(*) AS invoice_count
                       FROM supplier_invoices
                       WHERE po_id = ? AND status != 'deleted'";
            $params = [$po_id];
            if ($exclude_id > 0) { $sumSql .= " AND id != ?"; $params[] = $exclude_id; }
            $sumStmt = $pdo->prepare($sumSql);
            $sumStmt->execute($params);
            $sum = $sumStmt->fetch(PDO::FETCH_ASSOC);

            $grand    = (float)$poRow['grand_total'];
            $invoiced = (float)$sum['invoiced_total'];
            echo json_encode(['success' => true, 'data' => [
                'po_id'          => $po_id,
                'order_number'   => $poRow['order_number'],
                'grand_total'    => $grand,
                'invoiced_total' => $invoiced,
                'remaining'      => $grand - $invoiced,
                'invoice_count'  => (int)$sum['invoice_count'],
                'project_id'     => $poRow['project_id'] ? (int)$poRow['project_id'] : null,
                'project_name'   => $poRow['project_name'] ?? null,
            ]]);
        } catch (PDOException $e) {
            error_log('received_invoices po_summary: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    if ($action === 'get_pos') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $supplier_id  = intval($_GET['supplier_id'] ?? 0);
        $project_id   = intval($_GET['project_id']  ?? 0);   // optional
        $warehouse_id = intval($_GET['warehouse_id']?? 0);   // optional
        if (!$supplier_id) { echo json_encode(['success' => true, 'data' => []]); exit; }

        // PO Reference shows only POs for this supplier, optionally narrowed by
        // the chosen project and warehouse.
        $sql = "SELECT purchase_order_id AS id,
                       CONCAT(order_number, ' — TZS ', FORMAT(grand_total, 0)) AS text,
                       order_number, grand_total, order_date
                FROM purchase_orders
                WHERE supplier_id = ? AND status NOT IN ('cancelled')";
        $params = [$supplier_id];
        if ($project_id)   { $sql .= " AND project_id = ?";   $params[] = $project_id; }
        if ($warehouse_id) { $sql .= " AND warehouse_id = ?"; $params[] = $warehouse_id; }
        $sql .= " ORDER BY order_date DESC";
        $rows = $pdo->prepare($sql);
        $rows->execute($params);
        echo json_encode(['success' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Items of a chosen PO, to auto-fill the received-invoice items table.
    if ($action === 'get_po_items') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $po_id = intval($_GET['po_id'] ?? 0);
        if (!$po_id) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $items = $pdo->prepare("
            SELECT product_id,
                   COALESCE(NULLIF(product_name,''), item_name) AS item_name,
                   quantity, unit_of_measure AS unit, unit_price, tax_rate
              FROM purchase_order_items
             WHERE purchase_order_id = ?
          ORDER BY item_id
        ");
        $items->execute([$po_id]);
        echo json_encode(['success' => true, 'data' => $items->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_next_ref') {
        if (!canCreate('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $year = date('Y');
        try {
            $stmt = $pdo->prepare(
                "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_ref, '-', -1) AS UNSIGNED))
                 FROM supplier_invoices
                 WHERE invoice_ref LIKE ?"
            );
            $stmt->execute(["INV-{$year}-%"]);
            $max = (int)$stmt->fetchColumn();
            $ref = 'INV-' . $year . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
            echo json_encode(['success' => true, 'ref' => $ref]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'ref' => 'INV-' . $year . '-0001']);
        }
        exit;
    }

    if ($action === 'get_projects') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        // Project is the user's choice — show every active project they are
        // assigned to (admins see all), not just those linked to the supplier.
        if (isAdmin()) {
            $stmt = $pdo->query("SELECT project_id AS id, project_name AS text
                                   FROM projects WHERE status = 'active' ORDER BY project_name");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        $assigned = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
        if (!$assigned) { echo json_encode(['success' => true, 'data' => []]); exit; }
        $ph = implode(',', array_fill(0, count($assigned), '?'));
        $stmt = $pdo->prepare("SELECT project_id AS id, project_name AS text
                                 FROM projects
                                WHERE status = 'active' AND project_id IN ($ph)
                             ORDER BY project_name");
        $stmt->execute($assigned);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ── POST actions (CSRF required) ───────────────────────────────────────────

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

// ── CREATE ─────────────────────────────────────────────────────────────────
if ($action === 'create') {
    if (!canCreate('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $invoice_type = trim($_POST['invoice_type'] ?? '');
    $supplier_id  = intval($_POST['supplier_id'] ?? 0);
    $invoice_ref  = trim($_POST['invoice_ref']   ?? '');
    $date_raised  = trim($_POST['date_raised']   ?? '');
    $date_recorded= trim($_POST['date_recorded'] ?? date('Y-m-d'));
    $amount       = floatval($_POST['amount']     ?? 0);
    $notes        = trim($_POST['notes']         ?? '');

    if (!in_array($invoice_type, ['supplier', 'sub_contractor'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice type']); exit;
    }

    // Both supplier and sub-contractor invoices derive the amount from line items
    // (same money math as the customer invoice). Supplier items auto-fill from a
    // PO; sub-contractor items are entered manually (no PO).
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;
    $item_rows    = [];
    if (!empty($_POST['items'])) {
        [$sub, $tax, $grand, $item_rows] = ri_compute_items($_POST['items']);
        if ($item_rows) $amount = $grand;
    }

    if (!$supplier_id || !$invoice_ref || !$date_raised || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing or amount must be greater than 0']); exit;
    }

    $po_id            = null;
    $project_id       = null;
    $sc_invoice_basis = null;
    $sc_basis_ref     = null;

    // Both supplier and SC can have a project; SC also has basis fields
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    if ($invoice_type === 'supplier') {
        $po_id = !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
    } else {
        $sc_invoice_basis = !empty($_POST['sc_invoice_basis']) ? trim($_POST['sc_invoice_basis']) : null;
        $sc_basis_ref     = !empty($_POST['sc_basis_ref'])     ? trim($_POST['sc_basis_ref'])     : null;
        if ($sc_invoice_basis && !in_array($sc_invoice_basis, ['IPC','Milestone','Scope','Final'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid invoice basis']); exit;
        }
    }

    // ── Enforce PO cumulative cap (supplier invoices with linked PO only) ──
    if ($po_id) {
        $cap = ri_check_po_cap($pdo, $po_id, $amount, null);
        if (!$cap['ok']) {
            echo json_encode(['success' => false, 'message' => $cap['message']]);
            exit;
        }
    }

    $attachment = null;
    if (!empty($_FILES['attachment']['name'])) {
        $attachment = handleAttachmentUpload();
        if (!$attachment['success']) {
            echo json_encode(['success' => false, 'message' => $attachment['message']]); exit;
        }
        $attachment = $attachment['path'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO supplier_invoices
                (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded,
                 po_id, project_id, warehouse_id, sc_invoice_basis, sc_basis_ref,
                 amount, attachment, notes, status, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $invoice_type, $supplier_id, $invoice_ref, $date_raised, $date_recorded,
            $po_id, $project_id, $warehouse_id, $sc_invoice_basis, $sc_basis_ref,
            $amount, $attachment, $notes, $_SESSION['user_id']
        ]);
        $new_id = $pdo->lastInsertId();
        if ($item_rows) ri_save_items($pdo, $new_id, $item_rows);
        // Stamp the creator's signature for the print's "Created By" column.
        $actor = workflowActorSnapshot();
        workflowCaptureSignature($pdo, 'supplier_invoice', (int)$new_id, 'created',
            (int)$_SESSION['user_id'], $actor['name'], $actor['role']);
        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'],
            "Recorded received invoice #{$invoice_ref} from {$invoice_type} ID {$supplier_id} — amount {$amount}");
        echo json_encode(['success' => true, 'message' => 'Invoice recorded successfully', 'id' => $new_id]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('received_invoices create: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── UPDATE ─────────────────────────────────────────────────────────────────
if ($action === 'update') {
    if (!canEdit('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $id           = intval($_POST['id']           ?? 0);
    $invoice_ref  = trim($_POST['invoice_ref']    ?? '');
    $date_raised  = trim($_POST['date_raised']    ?? '');
    $date_recorded= trim($_POST['date_recorded']  ?? '');
    $amount       = floatval($_POST['amount']      ?? 0);
    $notes        = trim($_POST['notes']          ?? '');

    // Amount is validated after the supplier items recompute below (a supplier
    // invoice posts amount=0 and derives it from the line items).
    if (!$id || !$invoice_ref || !$date_raised) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing']); exit;
    }

    $existing = $pdo->prepare("SELECT * FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $existing->execute([$id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }

    // Both types recompute the amount from their line items (same money math).
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : $row['warehouse_id'];
    $item_rows    = [];
    if (!empty($_POST['items'])) {
        [$sub, $tax, $grand, $item_rows] = ri_compute_items($_POST['items']);
        if ($item_rows) $amount = $grand;
    }
    if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit; }

    $po_id            = $row['po_id'];
    $project_id       = $row['project_id'];
    $sc_invoice_basis = $row['sc_invoice_basis'];
    $sc_basis_ref     = $row['sc_basis_ref'];

    // Both types can update project; SC also updates basis fields
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    if ($row['invoice_type'] === 'supplier') {
        $po_id = !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
    } else {
        $sc_invoice_basis = !empty($_POST['sc_invoice_basis']) ? trim($_POST['sc_invoice_basis']) : null;
        $sc_basis_ref     = !empty($_POST['sc_basis_ref'])     ? trim($_POST['sc_basis_ref'])     : null;
    }

    // ── Enforce PO cumulative cap (exclude this invoice from the SUM) ─────
    if ($po_id) {
        $cap = ri_check_po_cap($pdo, $po_id, $amount, $id);
        if (!$cap['ok']) {
            echo json_encode(['success' => false, 'message' => $cap['message']]);
            exit;
        }
    }

    $attachment = $row['attachment'];
    if (!empty($_FILES['attachment']['name'])) {
        $upload = handleAttachmentUpload();
        if (!$upload['success']) {
            echo json_encode(['success' => false, 'message' => $upload['message']]); exit;
        }
        $attachment = $upload['path'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            UPDATE supplier_invoices SET
                invoice_ref = ?, date_raised = ?, date_recorded = ?,
                po_id = ?, project_id = ?, warehouse_id = ?, sc_invoice_basis = ?, sc_basis_ref = ?,
                amount = ?, attachment = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $invoice_ref, $date_raised, $date_recorded,
            $po_id, $project_id, $warehouse_id, $sc_invoice_basis, $sc_basis_ref,
            $amount, $attachment, $notes, $id
        ]);
        ri_save_items($pdo, $id, $item_rows);
        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'], "Updated received invoice #{$invoice_ref} (ID {$id})");
        echo json_encode(['success' => true, 'message' => 'Invoice updated successfully']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('received_invoices update: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── CHANGE STATUS ─────────────────────────────────────────────────────────
if ($action === 'change_status') {
    if (!canEdit('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $id         = intval($_POST['id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    if (!$id || !$new_status) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']); exit;
    }

    $stmt = $pdo->prepare("SELECT status, invoice_ref FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }

    $current     = $row['status'];
    // Three-stage workflow: pending -> reviewed -> approved.
    $transitions = ['pending' => 'reviewed', 'reviewed' => 'approved'];

    if (!isset($transitions[$current]) || $transitions[$current] !== $new_status) {
        echo json_encode(['success' => false, 'message' => "Cannot change status from {$current} to {$new_status}"]); exit;
    }

    // Gate each transition by its workflow permission.
    if ($new_status === 'reviewed' && !canReview('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to review invoices']); exit;
    }
    if ($new_status === 'approved' && !canApprove('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to approve invoices']); exit;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE supplier_invoices SET status = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$new_status, $id]);
        // Stamp the acting user's signature for the print's Reviewed/Approved column.
        $actor = workflowActorSnapshot();
        workflowCaptureSignature($pdo, 'supplier_invoice', (int)$id, $new_status,
            (int)$_SESSION['user_id'], $actor['name'], $actor['role']);
        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'], "Invoice #{$row['invoice_ref']}: {$current} → {$new_status}");
        $labels = ['reviewed' => 'Reviewed', 'approved' => 'Approved'];
        echo json_encode(['success' => true, 'message' => 'Invoice ' . ($labels[$new_status] ?? $new_status) . ' successfully']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('received_invoices change_status: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── RECORD PAYMENT ─────────────────────────────────────────────────────────
if ($action === 'record_payment') {
    if (!canApprove('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied — you do not have permission to record payments']);
        exit;
    }

    $invoice_id      = intval($_POST['invoice_id'] ?? 0);
    $payment_date    = trim($_POST['payment_date'] ?? '');
    $payment_method  = trim($_POST['payment_method'] ?? '');
    $payment_ref     = trim($_POST['payment_ref'] ?? '');
    $payment_account = !empty($_POST['payment_account_id']) ? (int)$_POST['payment_account_id'] : 0;

    if (!$invoice_id || !$payment_date || !$payment_method) {
        echo json_encode(['success' => false, 'message' => 'Payment date and method are required']); exit;
    }
    if (!$payment_account) {
        echo json_encode(['success' => false, 'message' => 'Please choose the account the payment was made from (Paid From)']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment date format']); exit;
    }

    $stmt = $pdo->prepare("SELECT status, invoice_ref, amount, supplier_id, project_id FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }
    if ($inv['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Only approved invoices can be marked as paid']); exit;
    }

    try {
        $pdo->beginTransaction();
        // Consolidated outflow: Dr Accounts Payable, Cr the Paid-From account.
        $txn = postOutflow(
            $pdo, 'received_invoice_payment', $payment_account, defaultPayableAccountId($pdo),
            (float)$inv['amount'], $payment_date, $inv['invoice_ref'],
            "Received invoice {$inv['invoice_ref']} paid",
            $inv['project_id'] ? (int)$inv['project_id'] : null
        );
        $pdo->prepare("
            UPDATE supplier_invoices
            SET status = 'paid', payment_date = ?, payment_method = ?, payment_account_id = ?,
                payment_ref = ?, payment_transaction_id = ?, payment_recorded_by = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$payment_date, $payment_method, $payment_account, $payment_ref, $txn, $_SESSION['user_id'], $invoice_id]);
        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'],
            "Payment recorded for invoice #{$inv['invoice_ref']} — method: {$payment_method}");
        echo json_encode(['success' => true, 'message' => 'Payment recorded. Invoice marked as Paid.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('received_invoices record_payment: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── DELETE (soft) ──────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!canDelete('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); exit; }

    $chk = $pdo->prepare("SELECT invoice_ref FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }

    try {
        $pdo->prepare("UPDATE supplier_invoices SET status = 'deleted', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        logActivity($pdo, $_SESSION['user_id'], "Deleted received invoice #{$row['invoice_ref']} (ID {$id})");
        echo json_encode(['success' => true, 'message' => 'Invoice deleted']);
    } catch (PDOException $e) {
        error_log('received_invoices delete: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);

// ── File upload helper ─────────────────────────────────────────────────────
function handleAttachmentUpload(): array {
    $file        = $_FILES['attachment'];
    $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    $allowed_mime= [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png'
    ];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }

    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($file['tmp_name']);
    if (!in_array($real_mime, $allowed_mime, true)) {
        return ['success' => false, 'message' => 'File content does not match allowed types'];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File exceeds 5 MB limit'];
    }

    $safe_name  = bin2hex(random_bytes(16)) . '.' . $ext;
    $upload_dir = __DIR__ . '/../uploads/finance/received_invoices/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $htaccess = $upload_dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -ExecCGI\nRemoveHandler .php .phtml .php5\nRemoveType .php .phtml .php5\n"
        );
    }

    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $safe_name)) {
        return ['success' => false, 'message' => 'Upload failed — could not save file'];
    }

    return ['success' => true, 'path' => 'uploads/finance/received_invoices/' . $safe_name];
}
