<?php
// File: api/purchase/update_debit_note.php
// Updates a PENDING debit note (header + line items). Recomputes totals
// server-side. Non-pending notes are immutable here.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('debit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;
$id          = intval($_POST['debit_note_id'] ?? 0);
$supplier_id = intval($_POST['supplier_id'] ?? 0);
$debit_date  = $_POST['debit_date'] ?? date('Y-m-d');
$reason      = trim($_POST['reason'] ?? '');
$notes       = trim($_POST['notes'] ?? '');
$items       = json_decode($_POST['items'] ?? '[]', true);

if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid debit note ID']); exit; }
if ($supplier_id <= 0) { echo json_encode(['success' => false, 'message' => 'Supplier is required']); exit; }
if (!is_array($items) || count($items) === 0) { echo json_encode(['success' => false, 'message' => 'At least one line item is required']); exit; }

try {
    $stmt = $pdo->prepare("SELECT debit_note_number, status FROM debit_notes WHERE debit_note_id = ?");
    $stmt->execute([$id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn || $dn['status'] === 'deleted') { echo json_encode(['success' => false, 'message' => 'Debit note not found']); exit; }
    if ($dn['status'] !== 'pending') { echo json_encode(['success' => false, 'message' => 'Only a pending debit note can be edited.']); exit; }

    $pdo->beginTransaction();

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

    $pdo->prepare("
        UPDATE debit_notes
           SET supplier_id = ?, debit_date = ?, reason = ?, notes = ?,
               subtotal_amount = ?, total_tax = ?, grand_total = ?, updated_at = NOW()
         WHERE debit_note_id = ?
    ")->execute([$supplier_id, $debit_date, $reason, $notes, $subtotal, $total_tax, $grand_total, $id]);

    $pdo->prepare("DELETE FROM debit_note_items WHERE debit_note_id = ?")->execute([$id]);
    $insItem = $pdo->prepare("
        INSERT INTO debit_note_items
            (debit_note_id, product_id, description, quantity, unit_price, tax_rate, tax_amount, total_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($clean as $c) {
        $insItem->execute([$id, $c['pid'], $c['desc'], $c['qty'], $c['price'], $c['rate'], $c['tax'], $c['total']]);
    }

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Update Debit Note',
        "$user_name updated Debit Note #{$dn['debit_note_number']} (Total: " . number_format($grand_total, 2) . ")");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Debit note updated successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
