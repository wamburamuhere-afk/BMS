<?php
// API: Manage performance indicator categories + indicators (Tier 3, Phase 3.2).
// CRUD with soft delete (§12). An indicator referenced by any appraisal item
// can only be soft-deleted (deactivated) — history keeps rendering via its
// snapshot; a hard remove that orphans an appraisal item is refused.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage indicators']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        // ── Categories ───────────────────────────────────────────────────────
        case 'add_category': {
            $name = trim($_POST['category_name'] ?? '');
            $sort = intval($_POST['sort_order'] ?? 0);
            if ($name === '') throw new Exception('Category name is required');
            $chk = $pdo->prepare("SELECT category_id, status FROM performance_indicator_categories WHERE category_name = ?");
            $chk->execute([$name]);
            $ex = $chk->fetch(PDO::FETCH_ASSOC);
            if ($ex && $ex['status'] !== 'deleted') throw new Exception('That category already exists');
            if ($ex) {
                $pdo->prepare("UPDATE performance_indicator_categories SET status='active', sort_order=? WHERE category_id=?")
                    ->execute([$sort, (int)$ex['category_id']]);
                $id = (int)$ex['category_id'];
            } else {
                $pdo->prepare("INSERT INTO performance_indicator_categories (category_name, sort_order, created_by) VALUES (?, ?, ?)")
                    ->execute([$name, $sort, $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            logActivity($pdo, $_SESSION['user_id'], 'Add indicator category', "added performance indicator category '$name'");
            echo json_encode(['success' => true, 'message' => 'Category saved', 'category_id' => $id]);
            break;
        }
        case 'rename_category': {
            $id = intval($_POST['category_id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            if (!$id || $name === '') throw new Exception('Category id and new name are required');
            $pdo->prepare("UPDATE performance_indicator_categories SET category_name=? WHERE category_id=? AND status!='deleted'")
                ->execute([$name, $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Rename indicator category', "renamed category #$id to '$name'");
            echo json_encode(['success' => true, 'message' => 'Category renamed']);
            break;
        }
        case 'delete_category': {
            $id = intval($_POST['category_id'] ?? 0);
            if (!$id) throw new Exception('Category id is required');
            // Block if it still has active indicators
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM performance_indicators WHERE category_id = $id AND status = 'active'")->fetchColumn();
            if ($cnt > 0) throw new Exception("Remove or move its $cnt active indicator(s) first");
            $pdo->prepare("UPDATE performance_indicator_categories SET status='deleted' WHERE category_id=?")->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], 'Delete indicator category', "deleted category #$id");
            echo json_encode(['success' => true, 'message' => 'Category deleted']);
            break;
        }

        // ── Indicators ───────────────────────────────────────────────────────
        case 'add_indicator': {
            $cat = intval($_POST['category_id'] ?? 0);
            $name = trim($_POST['indicator_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (!$cat) throw new Exception('Category is required');
            if ($name === '') throw new Exception('Indicator name is required');
            $chkCat = $pdo->prepare("SELECT category_id FROM performance_indicator_categories WHERE category_id=? AND status='active'");
            $chkCat->execute([$cat]);
            if (!$chkCat->fetch()) throw new Exception('Category does not exist or is inactive');
            $pdo->prepare("INSERT INTO performance_indicators (category_id, indicator_name, description, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$cat, $name, ($desc !== '' ? $desc : null), $_SESSION['user_id']]);
            $id = (int)$pdo->lastInsertId();
            logActivity($pdo, $_SESSION['user_id'], 'Add indicator', "added indicator '$name'");
            echo json_encode(['success' => true, 'message' => 'Indicator saved', 'indicator_id' => $id]);
            break;
        }
        case 'update_indicator': {
            $id = intval($_POST['indicator_id'] ?? 0);
            $cat = intval($_POST['category_id'] ?? 0);
            $name = trim($_POST['indicator_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (!$id || !$cat || $name === '') throw new Exception('Indicator id, category and name are required');
            $pdo->prepare("UPDATE performance_indicators SET category_id=?, indicator_name=?, description=? WHERE indicator_id=? AND status!='deleted'")
                ->execute([$cat, $name, ($desc !== '' ? $desc : null), $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Update indicator', "updated indicator #$id");
            echo json_encode(['success' => true, 'message' => 'Indicator updated']);
            break;
        }
        case 'delete_indicator': {
            $id = intval($_POST['indicator_id'] ?? 0);
            if (!$id) throw new Exception('Indicator id is required');
            // Referenced by any appraisal item → soft delete only (never orphan history)
            $pdo->prepare("UPDATE performance_indicators SET status='deleted' WHERE indicator_id=?")->execute([$id]);
            // Also drop any designation targets that referenced it (targets are not history)
            $pdo->prepare("DELETE FROM designation_indicator_targets WHERE indicator_id=?")->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], 'Delete indicator', "soft-deleted indicator #$id");
            echo json_encode(['success' => true, 'message' => 'Indicator removed']);
            break;
        }

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
