<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employees.state/ward/village...\n";

try {
    $cols = [
        'state'   => "ALTER TABLE employees ADD COLUMN state VARCHAR(100) NULL AFTER city",
        'ward'    => "ALTER TABLE employees ADD COLUMN ward VARCHAR(100) NULL AFTER state",
        'village' => "ALTER TABLE employees ADD COLUMN village VARCHAR(150) NULL AFTER ward",
    ];

    foreach ($cols as $col => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM employees LIKE '$col'")->fetch();
        if ($exists) {
            echo "  · column $col already exists, skipping.\n";
        } else {
            $pdo->exec($sql);
            echo "  + added column employees.$col.\n";
        }
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
