<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/wamp64/www/bms/includes/config.php';

echo "=== Checking Income Statement Data ===\n\n";

$start_date = date('Y-m-01'); // Mwezi huu
$end_date = date('Y-m-t');

echo "Checking Period: $start_date to $end_date\n\n";

try {
    // 1. Check Account Types
    echo "1. Active Income/Expense Accounts:\n";
    $stmt = $pdo->query("SELECT account_id, account_name, account_code, account_type FROM accounts WHERE account_type IN ('income', 'expense', 'cost_of_sales') AND status = 'active'");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "   [WARNING] No active accounts found with type 'income', 'expense', or 'cost_of_sales'.\n";
    } else {
        foreach ($accounts as $acc) {
            echo "   - [{$acc['account_code']}] {$acc['account_name']} ({$acc['account_type']})\n";
        }
    }
    echo "\n";

    // 2. Check Journal Entries in Period
    echo "2. Journal Entries in Period ($start_date to $end_date):\n";
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM journal_entries WHERE entry_date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $count = $stmt->fetchColumn();
    echo "   Total Entries Found: $count\n\n";

    if ($count > 0) {
        $stmt = $pdo->prepare("SELECT entry_id, entry_date, status, description FROM journal_entries WHERE entry_date BETWEEN ? AND ? LIMIT 5");
        $stmt->execute([$start_date, $end_date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($entries as $entry) {
            echo "   - [ID: {$entry['entry_id']}] {$entry['entry_date']} - {$entry['description']} (Status: {$entry['status']})\n";
        }
    }
    echo "\n";

    // 3. Check Posted Items for Income/Expenses
    echo "3. Checking Posted Items for Income/Expense Accounts:\n";
    $sql = "
        SELECT 
            ca.account_name, 
            ca.account_type,
            je.status,
            jei.type,
            SUM(jei.amount) as total_amount
        FROM journal_entry_items jei
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        JOIN accounts ca ON jei.account_id = ca.account_id
        WHERE je.entry_date BETWEEN ? AND ?
        AND ca.account_type IN ('income', 'expense', 'cost_of_sales')
        GROUP BY ca.account_name, ca.account_type, je.status, jei.type
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        echo "   [WARNING] No items found for Income/Expense accounts in this period.\n";
    } else {
        foreach ($results as $row) {
            echo "   - {$row['account_name']} ({$row['account_type']}): {$row['type']} {$row['total_amount']} (Status: {$row['status']})\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
