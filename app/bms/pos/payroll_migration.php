<?php
require_once __DIR__ . '/../../../roots.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create employee_allowances table
    $sql_allowances = "CREATE TABLE IF NOT EXISTS employee_allowances (
        allowance_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        allowance_name VARCHAR(100) NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        type ENUM('fixed', 'percentage') DEFAULT 'fixed',
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql_allowances);
    echo "Table 'employee_allowances' created or already exists.<br>";
    
    // Create employee_deductions table
    $sql_deductions = "CREATE TABLE IF NOT EXISTS employee_deductions (
        deduction_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        deduction_name VARCHAR(100) NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        type ENUM('fixed', 'percentage') DEFAULT 'fixed',
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql_deductions);
    echo "Table 'employee_deductions' created or already exists.<br>";
    
    // Check attendance table just in case
    $stmt = $pdo->query("SHOW TABLES LIKE 'attendance'");
    if ($stmt->rowCount() == 0) {
        $sql_attendance = "CREATE TABLE attendance (
            attendance_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('present', 'absent', 'late', 'half_day', 'leave') NOT NULL,
            check_in TIME DEFAULT NULL,
            check_out TIME DEFAULT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sql_attendance);
        echo "Table 'attendance' created.<br>";
    } else {
        echo "Table 'attendance' already exists.<br>";
    }
    
    // Update payroll table with missing columns for industrial standards
    $pdo->exec("ALTER TABLE payroll ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL AFTER notes");
    $pdo->exec("ALTER TABLE payroll ADD COLUMN IF NOT EXISTS approved_by INT DEFAULT NULL AFTER created_by");
    $pdo->exec("ALTER TABLE payroll ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    
    echo "Table 'payroll' updated with audit columns.<br>";
    
    echo "<h3>Migration Completed Successfully!</h3>";
    
} catch (PDOException $e) {
    echo "<h3>Migration Failed:</h3> " . $e->getMessage();
}
?>
