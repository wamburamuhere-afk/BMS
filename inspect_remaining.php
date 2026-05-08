<?php
require_once __DIR__ . '/roots.php';
function describeTable($pdo, $tableName) {
    echo "--- Table: $tableName ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $tableName");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "Field: {$row['Field']}, Type: {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
describeTable($pdo, 'account_types');
describeTable($pdo, 'audit_log');
describeTable($pdo, 'activity_log');
describeTable($pdo, 'audit_logs');
describeTable($pdo, 'activity_logs');
