<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: deliveries workflow snapshot columns...\n";

try {
    $columns = [
        'prepared_by_name' => "VARCHAR(150) NULL",
        'prepared_by_role' => "VARCHAR(100) NULL",
        'prepared_at'      => "DATETIME NULL",
        'reviewed_by_name' => "VARCHAR(150) NULL",
        'reviewed_by_role' => "VARCHAR(100) NULL",
        'reviewed_at'      => "DATETIME NULL",
        'approved_by_name' => "VARCHAR(150) NULL",
        'approved_by_role' => "VARCHAR(100) NULL",
        'approved_at'      => "DATETIME NULL",
    ];

    foreach ($columns as $col => $definition) {
        $exists = $pdo->query("SHOW COLUMNS FROM deliveries LIKE '$col'")->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE deliveries ADD COLUMN $col $definition");
            echo "  + Added column: deliveries.$col\n";
        } else {
            echo "  · Already exists: deliveries.$col\n";
        }
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
