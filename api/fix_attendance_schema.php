<?php
// scope-audit: skip — schema migration script; not a runtime data endpoint
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

echo "Fixing Attendance Table Schema...\n";

try {
    // Add updated_by column if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'updated_by'");
    if (!$stmt->fetch()) {
        echo "Adding 'updated_by' column...\n";
        $pdo->exec("ALTER TABLE attendance ADD COLUMN updated_by INT AFTER created_by");
    } else {
        echo "'updated_by' column already exists.\n";
    }

    // Modify status column to VARCHAR to allow flexible statuses (or ensure ENUM has all values)
    // It's safer to switch to VARCHAR for now to avoid specific ENUM errors
    echo "Modifying 'status' column to VARCHAR(50)...\n";
    $pdo->exec("ALTER TABLE attendance MODIFY COLUMN status VARCHAR(50) DEFAULT 'absent'");

    // Modify total_hours to ensure it can hold values (it was decimal(5,2))
    echo "Modifying 'total_hours' column...\n";
    $pdo->exec("ALTER TABLE attendance MODIFY COLUMN total_hours DECIMAL(10,2) DEFAULT 0");

    echo "Schema fixed successfully.\n";

} catch (PDOException $e) {
    echo "Error fixing schema: " . $e->getMessage() . "\n";
}
?>
