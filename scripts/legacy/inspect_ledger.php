<?php
require_once 'roots.php';
$tables = ['account_types', 'accounts', 'journal_entries', 'journal_entry_items'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
