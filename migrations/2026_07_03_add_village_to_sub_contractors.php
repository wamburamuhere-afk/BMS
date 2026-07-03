<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: sub_contractors.village...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM sub_contractors LIKE 'village'")->fetch();
    if ($exists) {
        echo "  · column village already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE sub_contractors ADD COLUMN village VARCHAR(150) NULL AFTER ward");
        echo "  + added column sub_contractors.village.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
