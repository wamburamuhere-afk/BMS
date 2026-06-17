<?php
require_once __DIR__ . '/../roots.php';
header('Content-Type: text/plain');

try {
    echo "Starting payroll table maintenance...\n";
    
    // Add payroll_number if missing
    try {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN payroll_number VARCHAR(50) AFTER payroll_id");
        echo "✅ Created column: payroll_number\n";
    } catch(Exception $e) {
        echo "ℹ️ Column payroll_number likely exists.\n";
    }

    // Add gross_salary if missing
    try {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount");
        echo "✅ Created column: gross_salary\n";
    } catch(Exception $e) {
        echo "ℹ️ Column gross_salary likely exists (" . $e->getMessage() . ")\n";
    }

    // Add updated_at if missing
    try {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        echo "✅ Created column: updated_at\n";
    } catch(Exception $e) {
        echo "ℹ️ Column updated_at likely exists.\n";
    }

    // Add metadata/logic columns if missing
    $cols = [
        'month' => "INT(2) AFTER net_salary",
        'year' => "INT(4) AFTER month",
        'status' => "ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending' AFTER payment_status",
        'payment_method' => "VARCHAR(50) DEFAULT 'bank' AFTER status",
        'notes' => "TEXT AFTER payment_method",
        'created_by' => "INT AFTER notes"
    ];

    foreach ($cols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE payroll ADD COLUMN $col $def");
            echo "✅ Created column: $col\n";
        } catch(Exception $e) {}
    }

    // Create tax_brackets table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tax_brackets` (
        `bracket_id` int NOT NULL AUTO_INCREMENT,
        `min_income` decimal(15,2) NOT NULL,
        `max_income` decimal(15,2) DEFAULT NULL,
        `tax_rate` decimal(5,2) NOT NULL,
        `is_active` tinyint(1) DEFAULT '1',
        `effective_from` date DEFAULT NULL,
        `effective_to` date DEFAULT NULL,
        PRIMARY KEY (`bracket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table 'tax_brackets' ensured.\n";

    // Create payroll_settings table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_settings` (
        `setting_id` int NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text,
        `description` text,
        PRIMARY KEY (`setting_id`),
        UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table 'payroll_settings' ensured.\n";

    // Create employee_allowances table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_allowances` (
        `allowance_id` int NOT NULL AUTO_INCREMENT,
        `employee_id` int NOT NULL,
        `allowance_type` varchar(100) DEFAULT NULL,
        `amount` decimal(15,2) DEFAULT '0.00',
        `status` enum('active','inactive') DEFAULT 'active',
        PRIMARY KEY (`allowance_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table 'employee_allowances' ensured.\n";

    // Create employee_deductions table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_deductions` (
        `deduction_id` int NOT NULL AUTO_INCREMENT,
        `employee_id` int NOT NULL,
        `deduction_type` varchar(100) DEFAULT NULL,
        `amount` decimal(15,2) DEFAULT '0.00',
        `status` enum('active','inactive') DEFAULT 'active',
        PRIMARY KEY (`deduction_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Table 'employee_deductions' ensured.\n";

    // Fix ENUMs
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial') DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial') DEFAULT 'pending'");
    echo "✅ ENUM columns (payment_status, status) updated to support 'approved' and 'processing'.\n";

    // Fix Employees table missing columns
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN bank_name VARCHAR(100) AFTER status");
        echo "✅ Created column: bank_name in employees table\n";
    } catch(Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN bank_account_number VARCHAR(50) AFTER bank_name");
        echo "✅ Created column: bank_account_number in employees table\n";
    } catch(Exception $e) {}

    echo "Maintenance completed successfully.\n";
} catch (Exception $e) {
    echo "❌ Error during maintenance: " . $e->getMessage() . "\n";
}
?>
