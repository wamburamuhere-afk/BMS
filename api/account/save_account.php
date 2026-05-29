<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    // Phase 0.5 hardening — auth + method gates
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized access');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $account_id = $_POST['account_id'] ?? '';
    $account_code = $_POST['account_code'] ?? '';
    $account_name = $_POST['account_name'] ?? '';
    $account_type_name = $_POST['account_type'] ?? '';
    $category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
    $description = $_POST['description'] ?? '';
    $opening_balance = !empty($_POST['opening_balance']) ? $_POST['opening_balance'] : 0;
    $status = $_POST['status'] ?? 'active';

    // Handle Sub-Account
    $is_sub_account = isset($_POST['is_sub_account']);
    $parent_account_id = $is_sub_account && !empty($_POST['parent_account_id']) ? $_POST['parent_account_id'] : null;

    if (empty($account_code) || empty($account_name) || empty($account_type_name)) {
        throw new Exception('Required fields missing');
    }

    // Resolve Account Type ID
    $stmt = $pdo->prepare("SELECT type_id FROM account_types WHERE type_name = ?");
    $stmt->execute([$account_type_name]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$type) {
        // Fallback: Try to map display name or assume type_name matches
        // Or if types are static, maybe handle that. For now assume type_name matches DB.
        throw new Exception("Invalid account type: " . $account_type_name);
    }
    $account_type_id = $type['type_id'];

    if (!empty($account_id)) {
        if (!canEdit('chart_of_accounts') && !canEdit('bank_accounts')) {
            throw new Exception('You do not have permission to edit accounts');
        }

        // Fetch original account data to calculate balance delta + check current type
        $origStmt = $pdo->prepare("SELECT opening_balance, current_balance, account_type_id FROM accounts WHERE account_id = ?");
        $origStmt->execute([$account_id]);
        $orig = $origStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orig) {
            throw new Exception('Account to update not found');
        }

        // Phase 0.5 safety guard — don't allow changing account_type_id if the
        // account already has journal entries. Changing the type would silently
        // flip every historical entry between BS and IS sections; that would
        // be a silent data-integrity breach. Admins must either:
        //   (a) leave the type alone, or
        //   (b) archive this account, create a new one with the right type,
        //       and post contra-entries to migrate balances.
        if ((int)$orig['account_type_id'] !== (int)$account_type_id) {
            $useStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = ?");
            $useStmt->execute([$account_id]);
            $line_count = (int)$useStmt->fetchColumn();
            if ($line_count > 0) {
                throw new Exception(
                    "Cannot change account type — this account already has $line_count "
                    . "journal entry line(s). Changing its type would silently re-classify "
                    . "every historical posting. To re-classify, archive this account and "
                    . "create a new one with the correct type."
                );
            }
        }

        $balance_delta = $opening_balance - $orig['opening_balance'];
        $new_current_balance = $orig['current_balance'] + $balance_delta;

        // Update
        $stmt = $pdo->prepare("
            UPDATE accounts SET 
                account_code = ?, 
                account_name = ?, 
                account_type_id = ?, 
                account_type = ?,
                category_id = ?, 
                description = ?, 
                opening_balance = ?,
                current_balance = ?,
                parent_account_id = ?,
                status = ?,
                updated_at = NOW()
            WHERE account_id = ?
        ");
        $stmt->execute([
            $account_code, 
            $account_name, 
            $account_type_id, 
            $account_type_name, 
            $category_id, 
            $description, 
            $opening_balance,
            $new_current_balance,
            $parent_account_id,
            $status,
            $account_id
        ]);
        
        logAudit($pdo, $_SESSION['user_id'] ?? 0, 'Updated Account', [
            'activity_type' => 'Account Update',
            'entity_type' => 'accounts',
            'entity_id' => $account_id,
            'description' => "Updated account: $account_name ($account_code) — type: $account_type_name"
        ]);

        $message = 'Account updated successfully';
    } else {
        if (!canCreate('chart_of_accounts') && !canCreate('bank_accounts')) {
            throw new Exception('You do not have permission to create accounts');
        }
        
        // Insert
        // Check for duplicate code
        $check = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
        $check->execute([$account_code]);
        if ($check->fetchColumn() > 0) {
            throw new Exception("Account code '$account_code' already exists.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO accounts (
                account_code, 
                account_name, 
                account_type_id, 
                account_type,
                category_id, 
                description, 
                opening_balance, 
                current_balance,
                parent_account_id, 
                status,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $account_code, 
            $account_name, 
            $account_type_id, 
            $account_type_name, // Enum value
            $category_id, 
            $description, 
            $opening_balance, 
            $opening_balance, 
            $parent_account_id, 
            $status
        ]);
        $new_id = $pdo->lastInsertId();
        
        logAudit($pdo, $_SESSION['user_id'] ?? 0, 'Created Account', [
            'activity_type' => 'Account Creation',
            'entity_type' => 'accounts',
            'entity_id' => $new_id,
            'description' => "Created account: $account_name ($account_code) — type: $account_type_name"
        ]);

        $message = 'Account created successfully';
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
