<?php
/**
 * api/assets/delete_asset_category.php
 *
 * Soft-delete an asset category (§12 — never hard delete). Admin / asset-delete
 * permission only.
 *
 * A category that is still assigned to one or more (non-deleted) assets cannot
 * be deleted — the request is rejected with the count so the user can reassign
 * those assets first. This also protects the assets.category_id foreign key.
 *
 * POST fields:
 *   category_id — required, the category to delete
 *
 * Response: { success: bool, message: string }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you do not have permission to delete asset categories']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;
if ($category_id < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid category id']);
    exit;
}

try {
    global $pdo;

    // Must exist and not already deleted.
    $stmt = $pdo->prepare("SELECT category_name, status FROM asset_categories WHERE category_id = ?");
    $stmt->execute([$category_id]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cat || $cat['status'] === 'deleted') {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
        exit;
    }

    // Block deletion while assets still reference this category.
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id = ? AND status != 'deleted'");
    $inUse->execute([$category_id]);
    $count = (int)$inUse->fetchColumn();
    if ($count > 0) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot delete — {$count} asset" . ($count === 1 ? ' is' : 's are') .
                         " still assigned to this category. Reassign them first.",
        ]);
        exit;
    }

    $pdo->prepare("UPDATE asset_categories SET status = 'deleted' WHERE category_id = ?")
        ->execute([$category_id]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Delete asset category',
        "deleted asset category \"{$cat['category_name']}\" with id $category_id");

    echo json_encode(['success' => true, 'message' => 'Category deleted.']);
} catch (Throwable $e) {
    error_log('delete_asset_category error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
