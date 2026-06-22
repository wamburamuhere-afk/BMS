<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../core/payment_source.php';
require_once __DIR__ . '/../core/money_guard.php';      // postOutflowOrFail / accountFundsWarning
require_once __DIR__ . '/../core/vat.php';
require_once __DIR__ . '/../core/wht.php';
require_once __DIR__ . '/../core/purchase_posting.php';  // postSubcontractorAccrual (OUT-3)
require_once __DIR__ . '/../core/bank_register.php';     // recordBankTransaction
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

/** Compute due_date from terms + date_raised. Returns null when no terms/date given.
 *  Handles fixed terms (COD, Net7…Net60), custom Net{N} (e.g. "Net21"), and "Custom". */
function ri_compute_due_date(string $date_raised, string $terms, string $custom_date): ?string {
    if ($terms === 'Custom') return ($custom_date ?: null);
    $fixed = ['COD' => 0, 'Net7' => 7, 'Net14' => 14, 'Net30' => 30, 'Net45' => 45, 'Net60' => 60];
    if (isset($fixed[$terms]) && $date_raised) {
        return (new DateTime($date_raised))->modify('+' . $fixed[$terms] . ' days')->format('Y-m-d');
    }
    // Generic Net{N} (e.g. "Net21", "Net90") — any positive integer of days
    if (preg_match('/^Net(\d+)$/', $terms, $m) && $date_raised) {
        return (new DateTime($date_raised))->modify('+' . (int)$m[1] . ' days')->format('Y-m-d');
    }
    return null;
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
                   COALESCE(s.default_wht_rate_id, sc.default_wht_rate_id) AS default_wht_rate_id,
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

    // ── DUPLICATE CHECK (live UI) ──────────────────────────────────────────
    if ($action === 'check_duplicate') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $supplier_id = intval($_GET['supplier_id'] ?? 0);
        $invoice_ref = trim($_GET['invoice_ref']   ?? '');
        $exclude_id  = intval($_GET['exclude_id']  ?? 0);
        $amount      = (float)($_GET['amount']     ?? 0);
        $date_raised = trim($_GET['date_raised']   ?? '');

        if (!$supplier_id || $invoice_ref === '') {
            echo json_encode(['success' => true, 'exact' => null, 'fuzzy' => []]);
            exit;
        }
        try {
            // Exact: same supplier + same ref (case/whitespace insensitive)
            $esql = "SELECT id, invoice_ref, date_raised, amount, status
                     FROM supplier_invoices
                     WHERE supplier_id = ? AND LOWER(TRIM(invoice_ref)) = LOWER(TRIM(?)) AND status != 'deleted'";
            $ep   = [$supplier_id, $invoice_ref];
            if ($exclude_id) { $esql .= " AND id != ?"; $ep[] = $exclude_id; }
            $esql .= " LIMIT 1";
            $est = $pdo->prepare($esql); $est->execute($ep);
            $exact = $est->fetch(PDO::FETCH_ASSOC) ?: null;

            // Fuzzy: same supplier, amount ±5%, date within 60 days
            $fuzzy = [];
            if ($amount > 0 && $date_raised) {
                $fsql = "SELECT id, invoice_ref, date_raised, amount, status
                         FROM supplier_invoices
                         WHERE supplier_id = ? AND status != 'deleted'
                           AND amount BETWEEN ? AND ?
                           AND ABS(DATEDIFF(date_raised, ?)) <= 60";
                $fp = [$supplier_id, $amount * 0.95, $amount * 1.05, $date_raised];
                if ($exclude_id) { $fsql .= " AND id != ?"; $fp[] = $exclude_id; }
                if ($exact)      { $fsql .= " AND id != ?"; $fp[] = $exact['id']; }
                $fsql .= " ORDER BY ABS(DATEDIFF(date_raised, ?)) ASC LIMIT 3";
                $fp[] = $date_raised;
                $fst = $pdo->prepare($fsql); $fst->execute($fp);
                $fuzzy = $fst->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'exact' => $exact, 'fuzzy' => $fuzzy]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
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

            // Billed/remaining come from the SHARED helper (ri_po_billing) so this page
            // and the PO details page can never disagree. When EDITING an invoice we
            // exclude its own amount from "billed" (so its current value isn't counted
            // against itself), then re-derive remaining/status from the adjusted figure.
            $billing  = ri_po_billing($pdo, $po_id);
            $grand    = $billing['po_total'];
            $invoiced = $billing['billed'];
            $count    = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE po_id = " . (int)$po_id . " AND status != 'deleted'")->fetchColumn();

            if ($exclude_id > 0) {
                $exStmt = $pdo->prepare("SELECT amount FROM supplier_invoices WHERE id = ? AND po_id = ? AND status != 'deleted'");
                $exStmt->execute([$exclude_id, $po_id]);
                $exAmt = (float)($exStmt->fetchColumn() ?: 0);
                if ($exAmt > 0) { $invoiced = round($invoiced - $exAmt, 2); $count = max(0, $count - 1); }
            }
            $remaining = round($grand - $invoiced, 2);
            $status    = ($invoiced <= 0.001) ? 'not_billed' : (($remaining <= 0.001) ? 'fully_billed' : 'partly_billed');

            echo json_encode(['success' => true, 'data' => [
                'po_id'          => $po_id,
                'order_number'   => $poRow['order_number'],
                'grand_total'    => $grand,
                'invoiced_total' => $invoiced,
                'remaining'      => $remaining,
                'billing_status' => $status,
                'billed_pct'     => $grand > 0 ? round($invoiced / $grand * 100, 1) : 0.0,
                'invoice_count'  => $count,
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
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);

        // When requested, scale the loaded quantities to the PO's REMAINING balance
        // (same as the PO "Convert to Invoice" flow) so a new invoice off a partly-
        // billed PO defaults to what's left to bill — not the full PO (which would
        // trip the over-invoice cap). Fraction = remaining / PO total (1.0 when nothing
        // is billed yet). exclude_id keeps a record out of its own "billed" when editing.
        if (!empty($_GET['scale_remaining'])) {
            $billing  = ri_po_billing($pdo, $po_id);
            $billed   = $billing['billed'];
            $poTotal  = $billing['po_total'];
            $exclude  = intval($_GET['exclude_id'] ?? 0);
            if ($exclude > 0) {
                $exStmt = $pdo->prepare("SELECT amount FROM supplier_invoices WHERE id = ? AND po_id = ? AND status != 'deleted'");
                $exStmt->execute([$exclude, $po_id]);
                $billed = round($billed - (float)($exStmt->fetchColumn() ?: 0), 2);
            }
            $remaining = round($poTotal - $billed, 2);
            $fraction  = ($poTotal > 0) ? max(0.0, min(1.0, $remaining / $poTotal)) : 1.0;
            if ($fraction < 0.9999) {
                foreach ($rows as &$r) { $r['quantity'] = round((float)$r['quantity'] * $fraction, 2); }
                unset($r);
            }
        }
        echo json_encode(['success' => true, 'data' => $rows]);
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

    // Selectable cost accounts for a goods Bill: active LEAF accounts that are an
    // Expense, COGS, Finance Cost, or the canonical Inventory account. Excludes
    // cash/bank/AR (those are never where a purchase cost lands).
    if ($action === 'get_cost_accounts') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $rows = $pdo->query("
            SELECT a.account_id AS id, CONCAT(a.account_code, ' — ', a.account_name) AS text
              FROM accounts a
              LEFT JOIN account_types t ON t.type_id = a.account_type_id
             WHERE a.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_account_id = a.account_id)
               AND ( a.account_type = 'expense'
                     OR t.category IN ('expense','cogs','finance_cost')
                     OR a.account_code = '1-1300' )
          ORDER BY a.account_code
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
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

    $invoice_type  = trim($_POST['invoice_type']  ?? '');
    $supplier_id   = intval($_POST['supplier_id'] ?? 0);
    $invoice_ref   = trim($_POST['invoice_ref']   ?? '');
    $date_raised   = trim($_POST['date_raised']   ?? '');
    $date_recorded = trim($_POST['date_recorded'] ?? date('Y-m-d'));
    $amount        = floatval($_POST['amount']    ?? 0);
    $notes         = trim($_POST['notes']         ?? '');

    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $valid_fixed   = ['', 'COD', 'Net7', 'Net14', 'Net30', 'Net45', 'Net60', 'Custom'];
    if (!in_array($payment_terms, $valid_fixed, true) && !preg_match('/^Net\d+$/', $payment_terms)) {
        $payment_terms = '';
    }
    $due_date = ri_compute_due_date($date_raised, $payment_terms, trim($_POST['due_date'] ?? ''));

    if (!in_array($invoice_type, ['supplier', 'sub_contractor'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice type']); exit;
    }

    // Both supplier and sub-contractor invoices derive the amount from line items
    // (same money math as the customer invoice). Supplier items auto-fill from a
    // PO; sub-contractor items are entered manually (no PO).
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : null;
    $item_rows    = [];
    $ri_subtotal  = null;   // ex-VAT base, when line items break out VAT
    $ri_tax       = null;   // input VAT total
    if (!empty($_POST['items'])) {
        [$sub, $tax, $grand, $item_rows] = ri_compute_items($_POST['items']);
        if ($item_rows) { $amount = $grand; $ri_subtotal = $sub; $ri_tax = $tax; }
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

    // Cost account (optional, goods/supplier Bills only): the GL account the cost
    // should be debited to (Expense / COGS / Asset-Inventory leaf). Null → posting
    // falls back to the canonical Inventory account, so this is zero-regression.
    $cost_account_id = null;
    if ($invoice_type === 'supplier' && !empty($_POST['cost_account_id'])) {
        $cost_account_id = intval($_POST['cost_account_id']);
        $ca = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND status = 'active'");
        $ca->execute([$cost_account_id]);
        if (!$ca->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Selected cost account is invalid or inactive']); exit;
        }
    }

    // Hard-block exact duplicate on create: same supplier + same ref already exists
    $dupChk = $pdo->prepare("SELECT id, status FROM supplier_invoices WHERE supplier_id = ? AND LOWER(TRIM(invoice_ref)) = LOWER(TRIM(?)) AND status != 'deleted' LIMIT 1");
    $dupChk->execute([$supplier_id, $invoice_ref]);
    $dupRow = $dupChk->fetch(PDO::FETCH_ASSOC);
    if ($dupRow) {
        echo json_encode(['success' => false, 'message' => "Duplicate: invoice reference \"{$invoice_ref}\" already exists for this supplier (Invoice #{$dupRow['id']}, status: {$dupRow['status']}). Please verify this is not a duplicate."]);
        exit;
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
                 payment_terms, due_date,
                 po_id, project_id, warehouse_id, sc_invoice_basis, sc_basis_ref,
                 amount, cost_account_id, subtotal, tax_amount, attachment, notes, status, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $invoice_type, $supplier_id, $invoice_ref, $date_raised, $date_recorded,
            ($payment_terms ?: null), $due_date,
            $po_id, $project_id, $warehouse_id, $sc_invoice_basis, $sc_basis_ref,
            $amount, $cost_account_id, $ri_subtotal, $ri_tax, $attachment, $notes, $_SESSION['user_id']
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

    $id            = intval($_POST['id']           ?? 0);
    $invoice_ref   = trim($_POST['invoice_ref']   ?? '');
    $date_raised   = trim($_POST['date_raised']   ?? '');
    $date_recorded = trim($_POST['date_recorded'] ?? '');
    $amount        = floatval($_POST['amount']    ?? 0);
    $notes         = trim($_POST['notes']         ?? '');

    // Amount is validated after the supplier items recompute below (a supplier
    // invoice posts amount=0 and derives it from the line items).
    if (!$id || !$invoice_ref || !$date_raised) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing']); exit;
    }

    $existing = $pdo->prepare("SELECT * FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $existing->execute([$id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }

    // Payment terms + due date
    $payment_terms = trim($_POST['payment_terms'] ?? $row['payment_terms'] ?? '');
    $valid_fixed   = ['', 'COD', 'Net7', 'Net14', 'Net30', 'Net45', 'Net60', 'Custom'];
    if (!in_array($payment_terms, $valid_fixed, true) && !preg_match('/^Net\d+$/', $payment_terms)) {
        $payment_terms = $row['payment_terms'] ?? '';
    }
    $due_date = ri_compute_due_date($date_raised, $payment_terms, trim($_POST['due_date'] ?? ''))
                ?? $row['due_date'] ?? null;

    // Both types recompute the amount from their line items (same money math).
    $warehouse_id = !empty($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : $row['warehouse_id'];
    $item_rows    = [];
    $ri_subtotal  = null;
    $ri_tax       = null;
    if (!empty($_POST['items'])) {
        [$sub, $tax, $grand, $item_rows] = ri_compute_items($_POST['items']);
        if ($item_rows) { $amount = $grand; $ri_subtotal = $sub; $ri_tax = $tax; }
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

    // Cost account (optional, supplier Bills only): default to the existing value so
    // an edit that doesn't send the field preserves it; empty value clears it (→ fallback).
    $cost_account_id = $row['cost_account_id'] ?? null;
    if ($row['invoice_type'] === 'supplier' && array_key_exists('cost_account_id', $_POST)) {
        $cost_account_id = !empty($_POST['cost_account_id']) ? intval($_POST['cost_account_id']) : null;
        if ($cost_account_id) {
            $ca = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND status = 'active'");
            $ca->execute([$cost_account_id]);
            if (!$ca->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Selected cost account is invalid or inactive']); exit;
            }
        }
    }

    // Hard-block changing ref to one that already exists for the same supplier (excluding self)
    $dupChk2 = $pdo->prepare("SELECT id FROM supplier_invoices WHERE supplier_id = ? AND LOWER(TRIM(invoice_ref)) = LOWER(TRIM(?)) AND status != 'deleted' AND id != ? LIMIT 1");
    $dupChk2->execute([$row['supplier_id'], $invoice_ref, $id]);
    if ($dupChk2->fetch()) {
        echo json_encode(['success' => false, 'message' => "Duplicate: invoice reference \"{$invoice_ref}\" already exists for this supplier. Change the reference or verify this is not a duplicate."]);
        exit;
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
                payment_terms = ?, due_date = ?,
                po_id = ?, project_id = ?, warehouse_id = ?, sc_invoice_basis = ?, sc_basis_ref = ?,
                amount = ?, cost_account_id = ?, subtotal = ?, tax_amount = ?, attachment = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $invoice_ref, $date_raised, $date_recorded,
            ($payment_terms ?: null), $due_date,
            $po_id, $project_id, $warehouse_id, $sc_invoice_basis, $sc_basis_ref,
            $amount, $cost_account_id, $ri_subtotal, $ri_tax, $attachment, $notes, $id
        ]);
        ri_save_items($pdo, $id, $item_rows);
        // If this invoice already recognised input VAT (it was approved before
        // this edit), re-sync the VAT control account to the new tax amount.
        $wasPosted = $pdo->prepare("SELECT input_vat_posted FROM supplier_invoices WHERE id = ?");
        $wasPosted->execute([$id]);
        if ($wasPosted->fetchColumn() !== null) {
            reverseInputVat($pdo, (int)$id);
            postInputVat($pdo, (int)$id);
        }
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
        // VAT (accrual): recognise input VAT now the received invoice is approved —
        // debits Input VAT Recoverable by the invoice's tax_amount. Idempotent.
        if ($new_status === 'approved') postInputVat($pdo, (int)$id);
        // OUT-3 (accrual): a sub-contractor invoice is COGS — recognise it in the GL
        // now (Dr COGS / Cr Accounts Payable); the supplier payment later settles
        // the same AP. Idempotent; only sub_contractor invoices accrue here.
        if ($new_status === 'approved') postSubcontractorAccrual($pdo, (int)$id, (int)$_SESSION['user_id']);
        // OUT-7 (accrual): a GOODS supplier invoice now recognises its payable at
        // approval time (Dr Inventory / Cr Accounts Payable) — GRN approval no
        // longer posts to the GL. Idempotent; amount-based guard nets off any
        // value already posted via an old-rule GRN for the same PO. Best-effort.
        if ($new_status === 'approved') postGoodsInvoiceAccrual($pdo, (int)$id, (int)$_SESSION['user_id']);
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

// ── GET INVOICE PAYMENTS ───────────────────────────────────────────────────
if ($action === 'get_invoice_payments') {
    if (!canView('received_invoices')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    $invoice_id = intval($_GET['invoice_id'] ?? 0);
    if (!$invoice_id) { echo json_encode(['success' => false, 'message' => 'invoice_id required']); exit; }
    try {
        $rows = $pdo->prepare("
            SELECT sip.*,
                   a.account_name,
                   CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
            FROM supplier_invoice_payments sip
            LEFT JOIN accounts a ON sip.payment_account_id = a.account_id
            LEFT JOIN users u    ON sip.recorded_by = u.user_id
            WHERE sip.invoice_id = ?
            ORDER BY sip.payment_date ASC, sip.id ASC
        ");
        $rows->execute([$invoice_id]);
        echo json_encode(['success' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
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
    $wht_rate_id     = !empty($_POST['wht_rate_id']) ? (int)$_POST['wht_rate_id'] : null;
    // payment_amount is optional — when omitted, it defaults to the full remaining balance
    // (preserves backward compatibility with callers that pre-date partial payments).
    $payment_amount_raw = isset($_POST['payment_amount']) && $_POST['payment_amount'] !== '' ? (float)$_POST['payment_amount'] : null;

    if (!$invoice_id || !$payment_date || !$payment_method) {
        echo json_encode(['success' => false, 'message' => 'Payment date and method are required']); exit;
    }
    if (!$payment_account) {
        echo json_encode(['success' => false, 'message' => 'Please choose the account the payment was made from (Paid From)']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment date format']); exit;
    }

    $stmt = $pdo->prepare("SELECT status, invoice_ref, amount, amount_paid, subtotal, supplier_id, project_id FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }
    if (!in_array($inv['status'], ['approved', 'partial'])) {
        echo json_encode(['success' => false, 'message' => 'Only approved or partially paid invoices can receive payments']); exit;
    }

    $inv_total    = (float)$inv['amount'];
    $already_paid = (float)$inv['amount_paid'];
    $remaining    = round($inv_total - $already_paid, 2);

    // Default to full remaining balance when no amount explicitly provided
    $payment_amount = $payment_amount_raw !== null ? round($payment_amount_raw, 2) : $remaining;

    if ($payment_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than zero']); exit;
    }

    if ($payment_amount > $remaining + 0.005) {
        echo json_encode(['success' => false, 'message' => 'Payment amount (' . number_format($payment_amount, 2) . ') exceeds the remaining balance (' . number_format($remaining, 2) . ')']); exit;
    }

    // WHT is proportional to the fraction of invoice this payment covers.
    // WHT base is the VAT-exclusive subtotal fraction for this payment.
    $inv_subtotal  = (float)($inv['subtotal'] ?? 0);
    $wht_base_full = ($inv_subtotal > 0) ? $inv_subtotal : $inv_total;
    $wht_base      = ($inv_total > 0) ? round($wht_base_full * ($payment_amount / $inv_total), 2) : $wht_base_full;
    $wht_rate      = $wht_rate_id ? whtRatePercent($pdo, $wht_rate_id) : 0.0;
    $wht_amt       = $wht_rate > 0 ? computeWht($wht_base, $wht_rate) : 0.0;
    $wht_acc       = $wht_amt > 0 ? whtPayableAccountId($pdo) : null;
    if ($wht_amt > 0 && !$wht_acc) {
        echo json_encode(['success' => false, 'message' => 'WHT was selected but no WHT Payable account is configured. Ask an admin to set it in settings.']); exit;
    }
    if ($wht_amt > 0 && $wht_amt >= $payment_amount) {
        echo json_encode(['success' => false, 'message' => 'Withholding tax cannot meet or exceed the payment amount.']); exit;
    }

    $new_amount_paid = round($already_paid + $payment_amount, 2);
    $new_status = ($new_amount_paid >= $inv_total - 0.005) ? 'paid' : 'partial';

    // MONEY-SAFETY (Step 8): I3 "warn but allow" — note a short balance, never block.
    $funds_warn = accountFundsWarning($pdo, (int)$payment_account, (float)$payment_amount);

    try {
        $pdo->beginTransaction();
        // Dr Accounts Payable (payment slice) / Cr Paid-From / Cr WHT Payable — FAIL LOUDLY:
        // a failed post throws the real reason and the whole payment rolls back rather than
        // saving an off-book supplier-invoice payment.
        $txn = postOutflowOrFail(
            $pdo, 'received_invoice_payment', $payment_account, defaultPayableAccountId($pdo),
            $payment_amount, $payment_date, $inv['invoice_ref'],
            "Received invoice {$inv['invoice_ref']} — payment" . ($new_status === 'partial' ? ' (partial)' : ''),
            $inv['project_id'] ? (int)$inv['project_id'] : null,
            $wht_amt, $wht_acc
        );

        // Record the individual payment instalment
        $pdo->prepare("
            INSERT INTO supplier_invoice_payments
                (invoice_id, payment_date, amount, payment_method, payment_account_id,
                 reference, wht_rate_id, wht_base, wht_amount, journal_txn_id, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $invoice_id, $payment_date, $payment_amount, $payment_method, $payment_account,
            $payment_ref ?: null,
            ($wht_amt > 0 ? $wht_rate_id : null),
            ($wht_amt > 0 ? $wht_base    : null),
            ($wht_amt > 0 ? $wht_amt     : null),
            $txn, $_SESSION['user_id']
        ]);

        // Update invoice running total and status
        $upd = $pdo->prepare("
            UPDATE supplier_invoices
            SET amount_paid = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$new_amount_paid, $new_status, $invoice_id]);

        // On full payment: also stamp the legacy single-payment columns for backward compat
        if ($new_status === 'paid') {
            $pdo->prepare("
                UPDATE supplier_invoices
                SET payment_date = ?, payment_method = ?, payment_account_id = ?,
                    payment_ref = ?, payment_transaction_id = ?, payment_recorded_by = ?,
                    wht_rate_id = ?, wht_base = ?, wht_amount = ?, wht_posted = ?
                WHERE id = ?
            ")->execute([
                $payment_date, $payment_method, $payment_account, $payment_ref, $txn, $_SESSION['user_id'],
                ($wht_amt > 0 ? $wht_rate_id : null),
                ($wht_amt > 0 ? $wht_base    : null),
                ($wht_amt > 0 ? $wht_amt     : null),
                ($wht_amt > 0 ? $wht_amt     : null),
                $invoice_id
            ]);
        }

        $pdo->commit();

        // Bank register — net cash leaving the payment account (gross minus WHT withheld at source)
        $cashOut = round($payment_amount - $wht_amt, 2);
        if ($payment_account > 0 && $cashOut > 0) {
            recordBankTransaction($pdo, $payment_account, $cashOut, 'withdrawal',
                $payment_date, $inv['invoice_ref'],
                "Supplier invoice {$inv['invoice_ref']} payment", (int)$_SESSION['user_id']);
        }

        $wht_note = $wht_amt > 0
            ? " WHT " . number_format($wht_amt, 2) . " withheld; net paid " . number_format($payment_amount - $wht_amt, 2) . "."
            : "";
        $status_note = $new_status === 'paid' ? 'Invoice fully paid.' : 'Remaining balance: TZS ' . number_format($remaining - $payment_amount, 2) . '.';
        logActivity($pdo, $_SESSION['user_id'],
            "Payment of " . number_format($payment_amount, 2) . " recorded for invoice #{$inv['invoice_ref']} — method: {$payment_method}. {$status_note}{$wht_note}");
        $warn_note = $funds_warn ? ' ' . $funds_warn : '';
        echo json_encode(['success' => true, 'message' => 'Payment recorded. ' . $status_note . $wht_note . $warn_note, 'new_status' => $new_status, 'funds_warning' => $funds_warn]);
    } catch (MoneyPostingException $e) {
        // Money-safety: the ledger post failed — roll back and surface the real reason.
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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

    $chk = $pdo->prepare("SELECT invoice_ref, amount_paid, status, payment_transaction_id FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }

    // Guard: a Bill with recorded payment(s) must not be deleted. Deleting reverses
    // the AP accrual, but the payment's (Dr AP / Cr Bank) entry would remain →
    // AP corrupted. Require the payment(s) be removed/voided first.
    if (supplierInvoiceHasPayments($pdo, (int)$id)) {
        echo json_encode(['success' => false, 'message' => 'This Bill has recorded payment(s) and cannot be deleted. Remove or void the payment(s) first.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        // Un-recognise any input VAT this invoice posted (reverses Input VAT
        // Recoverable by the exact amount posted; no-op if never approved).
        reverseInputVat($pdo, (int)$id);
        // Reverse any sub-contractor COGS accrual (no-op if never approved). OUT-3.
        reverseSubcontractorAccrual($pdo, (int)$id, (int)$_SESSION['user_id']);
        // Reverse any goods-invoice payable accrual (no-op if never approved, or
        // if it was covered_by_grn — nothing was posted to reverse). OUT-7.
        reverseGoodsInvoiceAccrual($pdo, (int)$id, (int)$_SESSION['user_id']);
        $pdo->prepare("UPDATE supplier_invoices SET status = 'deleted', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'], "Deleted received invoice #{$row['invoice_ref']} (ID {$id})");
        echo json_encode(['success' => true, 'message' => 'Invoice deleted']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
