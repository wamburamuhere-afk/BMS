<?php
// scope-audit: skip — tender materials schedule sub-data; same entity/scope as tenders (customers, not project-scoped); deferred to Phase G-2
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('tenders') && !canCreate('tenders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit the tender materials schedule']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// SEARCH_PRODUCTS is a read-only lookup for the Select2 field — allow GET,
// everything else is a state change and must be POST + CSRF-checked.
if ($action === 'SEARCH_PRODUCTS') {
    $term = trim($_GET['term'] ?? '');
    $stmt = $pdo->prepare("
        SELECT product_id, product_name, unit, selling_price
        FROM products
        WHERE status != 'deleted' AND product_name LIKE ?
        ORDER BY product_name ASC
        LIMIT 20
    ");
    $stmt->execute(['%' . $term . '%']);
    echo json_encode(['success' => true, 'products' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
$tender_id = (int)($_POST['tender_id'] ?? 0);

if (!$tender_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid Tender ID']);
    exit;
}

$tenderCheck = $pdo->prepare("SELECT tender_id FROM tenders WHERE tender_id = ?");
$tenderCheck->execute([$tender_id]);
if (!$tenderCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Tender not found']);
    exit;
}

try {
    switch ($action) {

        case 'ADD_ITEM':
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tender_materials WHERE tender_id = ?");
            $orderStmt->execute([$tender_id]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO tender_materials (tender_id, material, unit, qty, rate, amount, sort_order) VALUES (?, '', NULL, 0, 0, 0, ?)");
            $stmt->execute([$tender_id, $nextOrder]);
            $materialId = (int)$pdo->lastInsertId();

            echo json_encode(['success' => true, 'message' => 'Item added.', 'material_id' => $materialId]);
            break;

        case 'DELETE_ITEM':
            $materialId = (int)($_POST['material_id'] ?? 0);
            $own = $pdo->prepare("SELECT material_id FROM tender_materials WHERE material_id = ? AND tender_id = ?");
            $own->execute([$materialId, $tender_id]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Item not found for this tender']);
                exit;
            }
            $pdo->prepare("DELETE FROM tender_materials WHERE material_id = ?")->execute([$materialId]);

            echo json_encode(['success' => true, 'message' => 'Item removed.']);
            break;

        case 'SAVE_MATERIALS':
            $items = $_POST['items'] ?? []; // [material_id => ['product_id','material','specification','unit','qty','rate']]

            $validItems = $pdo->prepare("SELECT material_id FROM tender_materials WHERE tender_id = ?");
            $validItems->execute([$tender_id]);
            $validIds = array_map('intval', $validItems->fetchAll(PDO::FETCH_COLUMN));

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE tender_materials
                SET product_id = ?, material = ?, specification = ?, unit = ?, qty = ?, rate = ?, amount = ?
                WHERE material_id = ? AND tender_id = ?
            ");
            foreach ($items as $materialId => $row) {
                $materialId = (int)$materialId;
                if (!in_array($materialId, $validIds, true)) {
                    continue; // not this tender's row — skip rather than trust it
                }
                $productId = !empty($row['product_id']) ? (int)$row['product_id'] : null;
                $material = trim((string)($row['material'] ?? ''));
                $specification = trim((string)($row['specification'] ?? '')) ?: null;
                $unit = trim((string)($row['unit'] ?? '')) ?: null;
                $qty = (float)($row['qty'] ?? 0);
                $rate = (float)($row['rate'] ?? 0);
                $amount = round($qty * $rate, 2);

                $updateStmt->execute([$productId, $material, $specification, $unit, $qty, $rate, $amount, $materialId, $tender_id]);
            }

            $pdo->commit();

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Materials] Saved materials schedule for tender #$tender_id");
            echo json_encode(['success' => true, 'message' => 'Materials schedule saved.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("api/tender_materials.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
