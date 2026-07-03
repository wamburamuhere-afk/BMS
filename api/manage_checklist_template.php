<?php
// API: Manage checklist templates + items (Tier 4, Phase 4.4).
// add/update/delete template, set_default (one default per type, enforced),
// add/update/delete item. canEdit('hr_checklists').
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('hr_checklists')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'add_template': {
            $name = trim($_POST['template_name'] ?? '');
            $type = ($_POST['template_type'] ?? '') === 'offboarding' ? 'offboarding' : 'onboarding';
            if ($name === '') throw new Exception('Template name is required');
            $chk = $pdo->prepare("SELECT template_id, status FROM checklist_templates WHERE template_name=? AND template_type=?");
            $chk->execute([$name, $type]);
            $ex = $chk->fetch(PDO::FETCH_ASSOC);
            if ($ex && $ex['status'] !== 'deleted') throw new Exception('A template with that name already exists for this type');
            if ($ex) {
                $pdo->prepare("UPDATE checklist_templates SET status='active' WHERE template_id=?")->execute([(int)$ex['template_id']]);
                $id = (int)$ex['template_id'];
            } else {
                $pdo->prepare("INSERT INTO checklist_templates (template_name, template_type, is_default, status, created_by) VALUES (?, ?, 0, 'active', ?)")
                    ->execute([$name, $type, $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            logActivity($pdo, $_SESSION['user_id'], 'Add checklist template', "template '$name' ($type)");
            echo json_encode(['success' => true, 'message' => 'Template saved', 'template_id' => $id]);
            break;
        }
        case 'rename_template': {
            $id = intval($_POST['template_id'] ?? 0);
            $name = trim($_POST['template_name'] ?? '');
            if (!$id || $name === '') throw new Exception('Template id and name are required');
            $pdo->prepare("UPDATE checklist_templates SET template_name=? WHERE template_id=? AND status!='deleted'")->execute([$name, $id]);
            echo json_encode(['success' => true, 'message' => 'Template renamed']);
            break;
        }
        case 'set_default': {
            $id = intval($_POST['template_id'] ?? 0);
            if (!$id) throw new Exception('Template id is required');
            $t = $pdo->prepare("SELECT template_type FROM checklist_templates WHERE template_id=? AND status='active'");
            $t->execute([$id]);
            $type = $t->fetchColumn();
            if ($type === false) throw new Exception('Template not found or inactive');
            $pdo->beginTransaction();
            // only one default per type (enforced here)
            $pdo->prepare("UPDATE checklist_templates SET is_default=0 WHERE template_type=?")->execute([$type]);
            $pdo->prepare("UPDATE checklist_templates SET is_default=1 WHERE template_id=?")->execute([$id]);
            $pdo->commit();
            logActivity($pdo, $_SESSION['user_id'], 'Set default checklist', "template #$id is now the default $type");
            echo json_encode(['success' => true, 'message' => "Set as the default $type template"]);
            break;
        }
        case 'delete_template': {
            $id = intval($_POST['template_id'] ?? 0);
            if (!$id) throw new Exception('Template id is required');
            $pdo->prepare("UPDATE checklist_templates SET status='deleted', is_default=0 WHERE template_id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Template deleted']);
            break;
        }
        case 'add_item': {
            $tid = intval($_POST['template_id'] ?? 0);
            $text = trim($_POST['item_text'] ?? '');
            $sort = intval($_POST['sort_order'] ?? 0);
            if (!$tid || $text === '') throw new Exception('Template and item text are required');
            $pdo->prepare("INSERT INTO checklist_template_items (template_id, item_text, sort_order) VALUES (?, ?, ?)")->execute([$tid, $text, $sort]);
            echo json_encode(['success' => true, 'message' => 'Item added', 'item_id' => (int)$pdo->lastInsertId()]);
            break;
        }
        case 'update_item': {
            $iid = intval($_POST['item_id'] ?? 0);
            $text = trim($_POST['item_text'] ?? '');
            $sort = intval($_POST['sort_order'] ?? 0);
            if (!$iid || $text === '') throw new Exception('Item id and text are required');
            $pdo->prepare("UPDATE checklist_template_items SET item_text=?, sort_order=? WHERE item_id=?")->execute([$text, $sort, $iid]);
            echo json_encode(['success' => true, 'message' => 'Item updated']);
            break;
        }
        case 'delete_item': {
            $iid = intval($_POST['item_id'] ?? 0);
            if (!$iid) throw new Exception('Item id is required');
            $pdo->prepare("DELETE FROM checklist_template_items WHERE item_id=?")->execute([$iid]);
            echo json_encode(['success' => true, 'message' => 'Item removed']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
