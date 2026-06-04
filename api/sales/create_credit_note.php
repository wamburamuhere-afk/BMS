<?php
// File: api/sales/create_credit_note.php
// Creates a credit note (status 'pending') + its line items, captures the
// 'created' e-signature. GET ?action=get_next_ref returns the next CN number.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

global $pdo;

/** Next CN-YYYY-#### for the current year. */
function cn_next_number(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT credit_note_number FROM credit_notes WHERE credit_note_number LIKE ? ORDER BY credit_note_id DESC LIMIT 1");
    $stmt->execute(["CN-$year-%"]);
    $last = $stmt->fetchColumn();
    $seq = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
    return sprintf('CN-%s-%04d', $year, $seq);
}

// ── GET: next reference number (for the §UI-6 refresh button) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_next_ref') {
    if (!canView('credit_notes')) { echo json_encode(['success' => false]); exit; }
    echo json_encode(['success' => true, 'ref' => cn_next_number($pdo)]);
    exit;
}

if (!canCreate('credit_notes')) {
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

$customer_id     = intval($_POST['customer_id'] ?? 0);
$credit_date     = $_POST['credit_date'] ?? date('Y-m-d');
$sales_return_id = !empty($_POST['sales_return_id']) ? intval($_POST['sales_return_id']) : null;
$reason          = trim($_POST['reason'] ?? '');
$notes           = trim($_POST['notes'] ?? '');
$items           = json_decode($_POST['items'] ?? '[]', true);

if ($customer_id <= 0) { echo json_encode(['success' => false, 'message' => 'Customer is required']); exit; }
if (!is_array($items) || count($items) === 0) { echo json_encode(['success' => false, 'message' => 'At least one line item is required']); exit; }

try {
    $pdo->beginTransaction();

    // Guard against double-crediting a return
    if ($sales_return_id) {
        $dup = $pdo->prepare("SELECT credit_note_number FROM credit_notes
                               WHERE sales_return_id = ? AND status NOT IN ('deleted','rejected','cancelled') LIMIT 1");
        $dup->execute([$sales_return_id]);
        if ($existing = $dup->fetchColumn()) {
            throw new Exception("This sales return already has credit note {$existing}.");
        }
    }

    // Compute totals (per-line VAT, BMS {0,18})
    $subtotal = 0.0; $total_tax = 0.0; $clean = [];
    foreach ($items as $it) {
        $desc = trim($it['description'] ?? '');
        $qty  = (float)($it['quantity'] ?? 0);
        $price= (float)($it['unit_price'] ?? 0);
        $rate = ((float)($it['tax_rate'] ?? 0) == 18) ? 18 : 0;
        $pid  = (isset($it['product_id']) && $it['product_id'] !== '' && $it['product_id'] !== null) ? (int)$it['product_id'] : null;
        if ($desc === '' || $qty <= 0) continue;
        $base = $qty * $price;
        $tax  = $base * ($rate / 100);
        $subtotal += $base; $total_tax += $tax;
        $clean[] = ['pid'=>$pid,'desc'=>$desc,'qty'=>$qty,'price'=>$price,'rate'=>$rate,'tax'=>$tax,'total'=>$base+$tax];
    }
    if (count($clean) === 0) { throw new Exception('No valid line items.'); }
    $grand_total = $subtotal + $total_tax;

    $number = cn_next_number($pdo);

    $ins = $pdo->prepare("
        INSERT INTO credit_notes
            (credit_note_number, customer_id, sales_return_id, credit_date, reason, notes,
             subtotal_amount, total_tax, grand_total, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $ins->execute([$number, $customer_id, $sales_return_id, $credit_date, $reason, $notes,
                   $subtotal, $total_tax, $grand_total, $_SESSION['user_id']]);
    $cn_id = (int)$pdo->lastInsertId();

    // 'created' e-signature
    $actor = workflowActorSnapshot();
    workflowCaptureSignature($pdo, 'credit_note', $cn_id, 'created',
        (int)$_SESSION['user_id'], $actor['name'], $actor['role']);

    $insItem = $pdo->prepare("
        INSERT INTO credit_note_items
            (credit_note_id, product_id, description, quantity, unit_price, tax_rate, tax_amount, total_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($clean as $c) {
        $insItem->execute([$cn_id, $c['pid'], $c['desc'], $c['qty'], $c['price'], $c['rate'], $c['tax'], $c['total']]);
    }

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Create Credit Note',
        "$user_name created Credit Note #$number (Total: " . number_format($grand_total, 2) . ")");

    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $cn_id, 'message' => 'Credit note created successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
