<?php
/**
 * 2026_06_12_restore_cheque_account.php
 * -------------------------------------
 * Restores the standard "Cheque Account" (1-1110) under Cash On Hand (1-1100) if
 * it is missing. This is a standard seed account (Asset / Bank, Current Asset,
 * cash cash-flow) that several reports and pickers expect; it had been deleted on
 * some environments.
 *
 * Idempotent + criteria-based: only inserts when no account with code 1-1110
 * exists, and resolves the parent and Bank sub-type by lookup (no hard-coded ids).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: restore standard Cheque Account (1-1110)...\n";

try {
    // Already present? (active or otherwise) → nothing to do.
    $exists = $pdo->query("SELECT account_id FROM accounts WHERE account_code = '1-1110' LIMIT 1")->fetchColumn();
    if ($exists) {
        echo "  ~ 1-1110 already exists (id $exists) — skipping.\n\nMigration complete.\n";
        exit(0);
    }

    // Parent: Cash On Hand (1-1100); fall back to Current Assets (1-1000).
    $parentId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code = '1-1100' LIMIT 1")->fetchColumn() ?: 0);
    if (!$parentId) {
        $parentId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code = '1-1000' LIMIT 1")->fetchColumn() ?: 0);
    }
    if (!$parentId) {
        echo "  ! Cash On Hand / Current Assets not found — cannot place 1-1110. Skipping.\n\nMigration complete.\n";
        exit(0);
    }
    $parentLevel = (int)($pdo->query("SELECT level FROM accounts WHERE account_id = $parentId")->fetchColumn() ?: 3);

    // Asset type + Bank sub-type (criteria-based).
    $assetTypeId = (int)($pdo->query("SELECT type_id FROM account_types WHERE category = 'asset' LIMIT 1")->fetchColumn() ?: 0);
    $bankSubId   = (int)($pdo->query("SELECT st.sub_type_id FROM account_sub_types st
                                        JOIN account_types at ON st.type_id = at.type_id
                                       WHERE st.code = 'bank' AND at.category = 'asset' LIMIT 1")->fetchColumn() ?: 0);

    // Build a column-aware INSERT (only set columns that exist on this DB).
    $cols = $pdo->query("SHOW COLUMNS FROM accounts")->fetchAll(PDO::FETCH_COLUMN);
    $has  = fn($c) => in_array($c, $cols, true);

    $fields = ['account_code', 'account_name', 'account_type_id', 'account_type',
               'parent_account_id', 'level', 'normal_balance', 'status'];
    $values = ['1-1110', 'Cheque Account', $assetTypeId, 'asset',
               $parentId, $parentLevel + 1, 'debit', 'active'];

    if ($has('sub_type_id') && $bankSubId)     { $fields[] = 'sub_type_id';        $values[] = $bankSubId; }
    if ($has('cash_flow_category'))            { $fields[] = 'cash_flow_category'; $values[] = 'cash'; }
    if ($has('is_current'))                    { $fields[] = 'is_current';         $values[] = 1; }
    if ($has('is_system'))                     { $fields[] = 'is_system';          $values[] = 0; }
    if ($has('opening_balance'))               { $fields[] = 'opening_balance';    $values[] = 0; }
    if ($has('current_balance'))               { $fields[] = 'current_balance';    $values[] = 0; }
    if ($has('created_at'))                    { $fields[] = 'created_at';         $values[] = date('Y-m-d H:i:s'); }
    if ($has('updated_at'))                    { $fields[] = 'updated_at';         $values[] = date('Y-m-d H:i:s'); }

    $place = implode(', ', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO accounts (" . implode(', ', $fields) . ") VALUES ($place)";
    $pdo->prepare($sql)->execute($values);

    echo "  + Restored Cheque Account (1-1110) under parent #$parentId, level " . ($parentLevel + 1) . ".\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
