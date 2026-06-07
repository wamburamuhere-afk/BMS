<?php
// api/delete_account_category.php (with reassignment option)
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access');
    }

    // Deleting account categories is ADMIN-ONLY.
    if (!isAdmin()) {
        throw new Exception('Access Denied: only an administrator can delete account categories');
    }

    // Accept either `category_id` or the shared delete form's `delete_id`.
    $categoryId = (int)($_POST['category_id'] ?? $_POST['delete_id'] ?? 0) ?: null;
    $reassignToCategoryId = isset($_POST['reassign_to_category_id']) ? intval($_POST['reassign_to_category_id']) : null;
    $forceDelete = isset($_POST['force_delete']) ? filter_var($_POST['force_delete'], FILTER_VALIDATE_BOOLEAN) : false;

    if (!$categoryId) {
        throw new Exception('Category ID is required');
    }

    $pdo->beginTransaction();

    // Check if category exists. Use the category's OWN category_type column
    // (a plain SELECT) — an INNER JOIN to account_types would wrongly report
    // "not found" for categories whose account_type_id is NULL.
    $checkCategoryQuery = "SELECT category_id, category_name, category_type FROM account_categories WHERE category_id = ?";
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

    $reassignedAccounts = 0;
    $deletedAccounts    = 0;
    $unlinkedAccounts   = 0;
    $movedSubCategories = 0;

    // Sub-categories: move to top level so the deletion isn't blocked
    // (non-destructive — they simply lose their parent).
    if ($hasSubCategories) {
        $stmt = $pdo->prepare("UPDATE account_categories SET parent_category_id = NULL WHERE parent_category_id = ?");
        $stmt->execute([$categoryId]);
        $movedSubCategories = $stmt->rowCount();
    }

    // Linked accounts:
    if ($hasAccounts) {
        if ($reassignToCategoryId) {
            // ── Optional reassignment path (kept for callers that pass a target) ──
            $stmt = $pdo->prepare("SELECT category_id, category_type FROM account_categories WHERE category_id = ?");
            $stmt->execute([$reassignToCategoryId]);
            $targetCategory = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetCategory) {
                throw new Exception('Target category for reassignment not found');
            }
            if ($category['category_type'] !== $targetCategory['category_type']) {
                throw new Exception('Cannot reassign accounts to a category with different type');
            }
            $stmt = $pdo->prepare("UPDATE accounts SET category_id = ? WHERE category_id = ?");
            $stmt->execute([$reassignToCategoryId, $categoryId]);
            $reassignedAccounts = $stmt->rowCount();
        } else {
            // ── SAFE CASCADE (admin) ──────────────────────────────────────────
            // Delete the EMPTY linked accounts (no posted transactions, no sub-
            // accounts, not a system account, and NOT wired into system_settings /
            // journal_mappings). Any account that is in use or wired is kept but
            // UNLINKED (category → NULL) so the ledger, the financial statements,
            // and the wired features all stay correct.
            $wired = [];
            foreach ($pdo->query("SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key REGEXP '_account_id$' AND setting_value REGEXP '^[0-9]+$'")->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $wired[(int)$v] = true;
            }
            if ($pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch()) {
                foreach ($pdo->query("SELECT debit_account_id FROM journal_mappings WHERE debit_account_id IS NOT NULL UNION SELECT credit_account_id FROM journal_mappings WHERE credit_account_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    $wired[(int)$v] = true;
                }
            }
            $linked = $pdo->prepare("SELECT account_id, is_system FROM accounts WHERE category_id = ?");
            $linked->execute([$categoryId]);
            $txnStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = ?");
            $kidStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE parent_account_id = ? AND account_id <> parent_account_id");
            $delStmt = $pdo->prepare("DELETE FROM accounts WHERE account_id = ?");
            $unlStmt = $pdo->prepare("UPDATE accounts SET category_id = NULL WHERE account_id = ?");
            foreach ($linked->fetchAll(PDO::FETCH_ASSOC) as $acc) {
                $aid = (int)$acc['account_id'];
                $txnStmt->execute([$aid]); $hasTxn  = (int)$txnStmt->fetchColumn() > 0;
                $kidStmt->execute([$aid]); $hasKids = (int)$kidStmt->fetchColumn() > 0;
                $isSys = (int)$acc['is_system'] === 1;
                $isWired = isset($wired[$aid]);
                if ($hasTxn || $hasKids || $isSys || $isWired) {
                    $unlStmt->execute([$aid]);
                    $unlinkedAccounts++;
                } else {
                    $delStmt->execute([$aid]);
                    $deletedAccounts++;
                }
            }
        }
    }

    // Delete the category itself.
    $stmt = $pdo->prepare("DELETE FROM account_categories WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    if ($stmt->rowCount() === 0) {
        throw new Exception('Failed to delete category');
    }

    $pdo->commit();

    // Phase 3a — chart-of-accounts changes are high-sensitivity.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Account Category",
        "Category: '$categoryName' (ID: $categoryId) — deleted $deletedAccounts account(s), unlinked $unlinkedAccounts, reassigned $reassignedAccounts, moved $movedSubCategories sub-categor(ies)");

    // Build success message
    $message = "Category '{$categoryName}' deleted.";
    if ($deletedAccounts > 0)    $message .= " {$deletedAccounts} empty account(s) removed.";
    if ($unlinkedAccounts > 0)   $message .= " {$unlinkedAccounts} account(s) with transactions were kept and unlinked (to protect your reports).";
    if ($reassignedAccounts > 0) $message .= " {$reassignedAccounts} account(s) reassigned.";
    if ($movedSubCategories > 0) $message .= " {$movedSubCategories} sub-categor(ies) moved to top level.";

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
