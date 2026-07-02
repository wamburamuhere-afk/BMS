<?php
require_once __DIR__ . '/includes/config.php';
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
    if ($stmt->rowCount() == 0) {
        echo "Table 'audit_logs' DOES NOT exist.\n";
    } else {
        echo "Table 'audit_logs' exists.\n";
        $columns = $pdo->query("DESCRIBE audit_logs")->fetchAll(PDO::FETCH_ASSOC);
        print_r($columns);
        
        echo "\nRecent Logs:\n";
        $logs = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        print_r($logs);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
