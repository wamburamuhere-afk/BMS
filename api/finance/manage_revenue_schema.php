<?php
// scope-audit: skip — schema management for revenue_categories (no project data)
/**
 * api/finance/manage_revenue_schema.php
 * CRUD for the revenue category tree. Actions: add_category, edit_category,
 * delete_category (cascades to descendants). Gated by edit on revenue/categories.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized access.');
    if (!canEdit('revenue') && !canEdit('revenue_categories')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to manage revenue categories');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') csrf_check();

    $action  = $_POST['action'] ?? '';
    $user_id = getCurrentUserId();

    switch ($action) {
        case 'add_category':
            $name      = trim($_POST['name'] ?? '');
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            if ($name === '') throw new Exception('Category name is required.');
            $stmt = $pdo->prepare("INSERT INTO revenue_categories (name, parent_id, status) VALUES (?, ?, 'active')");
            $stmt->execute([$name, $parent_id]);
            $newId = (int)$pdo->lastInsertId();
            logActivity($pdo, $user_id, "Created Revenue Category", "Name: $name (ID: $newId)");
            echo json_encode(['success' => true, 'message' => 'Category added.', 'id' => $newId]);
            break;

        case 'edit_category':
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (!$id || $name === '') throw new Exception('ID and new name are required.');
            $pdo->prepare("UPDATE revenue_categories SET name = ? WHERE id = ?")->execute([$name, $id]);
            logActivity($pdo, $user_id, "Updated Revenue Category", "ID: $id, New Name: $name");
            echo json_encode(['success' => true, 'message' => 'Category updated.']);
            break;

        case 'delete_category':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Missing category ID.');
            // Block delete if a revenue record uses this category.
            $inUse = $pdo->prepare("SELECT COUNT(*) FROM revenues WHERE category_id = ?");
            $inUse->execute([$id]);
            if ((int)$inUse->fetchColumn() > 0) {
                throw new Exception('Cannot delete: this category is linked to revenue records.');
            }
            // Cascade delete descendants (depth-first).
            $deleteTree = function (int $cid) use (&$deleteTree, $pdo) {
                $kids = $pdo->prepare("SELECT id FROM revenue_categories WHERE parent_id = ?");
                $kids->execute([$cid]);
                foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $kid) $deleteTree((int)$kid);
                $pdo->prepare("DELETE FROM revenue_categories WHERE id = ?")->execute([$cid]);
            };
            $deleteTree($id);
            logActivity($pdo, $user_id, "Deleted Revenue Category", "ID: $id (cascade)");
            echo json_encode(['success' => true, 'message' => 'Category deleted.']);
            break;

        default:
            throw new Exception('Invalid action requested.');
    }
} catch (Exception $e) {
    if ((http_response_code() ?: 200) < 400) http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
