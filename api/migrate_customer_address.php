<?php
// One-time migration: add council, ward, postal_address columns to customers table
require_once __DIR__ . '/../roots.php';
header('Content-Type: text/plain');

function addColumnIfMissing($pdo, $table, $column, $definition) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $check->execute([$table, $column]);
    if ($check->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "ADDED: $column\n";
    } else {
        echo "EXISTS: $column\n";
    }
}

addColumnIfMissing($pdo, 'customers', 'council',        'VARCHAR(255) NULL AFTER `city`');
addColumnIfMissing($pdo, 'customers', 'ward',           'VARCHAR(255) NULL AFTER `council`');
addColumnIfMissing($pdo, 'customers', 'postal_address', 'VARCHAR(255) NULL AFTER `postal_code`');

echo "\nDone.\n";
