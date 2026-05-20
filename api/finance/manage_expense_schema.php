<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access.');
    }

    $action = $_POST['action'] ?? '';
    $user_id = getCurrentUserId();

    switch ($action) {
        case 'add_type':
            $name         = trim($_POST['name'] ?? '');
            $show_project = isset($_POST['show_project']) ? (int)(bool)$_POST['show_project'] : 1;
            if (empty($name)) throw new Exception('Type name is required.');

            $stmt = $pdo->prepare("INSERT INTO expense_types (name, show_project) VALUES (?, ?)");
            $stmt->execute([$name, $show_project]);
            echo json_encode(['success' => true, 'message' => 'New Expense Type created.', 'id' => $pdo->lastInsertId()]);
            break;

        case 'add_category':
            $type_id = intval($_POST['type_id'] ?? 0);
            $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
            $name = trim($_POST['name'] ?? '');
            if (!$type_id || empty($name)) throw new Exception('Type and Category name are required.');

            $hasParentId = $pdo->query("SHOW COLUMNS FROM expense_categories LIKE 'parent_id'")->rowCount() > 0;
            if ($hasParentId) {
                $stmt = $pdo->prepare("INSERT INTO expense_categories (type_id, parent_id, name) VALUES (?, ?, ?)");
                $stmt->execute([$type_id, $parent_id, $name]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO expense_categories (type_id, name) VALUES (?, ?)");
                $stmt->execute([$type_id, $name]);
            }
            echo json_encode(['success' => true, 'message' => 'Category added successfully.', 'id' => $pdo->lastInsertId()]);
            break;

        case 'edit_category':
            $cat_id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (!$cat_id || empty($name)) throw new Exception('ID and new Name are required.');

            $stmt = $pdo->prepare("UPDATE expense_categories SET name = ? WHERE id = ?");
            $stmt->execute([$name, $cat_id]);
            echo json_encode(['success' => true, 'message' => 'Category updated.']);
            break;

        case 'delete_category':
            $cat_id = intval($_POST['id'] ?? 0);
            if (!$cat_id) throw new Exception('Missing category ID.');

            $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE id = ?");
            $stmt->execute([$cat_id]);
            echo json_encode(['success' => true, 'message' => 'Category deleted.']);
            break;

        case 'edit_type':
            $type_id = intval($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            if (!$type_id || empty($name)) throw new Exception('ID and new Name are required.');

            $stmt = $pdo->prepare("UPDATE expense_types SET name = ? WHERE id = ?");
            $stmt->execute([$name, $type_id]);
            echo json_encode(['success' => true, 'message' => 'Expense Type updated.']);
            break;

        case 'toggle_show_project':
            $type_id = intval($_POST['id'] ?? 0);
            if (!$type_id) throw new Exception('Missing type ID.');

            $stmt = $pdo->prepare("UPDATE expense_types SET show_project = 1 - show_project WHERE id = ?");
            $stmt->execute([$type_id]);
            $newVal = $pdo->query("SELECT show_project FROM expense_types WHERE id = $type_id")->fetchColumn();
            echo json_encode(['success' => true, 'show_project' => (int)$newVal]);
            break;

        case 'delete_type':
            $type_id = intval($_POST['id'] ?? 0);
            if (!$type_id) throw new Exception('Missing type ID.');

            // Check if in use
            $count = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE type_id = ?");
            $count->execute([$type_id]);
            if ($count->fetchColumn() > 0) {
                throw new Exception('Cannot delete this type as it is already linked to expense records.');
            }

            $pdo->beginTransaction();
            try {
                // Delete categories first
                $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE type_id = ?");
                $stmt->execute([$type_id]);

                $stmt = $pdo->prepare("DELETE FROM expense_types WHERE id = ?");
                $stmt->execute([$type_id]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Expense Type and its categories deleted.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception('Invalid action requested.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
