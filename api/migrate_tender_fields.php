<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $columns = [
        'duration' => "VARCHAR(100) DEFAULT NULL AFTER tender_document",
        'discipline' => "VARCHAR(255) DEFAULT NULL AFTER duration",
        'tender_role' => "VARCHAR(255) DEFAULT NULL AFTER discipline"
    ];

    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE tenders ADD COLUMN $col $definition");
            echo "Added column: $col\n";
        } else {
            echo "Column $col already exists.\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
