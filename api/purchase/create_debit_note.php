<?php
// File: api/purchase/create_debit_note.php
// Creates a debit note (status 'pending') + its line items, captures the
// 'created' e-signature. GET ?action=get_next_ref returns the next DBN number.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

global $pdo;

/** Next DBN-YYYY-#### for the current year. */
function dn_next_number(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT debit_note_number FROM debit_notes WHERE debit_note_number LIKE ? ORDER BY debit_note_id DESC LIMIT 1");
    $stmt->execute(["DBN-$year-%"]);
    $last = $stmt->fetchColumn();
    $seq = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
    return sprintf('DBN-%s-%04d', $year, $seq);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_next_ref') {
    if (!canView('debit_notes')) { echo json_encode(['success' => false]); exit; }
    echo json_encode(['success' => true, 'ref' => dn_next_number($pdo)]);
    exit;
}

if (!canCreate('debit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$supplier_id        = intval($_POST['supplier_id'] ?? 0);
$debit_date         = $_POST['debit_date'] ?? date('Y-m-d');
$purchase_return_id = !empty($_POST['purchase_return_id']) ? intval($_POST['purchase_return_id']) : null;
$reason             = trim($_POST['reason'] ?? '');
$notes              = trim($_POST['notes'] ?? '');
$project_id_in      = !empty($_POST['project_id']) ? intval($_POST['project_id']) : 0;
$items              = json_decode($_POST['items'] ?? '[]', true);

// Project tag (in-project create). Only honour a project the user may access;
// otherwise it is resolved from the linked purchase return below.
require_once __DIR__ . '/../../core/project_scope.php';
$project_id = ($project_id_in > 0 && userCan('project', $project_id_in)) ? $project_id_in : null;

if ($supplier_id <= 0) { echo json_encode(['success' => false, 'message' => 'Supplier is required']); exit; }
if (!$purchase_return_id) { echo json_encode(['success' => false, 'message' => 'An approved purchase return is required.']); exit; }
if (!is_array($items) || count($items) === 0) { echo json_encode(['success' => false, 'message' => 'At least one line item is required']); exit; }

try {
    $pdo->beginTransaction();

    if ($purchase_return_id) {
        $dup = $pdo->prepare("SELECT debit_note_number FROM debit_notes
                               WHERE purchase_return_id = ? AND status NOT IN ('deleted','rejected','cancelled') LIMIT 1");
        $dup->execute([$purchase_return_id]);
        if ($existing = $dup->fetchColumn()) {
            throw new Exception("This purchase return already has debit note {$existing}.");
        }
    }

    // Derive the project from the linked purchase return when not set in-context.
    if ($project_id === null && $purchase_return_id) {
        $pr = $pdo->prepare("SELECT project_id FROM purchase_returns WHERE purchase_return_id = ?");
        $pr->execute([$purchase_return_id]);
        $project_id = ($v = $pr->fetchColumn()) ? (int)$v : null;
    }

    $subtotal = 0.0; $total_tax = 0.0; $clean = [];
    foreach ($items as $it) {
        $desc = trim($it['description'] ?? '');
        $qty  = (float)($it['quantity'] ?? 0);
        $price= (float)($it['unit_price'] ?? 0);
        $rate = ((float)($it['tax_rate'] ?? 0) == 18) ? 18 : 0;
        $pid  = (isset($it['product_id']) && $it['product_id'] !== '' && $it['product_id'] !== null) ? (int)$it['product_id'] : null;
        if ($desc === '' || $qty <= 0) continue;
        $base = $qty * $price; $tax = $base * ($rate / 100);
        $subtotal += $base; $total_tax += $tax;
        $clean[] = ['pid'=>$pid,'desc'=>$desc,'qty'=>$qty,'price'=>$price,'rate'=>$rate,'tax'=>$tax,'total'=>$base+$tax];
    }
    if (count($clean) === 0) { throw new Exception('No valid line items.'); }
    $grand_total = $subtotal + $total_tax;

    $number = dn_next_number($pdo);

    $ins = $pdo->prepare("
        INSERT INTO debit_notes
            (debit_note_number, supplier_id, purchase_return_id, project_id, debit_date, reason, notes,
             subtotal_amount, total_tax, grand_total, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $ins->execute([$number, $supplier_id, $purchase_return_id, $project_id, $debit_date, $reason, $notes,
                   $subtotal, $total_tax, $grand_total, $_SESSION['user_id']]);
    $dn_id = (int)$pdo->lastInsertId();

    $actor = workflowActorSnapshot();
    workflowCaptureSignature($pdo, 'debit_note', $dn_id, 'created',
        (int)$_SESSION['user_id'], $actor['name'], $actor['role']);

    $insItem = $pdo->prepare("
        INSERT INTO debit_note_items
            (debit_note_id, product_id, description, quantity, unit_price, tax_rate, tax_amount, total_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($clean as $c) {
        $insItem->execute([$dn_id, $c['pid'], $c['desc'], $c['qty'], $c['price'], $c['rate'], $c['tax'], $c['total']]);
    }

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Create Debit Note',
        "$user_name created Debit Note #$number (Total: " . number_format($grand_total, 2) . ")");

    $pdo->commit();

    // Attachments are saved AFTER commit (file moves stay out of the DB
    // transaction). Best-effort: a failed attachment never undoes the note.
    $att = ['saved' => 0, 'errors' => []];
    try {
        require_once __DIR__ . '/../../core/note_attachments.php';
        $att = saveNoteAttachments($pdo, 'debit_note_attachments', 'debit_note_id', $dn_id, 'debit_notes');
    } catch (Throwable $e) { error_log('debit note attachments: ' . $e->getMessage()); }

    $msg = 'Debit note created successfully.';
    if ($att['saved'] > 0) $msg .= " {$att['saved']} attachment(s) uploaded.";

    // Smart-notification: a new debit note needs attention. Fail-safe + kill-switched.
    require_once __DIR__ . '/../../core/notify.php';
    dispatchEvent($pdo, 'debit_note.pending', [
        'entity_type' => 'debit_note',
        'entity_id'   => (int)$dn_id,
        'project_id'  => $project_id !== null ? (int)$project_id : null,
        'title'       => 'Debit note pending: ' . $number,
        'message'     => 'A new debit note ' . $number . ' has been created and needs attention.',
        'action_url'  => 'debit_note_view?id=' . (int)$dn_id,
    ]);

    echo json_encode(['success' => true, 'id' => $dn_id, 'message' => $msg,
                      'attachment_errors' => $att['errors']]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
