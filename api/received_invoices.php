<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = trim(($_GET['action'] ?? $_POST['action'] ?? ''));

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

        $where  = ["si.status != 'deleted'"];
        $params = [];

        if ($type)        { $where[] = 'si.invoice_type = ?'; $params[] = $type; }
        if ($supplier_id) { $where[] = 'si.supplier_id = ?';  $params[] = $supplier_id; }
        if ($status)      { $where[] = 'si.status = ?';        $params[] = $status; }

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
            WHERE " . implode(' AND ', $where) . "
            ORDER BY si.date_recorded DESC, si.id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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

        $stmt = $pdo->prepare("
            SELECT si.*,
                   COALESCE(s.supplier_name, sc.supplier_name) AS party_name,
                   po.order_number AS po_number,
                   p.project_name
            FROM supplier_invoices si
            LEFT JOIN suppliers s        ON si.invoice_type = 'supplier'       AND s.supplier_id  = si.supplier_id
            LEFT JOIN sub_contractors sc ON si.invoice_type = 'sub_contractor' AND sc.supplier_id = si.supplier_id
            LEFT JOIN purchase_orders po ON si.po_id       = po.purchase_order_id
            LEFT JOIN projects p         ON si.project_id  = p.project_id
            WHERE si.id = ? AND si.status != 'deleted'
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }
        echo json_encode(['success' => true, 'data' => $row]);
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

    if ($action === 'get_pos') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $supplier_id = intval($_GET['supplier_id'] ?? 0);
        if (!$supplier_id) { echo json_encode(['success' => true, 'data' => []]); exit; }

        $rows = $pdo->prepare("
            SELECT purchase_order_id AS id,
                   CONCAT(order_number, ' — TZS ', FORMAT(grand_total, 0)) AS text,
                   order_number,
                   grand_total,
                   order_date
            FROM purchase_orders
            WHERE supplier_id = ? AND status NOT IN ('cancelled')
            ORDER BY order_date DESC
        ");
        $rows->execute([$supplier_id]);
        echo json_encode(['success' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_projects') {
        if (!canView('received_invoices')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $supplier_id = intval($_GET['supplier_id'] ?? 0);
        if (!$supplier_id) { echo json_encode(['success' => true, 'data' => []]); exit; }

        $stmt = $pdo->prepare("
            SELECT p.project_id AS id, p.project_name AS text
            FROM projects p
            INNER JOIN sub_contractor_projects scp ON scp.project_id = p.project_id
            WHERE scp.supplier_id = ?
            ORDER BY p.project_name
        ");
        $stmt->execute([$supplier_id]);
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
    if (!$supplier_id || !$invoice_ref || !$date_raised || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing or amount must be greater than 0']); exit;
    }

    $po_id            = null;
    $project_id       = null;
    $sc_invoice_basis = null;
    $sc_basis_ref     = null;

    if ($invoice_type === 'supplier') {
        $po_id = !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
    } else {
        $project_id       = !empty($_POST['project_id'])       ? intval($_POST['project_id'])          : null;
        $sc_invoice_basis = !empty($_POST['sc_invoice_basis']) ? trim($_POST['sc_invoice_basis'])       : null;
        $sc_basis_ref     = !empty($_POST['sc_basis_ref'])     ? trim($_POST['sc_basis_ref'])           : null;
        if ($sc_invoice_basis && !in_array($sc_invoice_basis, ['IPC','Milestone','Scope','Final'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid invoice basis']); exit;
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

    $stmt = $pdo->prepare("
        INSERT INTO supplier_invoices
            (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded,
             po_id, project_id, sc_invoice_basis, sc_basis_ref,
             amount, attachment, notes, status, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
    ");
    $stmt->execute([
        $invoice_type, $supplier_id, $invoice_ref, $date_raised, $date_recorded,
        $po_id, $project_id, $sc_invoice_basis, $sc_basis_ref,
        $amount, $attachment, $notes, $_SESSION['user_id']
    ]);
    $new_id = $pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'],
        "Recorded received invoice #{$invoice_ref} from {$invoice_type} ID {$supplier_id} — amount {$amount}");

    echo json_encode(['success' => true, 'message' => 'Invoice recorded successfully', 'id' => $new_id]);
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

    if (!$id || !$invoice_ref || !$date_raised || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing']); exit;
    }

    $existing = $pdo->prepare("SELECT * FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $existing->execute([$id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Invoice not found']); exit; }

    $po_id            = $row['po_id'];
    $project_id       = $row['project_id'];
    $sc_invoice_basis = $row['sc_invoice_basis'];
    $sc_basis_ref     = $row['sc_basis_ref'];

    if ($row['invoice_type'] === 'supplier') {
        $po_id = !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
    } else {
        $project_id       = !empty($_POST['project_id'])       ? intval($_POST['project_id'])    : null;
        $sc_invoice_basis = !empty($_POST['sc_invoice_basis']) ? trim($_POST['sc_invoice_basis']) : null;
        $sc_basis_ref     = !empty($_POST['sc_basis_ref'])     ? trim($_POST['sc_basis_ref'])     : null;
    }

    $attachment = $row['attachment'];
    if (!empty($_FILES['attachment']['name'])) {
        $upload = handleAttachmentUpload();
        if (!$upload['success']) {
            echo json_encode(['success' => false, 'message' => $upload['message']]); exit;
        }
        $attachment = $upload['path'];
    }

    $pdo->prepare("
        UPDATE supplier_invoices SET
            invoice_ref = ?, date_raised = ?, date_recorded = ?,
            po_id = ?, project_id = ?, sc_invoice_basis = ?, sc_basis_ref = ?,
            amount = ?, attachment = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $invoice_ref, $date_raised, $date_recorded,
        $po_id, $project_id, $sc_invoice_basis, $sc_basis_ref,
        $amount, $attachment, $notes, $id
    ]);

    logActivity($pdo, $_SESSION['user_id'], "Updated received invoice #{$invoice_ref} (ID {$id})");

    echo json_encode(['success' => true, 'message' => 'Invoice updated successfully']);
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

    $pdo->prepare("UPDATE supplier_invoices SET status = 'deleted', updated_at = NOW() WHERE id = ?")
        ->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted received invoice #{$row['invoice_ref']} (ID {$id})");

    echo json_encode(['success' => true, 'message' => 'Invoice deleted']);
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
