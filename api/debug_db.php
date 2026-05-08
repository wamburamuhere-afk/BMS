<?php
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

echo "Checking Database...\n";

// Check if attendance table exists
$stmt = $pdo->query("SHOW TABLES LIKE 'attendance'");
$tableExists = $stmt->fetch();

if ($tableExists) {
    echo "Table 'attendance' EXISTS.\n\n";
    echo "Columns:\n";
    $stmt = $pdo->query("DESCRIBE attendance");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "\n";
    }
} else {
    echo "Table 'attendance' DOES NOT EXIST.\n";
    echo "Creating 'attendance' table...\n";
    
    // Create the table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS attendance (
            attendance_id INT PRIMARY KEY AUTO_INCREMENT,
            employee_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            check_in_time TIME,
            check_out_time TIME,
            total_hours DECIMAL(10,2),
            status VARCHAR(50) DEFAULT 'absent',
            notes TEXT,
            created_by INT,
            created_at DATETIME,
            updated_by INT,
            updated_at DATETIME,
            UNIQUE KEY unique_attendance (employee_id, attendance_date)
        )";
        $pdo->exec($sql);
        echo "Table created successfully.\n";
    } catch (PDOException $e) {
        echo "Error creating table: " . $e->getMessage() . "\n";
    }
}
?>
