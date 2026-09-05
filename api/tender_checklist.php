<?php
// scope-audit: skip — tender checklist sub-data; same entity/scope as tenders (customers, not project-scoped); deferred to Phase G-2
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('tenders') && !canCreate('tenders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit the tender checklist']);
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

$tenderCheck = $pdo->prepare("SELECT tender_id FROM tenders WHERE tender_id = ?");
$tenderCheck->execute([$tender_id]);
if (!$tenderCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Tender not found']);
    exit;
}

function tenderChecklistCounts(PDO $pdo, int $tenderId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(is_ready) AS ready FROM tender_checklist_items WHERE tender_id = ?");
    $stmt->execute([$tenderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ['total' => (int)($row['total'] ?? 0), 'ready' => (int)($row['ready'] ?? 0)];
}

try {
    switch ($action) {

        case 'TOGGLE_ITEM':
            $itemId = (int)($_POST['item_id'] ?? 0);
            $isReady = !empty($_POST['is_ready']) ? 1 : 0;
            $own = $pdo->prepare("SELECT item_id FROM tender_checklist_items WHERE item_id = ? AND tender_id = ?");
            $own->execute([$itemId, $tender_id]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Checklist item not found for this tender']);
                exit;
            }
            $pdo->prepare("UPDATE tender_checklist_items SET is_ready = ? WHERE item_id = ?")->execute([$isReady, $itemId]);

            echo json_encode(['success' => true, 'counts' => tenderChecklistCounts($pdo, $tender_id)]);
            break;

        case 'ADD_ITEM':
            $text = trim($_POST['item_text'] ?? '');
            if ($text === '') {
                echo json_encode(['success' => false, 'message' => 'Item text is required']);
                exit;
            }
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tender_checklist_items WHERE tender_id = ?");
            $orderStmt->execute([$tender_id]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $pdo->prepare("INSERT INTO tender_checklist_items (tender_id, item_text, is_ready, is_custom, sort_order) VALUES (?, ?, 0, 1, ?)")
                ->execute([$tender_id, $text, $nextOrder]);

            logActivity($pdo, $user_id, 'CREATE', "[Tender Checklist] Added custom item '$text' to tender #$tender_id");
            echo json_encode(['success' => true, 'message' => 'Item added.', 'counts' => tenderChecklistCounts($pdo, $tender_id)]);
            break;

        case 'DELETE_ITEM':
            $itemId = (int)($_POST['item_id'] ?? 0);
            // Only a custom (is_custom = 1) row may ever be deleted — the 19
            // standard items can be unticked, never removed, so the "X / N
            // ready" counter always measures against the real standard.
            $own = $pdo->prepare("SELECT item_id FROM tender_checklist_items WHERE item_id = ? AND tender_id = ? AND is_custom = 1");
            $own->execute([$itemId, $tender_id]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Only custom checklist items can be removed']);
                exit;
            }
            $pdo->prepare("DELETE FROM tender_checklist_items WHERE item_id = ?")->execute([$itemId]);

            echo json_encode(['success' => true, 'message' => 'Item removed.', 'counts' => tenderChecklistCounts($pdo, $tender_id)]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (PDOException $e) {
    error_log("api/tender_checklist.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
