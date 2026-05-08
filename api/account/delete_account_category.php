<?php
// api/delete_account_category.php (with reassignment option)
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $reassignToCategoryId = isset($_POST['reassign_to_category_id']) ? intval($_POST['reassign_to_category_id']) : null;
    $forceDelete = isset($_POST['force_delete']) ? filter_var($_POST['force_delete'], FILTER_VALIDATE_BOOLEAN) : false;

    if (!$categoryId) {
        throw new Exception('Category ID is required');
    }

    $pdo->beginTransaction();

    // Check if category exists
    $checkCategoryQuery = "SELECT c.category_id, c.category_name, at.type_name as category_type FROM account_categories c JOIN account_types at ON c.account_type_id = at.type_id WHERE c.category_id = ?";
    $stmt = $pdo->prepare($checkCategoryQuery);
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        throw new Exception('Category not found');
    }

    $categoryName = $category['category_name'];

    // Check for accounts in this category
    $accountsCheckQuery = "SELECT COUNT(*) as account_count FROM accounts WHERE category_id = ?";
    $stmt = $pdo->prepare($accountsCheckQuery);
    $stmt->execute([$categoryId]);
    $accountsResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $hasAccounts = $accountsResult['account_count'] > 0;

    // Check for sub-categories
    $subCategoriesCheckQuery = "SELECT COUNT(*) as subcategory_count FROM account_categories WHERE parent_category_id = ?";
    $stmt = $pdo->prepare($subCategoriesCheckQuery);
    $stmt->execute([$categoryId]);
    $subCategoriesResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $hasSubCategories = $subCategoriesResult['subcategory_count'] > 0;

    // Handle reassignment if requested
    if ($hasAccounts && $reassignToCategoryId) {
        // Validate target category
        $targetCategoryQuery = "SELECT c.category_id, at.type_name as category_type FROM account_categories c JOIN account_types at ON c.account_type_id = at.type_id WHERE c.category_id = ?";
        $stmt = $pdo->prepare($targetCategoryQuery);
        $stmt->execute([$reassignToCategoryId]);
        $targetCategory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$targetCategory) {
            throw new Exception('Target category for reassignment not found');
        }

        // Verify category type compatibility
        if ($category['category_type'] !== $targetCategory['category_type']) {
            throw new Exception('Cannot reassign accounts to a category with different type');
        }

        // Reassign accounts
        $reassignQuery = "UPDATE accounts SET category_id = ? WHERE category_id = ?";
        $stmt = $pdo->prepare($reassignQuery);
        $stmt->execute([$reassignToCategoryId, $categoryId]);
        
        $reassignedAccounts = $stmt->rowCount();
        $hasAccounts = false; // Accounts have been reassigned
    }

    // Handle sub-categories if force delete is requested
    if ($hasSubCategories && $forceDelete) {
        // Option 1: Move sub-categories to top level (set parent to NULL)
        $updateSubCategoriesQuery = "UPDATE account_categories SET parent_category_id = NULL WHERE parent_category_id = ?";
        $stmt = $pdo->prepare($updateSubCategoriesQuery);
        $stmt->execute([$categoryId]);
        
        $affectedSubCategories = $stmt->rowCount();
        $hasSubCategories = false; // Sub-categories have been handled
    }

    // Final safety checks before deletion
    if ($hasAccounts) {
        throw new Exception(
            "Category '{$categoryName}' has {$accountsResult['account_count']} account(s). " .
            "Please reassign these accounts to another category before deletion."
        );
    }

    if ($hasSubCategories) {
        throw new Exception(
            "Category '{$categoryName}' has {$subCategoriesResult['subcategory_count']} sub-category(s). " .
            "Please delete or reassign these sub-categories first."
        );
    }

    // Delete the category
    $deleteQuery = "DELETE FROM account_categories WHERE category_id = ?";
    $stmt = $pdo->prepare($deleteQuery);
    $success = $stmt->execute([$categoryId]);

    if (!$success || $stmt->rowCount() === 0) {
        throw new Exception('Failed to delete category');
    }

    $pdo->commit();

    // Build success message
    $message = "Category '{$categoryName}' has been deleted successfully";
    if (isset($reassignedAccounts) && $reassignedAccounts > 0) {
        $message .= ". {$reassignedAccounts} account(s) were reassigned.";
    }
    if (isset($affectedSubCategories) && $affectedSubCategories > 0) {
        $message .= " {$affectedSubCategories} sub-category(s) were moved to top level.";
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'deleted_category_id' => $categoryId
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
