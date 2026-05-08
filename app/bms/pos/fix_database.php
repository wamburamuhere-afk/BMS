<?php
// Script to create the missing payroll_settings table
// SAVE AS: app/bms/pos/fix_database.php

// Try to include necessary files
$possible_roots = ['../../../roots.php', '../../roots.php', '../roots.php', 'roots.php'];
foreach ($possible_roots as $path) { if (file_exists($path)) { require_once $path; break; } }

// DB Connection
$db_connected = false;
$possible_db = ['../../../includes/db_connect.php', '../../includes/db_connect.php', '../includes/db_connect.php', 'includes/db_connect.php'];
foreach ($possible_db as $path) { if (file_exists($path)) { require_once $path; $db_connected = true; break; } }

if (!$db_connected && !isset($pdo)) {
    // Manual Fallback
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=bms;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\PDOException $e) { die("DB Connection Failed: " . $e->getMessage()); }
}

header('Content-Type: text/plain');
echo "========================================\n";
echo "FIXING DATABASE...\n";
echo "========================================\n\n";

try {
    // 1. Create payroll_settings table
    echo "Creating 'payroll_settings' table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS `payroll_settings` (
        `setting_id` int(11) NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(50) NOT NULL,
        `setting_value` text,
        `description` text,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_id`),
        UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "✅ Table 'payroll_settings' created successfully.\n";
    
    // 2. Insert Default Settings
    echo "Inserting default settings...\n";
    $defaults = [
        ['currency_symbol', 'TZS', 'Currency symbol used in payroll'],
        ['working_days_per_month', '26', 'Standard working days in a month'],
        ['default_tax_rate', '0', 'Default tax rate percentage if no brackets apply'],
        ['nssf_rate', '10.00', 'NSSF employee contribution rate (%)'],
        ['nhif_rate', '6.00', 'NHIF employee contribution rate (%)'],
        ['wcf_rate', '1.00', 'WCF employer contribution rate (%)'],
        ['company_name', 'My Company', 'Company Name for Payslips'],
        ['company_address', 'P.O. Box 123, Dar es Salaam', 'Company Address for Payslips']
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO payroll_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
    
    foreach ($defaults as $setting) {
        $stmt->execute($setting);
    }
    echo "✅ Default settings inserted.\n\n";
    
    echo "========================================\n";
    echo "🎉 FIX COMPLETE! You can now use the system.\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
