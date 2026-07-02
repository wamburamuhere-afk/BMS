<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/wamp64/www/bms/includes/config.php';

echo "=== Checking ALL Accounts & Entries ===\n\n";

try {
    // 1. Account Types Distribution
    echo "1. Account Types Summary:\n";
    $stmt = $pdo->query("SELECT account_type, COUNT(*) as count FROM accounts GROUP BY account_type");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($types)) {
        echo "   [!] No accounts found at all.\n";
    } else {
        foreach ($types as $t) {
            echo "   - {$t['account_type']}: {$t['count']} accounts\n";
        }
    }
    echo "\n";

    // 2. List Some Accounts
    echo "2. Sample Accounts (Top 10):\n";
    $stmt = $pdo->query("SELECT account_id, account_name, account_code, account_type, status FROM accounts LIMIT 10");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as $acc) {
        echo "   - [{$acc['account_code']}] {$acc['account_name']} ({$acc['account_type']}, {$acc['status']})\n";
    }
    echo "\n";

    // 3. Entries Date Range
    echo "3. Journal Entries Date Range:\n";
    $stmt = $pdo->query("SELECT MIN(entry_date) as first_date, MAX(entry_date) as last_date, COUNT(*) as total FROM journal_entries");
    $range = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   First Entry: {$range['first_date']}\n";
    echo "   Last Entry: {$range['last_date']}\n";
    echo "   Total Entries: {$range['total']}\n\n";
    
    // 4. Check for 'Revenue' or 'Sales' in account names regardless of type
    echo "4. Searching for 'Sales' or 'Revenue' accounts:\n";
    $stmt = $pdo->query("SELECT * FROM accounts WHERE account_name LIKE '%Sales%' OR account_name LIKE '%Revenue%'");
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sales as $s) {
        echo "   - [{$s['account_code']}] {$s['account_name']} (Type: {$s['account_type']})\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
