<?php
// API: Tick / untick a checklist item (Tier 4, Phase 4.4).
// Stamps done_by/done_at + optional note; logs the change.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('hr_checklists')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $item_id = intval($_POST['item_id'] ?? 0);
    $done = (int)($_POST['is_done'] ?? 1) ? 1 : 0;
    $note = trim($_POST['notes'] ?? '');
    if (!$item_id) throw new Exception('Item id is required');

    // scope: follow the item → its checklist → the employee
    $row = $pdo->prepare("SELECT eci.checklist_id, ec.employee_id, eci.item_text
                          FROM employee_checklist_items eci
                          JOIN employee_checklists ec ON ec.checklist_id = eci.checklist_id
                          WHERE eci.item_id = ?");
    $row->execute([$item_id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Item not found');
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee((int)$r['employee_id']);

    if ($done) {
        $pdo->prepare("UPDATE employee_checklist_items SET is_done=1, done_by=?, done_at=NOW(), notes=? WHERE item_id=?")
            ->execute([$_SESSION['user_id'], ($note !== '' ? $note : null), $item_id]);
    } else {
        $pdo->prepare("UPDATE employee_checklist_items SET is_done=0, done_by=NULL, done_at=NULL WHERE item_id=?")->execute([$item_id]);
    }
    logActivity($pdo, $_SESSION['user_id'], 'Tick checklist item', ($done ? 'completed' : 'reopened') . " '{$r['item_text']}'" . ($note !== '' ? ": $note" : ''));
    echo json_encode(['success' => true, 'message' => $done ? 'Item completed' : 'Item reopened']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
