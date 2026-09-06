<?php
// scope-audit: skip — tender BOQ sub-data; same entity/scope as tenders (customers, not project-scoped); deferred to Phase G-2
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/tender_boq.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('tenders') && !canCreate('tenders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit tender BOQ data']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

global $pdo;
$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$tender_id = (int)($_POST['tender_id'] ?? 0);

if (!$tender_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid Tender ID']);
    exit;
}

// Every mutation must be scoped to a real, non-deleted tender.
$tenderCheck = $pdo->prepare("SELECT tender_id FROM tenders WHERE tender_id = ?");
$tenderCheck->execute([$tender_id]);
if (!$tenderCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Tender not found']);
    exit;
}

try {
    switch ($action) {

        case 'ADD_BILL':
            $title = trim($_POST['bill_title'] ?? '') ?: 'Untitled Bill';
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tender_boq_bills WHERE tender_id = ?");
            $orderStmt->execute([$tender_id]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$tender_id, $title, $nextOrder]);
            $billId = (int)$pdo->lastInsertId();

            logActivity($pdo, $user_id, 'CREATE', "[Tender BOQ] Added bill '$title' to tender #$tender_id");
            echo json_encode(['success' => true, 'message' => 'Bill added.', 'bill_id' => $billId]);
            break;

        case 'DELETE_BILL':
            $billId = (int)($_POST['bill_id'] ?? 0);
            $own = $pdo->prepare("SELECT bill_id FROM tender_boq_bills WHERE bill_id = ? AND tender_id = ?");
            $own->execute([$billId, $tender_id]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Bill not found for this tender']);
                exit;
            }
            $pdo->prepare("DELETE FROM tender_boq_bills WHERE bill_id = ?")->execute([$billId]);
            $grandTotal = recomputeTenderBoqTotal($pdo, $tender_id);

            logActivity($pdo, $user_id, 'DELETE', "[Tender BOQ] Removed bill #$billId from tender #$tender_id");
            echo json_encode(['success' => true, 'message' => 'Bill removed.', 'grand_total' => $grandTotal]);
            break;

        case 'ADD_ITEM':
            $billId = (int)($_POST['bill_id'] ?? 0);
            $own = $pdo->prepare("SELECT bill_id FROM tender_boq_bills WHERE bill_id = ? AND tender_id = ?");
            $own->execute([$billId, $tender_id]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Bill not found for this tender']);
                exit;
            }
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tender_boq_items WHERE bill_id = ?");
            $orderStmt->execute([$billId]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO tender_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, '', NULL, 0, 0, 0, ?)");
            $stmt->execute([$billId, $nextOrder]);
            $itemId = (int)$pdo->lastInsertId();

            echo json_encode(['success' => true, 'message' => 'Item added.', 'item_id' => $itemId]);
            break;

        case 'DELETE_ITEM':
            $itemId = (int)($_POST['item_id'] ?? 0);
            $own = $pdo->prepare("
                SELECT i.item_id FROM tender_boq_items i
                JOIN tender_boq_bills b ON b.bill_id = i.bill_id
                WHERE i.item_id = ? AND b.tender_id = ?
            ");
            $own->execute([$itemId, $tender_id]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Item not found for this tender']);
                exit;
            }
            $pdo->prepare("DELETE FROM tender_boq_items WHERE item_id = ?")->execute([$itemId]);
            $grandTotal = recomputeTenderBoqTotal($pdo, $tender_id);

            echo json_encode(['success' => true, 'message' => 'Item removed.', 'grand_total' => $grandTotal]);
            break;

        case 'SAVE_BOQ':
            $items = $_POST['items'] ?? []; // [item_id => ['bill_id'=>, 'description'=>, 'unit'=>, 'qty'=>, 'rate'=>]]
            $contingencyPercent = (float)($_POST['contingency_percent'] ?? 0);
            $vatPercent = (float)($_POST['vat_percent'] ?? 18);

            // Every item must belong to a bill of THIS tender - verify the whole
            // posted set before writing anything, so a tampered request can't
            // touch another tender's BOQ line.
            $validBills = $pdo->prepare("SELECT bill_id FROM tender_boq_bills WHERE tender_id = ?");
            $validBills->execute([$tender_id]);
            $validBillIds = array_map('intval', $validBills->fetchAll(PDO::FETCH_COLUMN));

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("UPDATE tender_boq_items SET description = ?, unit = ?, qty = ?, rate = ?, amount = ? WHERE item_id = ? AND bill_id = ?");
            foreach ($items as $itemId => $row) {
                $itemId = (int)$itemId;
                $billId = (int)($row['bill_id'] ?? 0);
                if (!in_array($billId, $validBillIds, true)) {
                    continue; // silently skip anything not belonging to this tender
                }
                $description = trim((string)($row['description'] ?? ''));
                $unit = trim((string)($row['unit'] ?? '')) ?: null;
                $qty = (float)($row['qty'] ?? 0);
                $rate = (float)($row['rate'] ?? 0);
                $amount = round($qty * $rate, 2);

                $updateStmt->execute([$description, $unit, $qty, $rate, $amount, $itemId, $billId]);
            }

            // Bill titles, if posted.
            $billTitles = $_POST['bill_titles'] ?? []; // [bill_id => title]
            if (is_array($billTitles)) {
                $titleStmt = $pdo->prepare("UPDATE tender_boq_bills SET bill_title = ? WHERE bill_id = ? AND tender_id = ?");
                foreach ($billTitles as $billId => $title) {
                    $billId = (int)$billId;
                    if (!in_array($billId, $validBillIds, true)) continue;
                    $titleStmt->execute([trim((string)$title) ?: 'Untitled Bill', $billId, $tender_id]);
                }
            }

            $grandTotal = recomputeTenderBoqTotal($pdo, $tender_id, $contingencyPercent, $vatPercent);

            $pdo->commit();

            logActivity($pdo, $user_id, 'UPDATE', "[Tender BOQ] Saved BOQ for tender #$tender_id (grand total: $grandTotal)");
            echo json_encode(['success' => true, 'message' => 'BOQ saved successfully.', 'grand_total' => $grandTotal]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("api/tender_boq.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
